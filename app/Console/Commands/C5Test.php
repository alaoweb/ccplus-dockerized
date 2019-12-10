<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Client;
use DB;
use App\Report;
use App\Consortium;
use App\Provider;
use App\Institution;
use App\Counter5Processor;
use \ubfr\c5tools\RawReport;
use \ubfr\c5tools\JsonR5Report;
use \ubfr\c5tools\ParseException;

class C5TestCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sushi:C5test {infile : The input file}
                             {--M|month= : YYYY-MM to process  [lastmonth]}
                             {--P|provider= : Provider ID to process [ALL]}
                             {--I|institution= : Institution ID to process[ALL]}
                             {--R|report= : Report Name to request [ALL]}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test Counter processing for a given input file';

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
       // Required arguments
        $infile = $this->argument('infile');
        $consortium = Consortium::findOrFail(1);

       // Aim the consodb connection at specified consortium's database
        config(['database.connections.consodb.database' => 'ccplus_' . $consortium->ccp_key]);
        DB::reconnect();
        $report_path = config('ccplus.reports_path') . $consortium->ccp_key;

       // Handle input options
        $month  = is_null($this->option('month')) ? 'lastmonth' : $this->option('month');
        $prov_id = is_null($this->option('provider')) ? 0 : $this->option('provider');
        $inst_id = is_null($this->option('institution')) ? 0 : $this->option('institution');
        $rept = $this->option('report');

       // Setup month string for pulling the report and begin/end for parsing
       //
        if (strtolower($month) == 'lastmonth') {
            $begin = date("Y-m", mktime(0, 0, 0, date("m") - 1, date("d"), date("Y")));
        } else {
            $begin = date("Y-m", strtotime($month));
        }
        $yearmon = $begin;
        $end = $begin;
        $begin .= '-01';
        $end .= '-' . date('t', strtotime($end . '-01'));

       // Get detail on report
        $reports = Report::where('name', '=', $rept)->get();
        if ($reports->isEmpty()) {
            $this->error("No matching reports found");
            exit;
        }

       // Get Provider data as a collection regardless of whether we just need one
        $providers = Provider::where('is_active', '=', true)->where('id', '=', $prov_id)->get();

       // Get Institution data
        $institutions = Institution::where('is_active', '=', true)->where('id', '=', $inst_id)
                                       ->pluck('name', 'id');

       // Loop through providers
        $logmessage = false;
        $client = new Client();   //GuzzleHttp\Client
        foreach ($providers as $provider) {
           // Skip this provider if there are no reports defined for it
            if (count($provider->reports) == 0) {
                $this->line($provider->name . " has no reports defined; skipping...");
                continue;
            }

           // Skip this provider if there are no sushi settings for it
            if (count($provider->sushisettings) == 0) {
                $this->line($provider->name . " has no sushi settings defined; skipping...");
                continue;
            }

           // Begin setting up the URI for the request
            if ($logmessage) {
                $this->line("Sushi Requests Begin for Consortium: " . $consortium->ccp_key);
            }
            $base_uri = preg_replace('/\/?$/', '/', $provider->server_url_r5); // ensure slash-ending
            $uri_args = "/?begin_date=" . $begin . "&end_date=" . $end;

           // Loop through all sushisettings for this provider
            foreach ($provider->sushisettings as $setting) {
               // Skip this setting if we're just processing a single inst and the IDs don't match
                if (($inst_id != 0) && ($setting->inst_id != $inst_id)) {
                    continue;
                }

               // Construct and execute the Request
                $uri_args .= "&customer_id=" . $setting->customer_id;
                $uri_args .= "&requestor_id=" . $setting->requestor_id;

               // Create the processor object
                $C5processor = new Counter5Processor($provider->id, $setting->inst_id, $begin, $end, "");

               // Loop through all reports for this provider
                foreach ($provider->reports as $report) {
                    if ($report->name != $rept) {
                        continue;
                    }
                    $this->line("Requesting " . $report->name . " for " . $provider->name);

                   // Set output filename for raw data
                    if (!is_null(config('ccplus.reports_path'))) {
                        $raw_datafile = $report_path . '/' . $setting->institution->name . '/' . $provider->name .
                                        '/' . $report->name . '_' . $begin . '_' . $end . '.json';
                    }

                   // Setup attributes for the request
                    if ($report->name == "TR") {
                        $uri_atts  = "&attributes_to_show=Data_Type%7CAccess_Method%7CAccess_Type%7C";
                        $uri_atts .= "Section_Type%7CYOP";
                    } elseif ($report->name == "DR") {
                        $uri_atts = "";
                    } elseif ($report->name == "PR") {
                        $uri_atts = "&attributes_to_show=Data_Type%7CAccess_Method";
                    } elseif ($report->name == "IR") {
                        $uri_atts = "";
                    } else {
                        $this->error("Unknown report: " . $report->name . " defined for: " .
                                $provider->name);
                        continue;
                    }

                   // Construct URI for the request
                    $request_uri = $base_uri . $report->name . $uri_args . $uri_atts;
                    $this->line("Requested URI would be: " . $request_uri);

                    $json_text = file_get_contents($infile);
                    if ($json_text === false) {
                        $this->line("System Error - reading file {$infile} failed");
                        exit;
                    }
                    $json = json_decode($json_text);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        $this->line("Error decoding JSON - " . json_last_error_msg());
                    }

                   // Validate report
                   $validJson = self::validateJson($json);

                   // Parse and store the report if it's valid
                   $result = $C5processor->{$report->name}($validJson);
                    // if ($C5validator->{$report->name}()) {
                    //     $result = $C5processor->{$report->name}($C5validator->report);
                    // }

                }  // foreach reports
            }  // foreach sushisettings
        }  // foreach providers
        $this->line("Test completed: " . date("Y-m-d H:i:s"));
    }

    protected static function validateJson($json)
    {

        try {
            $release = RawReport::getReleaseFromJson($json);
        } catch (\Exception $e) {
            throw new ParseException("Could not determine COUNTER Release - " . $e->getMessage());
        }
        if ($release !== '5') {
            throw new ParseException("COUNTER Release '{$release}' invalid/unsupported");
        }

        $report = new JsonR5Report($json);
        unset($json);

        return $report;
    }
}