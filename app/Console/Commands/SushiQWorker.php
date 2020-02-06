<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use DB;
use App\Consortium;
use App\Report;
use App\Sushi;
use App\SushiSetting;
use App\SushiQueueJob;
use App\Counter5Processor;
use App\FailedIngest;
use App\IngestLog;
use App\CcplusError;
use \ubfr\c5tools\JsonR5Report;
use \ubfr\c5tools\CheckResult;
use \ubfr\c5tools\ParseException;

/*
 * NOTE:
 *   As of 1/10/2020, it looks like the HOST system needs to set the PHP memory_limit
 *   to AT LEAST 1024Mb. Encoding/Decoding the JSON for processing can be a real PIG,
 *   and is dependent on the size of the report coming back from the providers
 *   (60-100K characters is not uncommon for TR). This script will fail and die with a
 *   shutdown exception (that cannot be caught) if it cannot allocate enough memory.
 */
 // CC Plus Queue Worker Script
 //
class SushiQWorker extends Command
{
    /**
     * The name and signature for the single-report Sushi processing console command.
     * @var string
     */
    protected $signature = 'ccplus:sushiqw {consortium : Consortium ID or key-string}
                                           {ident=null : Optional runtime name for logging output []}
                                           {startup-delay=0 : Optional delay for staggering multiple startups}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process the CC-Plus Sushi Queue for a Consortium';
    private $all_consortia;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
       // Get optional inputs
        $_ident = $this->argument('ident');
        $ident = ($_ident == "null") ? "" : $_ident . " : ";
        $delay = $this->argument('startup-delay');
        sleep($delay);

       // Allow input consortium to be an ID or Key
        $ts = date("Y-m-d H:i:s") . " ";
        $conarg = $this->argument('consortium');
        $consortium = Consortium::find($conarg);
        if (is_null($consortium)) {
            $consortium = Consortium::where('ccp_key', '=', $conarg)->first();
        }
        if (is_null($consortium)) {
            $this->line($ts . $ident . 'Cannot locate Consortium: ' . $conarg);
            exit;
        }
        if (!$consortium->is_active) {
            $this->line($ts . $ident . 'Consortium: ' . $conarg . " is NOT ACTIVE ... quitting.");
            exit;
        }

       // Aim the consodb connection at specified consortium's database and initialize the
       // path for keeping raw report responses
        config(['database.connections.consodb.database' => 'ccplus_' . $consortium->ccp_key]);
        DB::reconnect();
        if (!is_null(config('ccplus.reports_path'))) {
            $report_path = config('ccplus.reports_path') . $consortium->ccp_key;
        }

       // Setup strings for job queries
        $jobs_table = config('database.connections.globaldb.database') . ".jobs";
        $ingestlogs_table = config('database.connections.consodb.database') . ".ingestlogs";
        $runable_status = array('Queued','Pending','Retrying');

       // Get Job ID's for all "runable" queue entries for this consortium; exit if none found
        $job_ids = DB::table($jobs_table . ' as job')
                      ->join($ingestlogs_table . ' as ing', 'ing.id', '=', 'job.ingest_id')
                      ->where('consortium_id', '=', $consortium->id)
                      ->whereIn('ing.status', $runable_status)
                      ->pluck('job.id');
        if (sizeof($job_ids) == 0) {
            exit;
        }

       // Save all consortia records for detecting active jobs, strings for job queries
        $this->all_consortia = Consortium::where('is_active', true)->get();

       // Keep looping as long as there are jobs we can do
       // ($job_ids is updated @ bottom of loop)
        while (sizeof($job_ids) > 0) {
           // Get the current jobs
            $jobs = SushiQueueJob::whereIn('id', $job_ids)
                                 ->orderBy('priority', 'DESC')
                                 ->orderBy('id', 'ASC')
                                 ->get();
            if (empty($jobs)) {
                exit;
            }

           // Find the next available job
            $ten_ago = strtotime("-10 minutes");
            $job_found = false;
            foreach ($jobs as $job) {
               // Skip any "Retrying" ingest that's been updated today
                if (
                    $job->ingest->status == 'Retrying' &&
                    (substr($job->ingest->updated_at, 0, 10) == date("Y-m-d"))
                ) {
                    continue;
                }

               // Skip any "Pending" ingest that's been updated within the last 10 minutes
                if ($job->ingest->status == 'Pending' && (strtotime($job->ingest->updated_at) > $ten_ago)) {
                    continue;
                }

               // Check the job url against all active urls and skip if there's a match
                if ($this->hasActiveIngest($job->ingest->sushiSetting->provider->server_url_r5)) {
                    continue;
                }

               // Got one... move on
                $job_found = true;
                break;
            }

           // If we found a job, mark it active to keep any parallel processes from hitting this same
           // provider; otherwise, we exit quietly.
            if ($job_found) {
                $job->ingest->status = 'Active';
                $job->ingest->save();
            } else {
                exit;
            }

           // Setup begin and end dates for sushi request
            $ts = date("Y-m-d H:i:s");
            $yearmon = $job->ingest->yearmon;
            $begin = $yearmon . '-01';
            $end = $yearmon . '-' . date('t', strtotime($begin));

           // Get report
            $report = Report::find($job->ingest->report_id);
            if (is_null($report)) {     // report gone? toss entry
                $this->line($ts . " " . $ident . 'Unknown Report ID: ' . $job->ingest->report_id .
                            ' , queue entry skipped and deleted.');
                $job->delete();
                continue;
            }

           // Get sushi settings
            if (is_null($job->ingest->sushiSetting)) {     // settings gone? toss the job
                $this->line($ts . " " . $ident . 'Unknown Sushi Settings ID: ' . $job->ingest->sushisettings_id .
                            ' , queue entry skipped and deleted.');
                $job->delete();
                continue;
            }
            $setting = $job->ingest->sushiSetting;

           // If provider or institution is inactive, toss the job and move on
            if (!$setting->provider->is_active) {
                $this->line($ts . " " . $ident . 'Provider: ' . $setting->provider->name .
                            ' is INACTIVE , queue entry skipped and deleted.');
                $job->delete();
                continue;
            }
            if (!$setting->institution->is_active) {
                $this->line($ts . " " . $ident . 'Institution: ' . $setting->institution->name .
                            ' is INACTIVE , queue entry skipped and deleted.');
                $job->delete();
                continue;
            }

           // Create a new processor object; job record decides if data is getting replaced. If data is
           // being replaced, nothing is deleted until after the new report is received and validated.
            $C5processor = new Counter5Processor(
                $setting->prov_id,
                $setting->inst_id,
                $begin,
                $end,
                $job->replace_data
            );

           // Create a new Sushi object
            $sushi = new Sushi($begin, $end);

           // Set output filename for raw data. Create the folder path, if necessary
            if (!is_null(config('ccplus.reports_path'))) {
                $full_path = $report_path . '/' . $setting->institution->name . '/' . $setting->provider->name . '/';
                if (!is_dir($full_path)) {
                    mkdir($full_path, 0755, true);
                }
                $sushi->raw_datafile = $full_path . $report->name . '_' . $begin . '_' . $end . '.json';
            }

           // Construct URI for the request
            $request_uri = $sushi->buildUri($setting, $report);
// $this->line("Job: " . $job->id . " (ingest_id: " . $job->ingest->id . ")");
// $this->line("Provider: " . $setting->provider->name . " , Inst: " . $setting->institution->name);
// $this->line("Request : " . $request_uri);
// exit;

           // Make the request
            $request_status = $sushi->request($request_uri);

           // Examine the response
            $valid_report = false;
            if ($request_status == "Success") {
               // Print out any non-fatal message from sushi request
                if ($sushi->message != "") {
                    $this->line($ts . " " . $ident . "Non-Fatal SUSHI Exception: (" . $sushi->error_code . ") : " .
                                $sushi->message . $sushi->detail);
                }

                try {
                    $valid_report = $sushi->validateJson();
                } catch (\Exception $e) {
                    FailedIngest::insert(['ingest_id' => $job->ingest->id, 'process_step' => 'COUNTER',
                                          'error_id' => 100, 'detail' => 'Validation error: ' . $e->getMessage(),
                                          'created_at' => $ts]);
                    $this->line($ts . " " . $ident . "Report failed COUNTER validation : " . $e->getMessage());
                }

           // If request is pending (in a provider queue, not a CC+ queue), just set ingest status
           // the record updates when we fall out of the remaining if-else blocks
            } elseif ($request_status == "Pending") {
                $job->ingest->status = "Pending";

           // If request failed, update the Logs
            } else {    // Fail
               // Clean up the message in case we're adding the code to the errors table
                $error_msg = substr(preg_replace('/(.*)(https?:\/\/.*)$/', '$1', $sushi->message),0,60);
               // Get/Create entry from the sushi_errors table
                $error = CcplusError::firstOrCreate(
                         ['id' => $sushi->error_code],
                         ['id' => $sushi->error_code, 'message' => $error_msg, 'severity' => $sushi->severity]
                );
                FailedIngest::insert(['ingest_id' => $job->ingest->id, 'process_step' => $sushi->step,
                                      'error_id' => $error->id, 'detail' => $sushi->detail, 'created_at' => $ts]);
                $this->line($ts . " " . $ident . "SUSHI Exception (" . $sushi->error_code . ") : " .
                            $sushi->message . $sushi->detail);
            }

           // If we have a validated report, processs and save it
            if ($valid_report) {
                $_status = $C5processor->{$report->name}($sushi->json);
// -->> Is there ever a time this returns something other than success?
                if ($_status == 'Success') {
                    $this->line($ts . " " . $ident . $setting->provider->name . " : " . $yearmon . " : " .
                                $report->name . " saved for " . $setting->institution->name);
                }
                $job->ingest->status = $_status;

           // No valid report data saved. If we failed, update ingest record
           // ("Pending" is not considered failure.)
            } else {
                if ($request_status == "Fail") {    // Pending is not failure
                   // Increment ingest attempts
                    $job->ingest->attempts++;

                   // If we're out of retries, the ingest fails and we set an Alert
                    if ($job->ingest->attempts >= config('ccplus.max_ingest_retries')) {
                        $job->ingest->status = 'Fail';
                        Alert::insert(['yearmon' => $yearmon, 'prov_id' => $setting->prov_id,
                                       'ingest_id' => $job->ingest->id, 'status' => 'Active', 'created_at' => $ts]);
                    } else {
                        $job->ingest->status = 'Retrying';
                    }
                }
            }

           // Clean up and update the database;
           // unless the request is "Pending", remove the job from the queue.
            unset($sushi);
            unset($C5processor);
            $job->ingest->update();
            if ($request_status != "Pending") {
                $job->delete();
            }

           // Update ID's of "runable" queue entries
            $job_ids = DB::table($jobs_table . ' as job')
                         ->join($ingestlogs_table . ' as ing', 'ing.id', '=', 'job.ingest_id')
                         ->where('consortium_id', '=', $consortium->id)
                         ->whereIn('ing.status', $runable_status)
                         ->pluck('job.id');
        }   // While there are jobs in the queue
    }

    /**
     * Pull the URLs of "Active" ingests across all active consortia in the system.
     * Return T/F if the job's URL matches any of them.
     *
     * @param  string  $job_url
     * @return boolean result
     */
    private function hasActiveIngest($job_url)
    {
        foreach ($this->all_consortia as $_con) {
            $_db = 'ccplus_' . $_con->ccp_key;
            $_urls = DB::table($_db . '.ingestlogs as ing')
                         ->distinct()
                         ->join($_db . '.sushisettings as sus', 'sus.id', '=', 'ing.sushisettings_id')
                         ->join($_db . '.providers as prv', 'prv.id', '=', 'sus.prov_id')
                         ->where($_db . '.ing.status', 'Active')
                         ->select($_db . '.prv.server_url_r5')
                         ->get();
            foreach ($_urls as $_url) {
                if ($_url->server_url_r5 == $job_url) {
                    return true;
                }
            }
        }
        return false;
    }
}