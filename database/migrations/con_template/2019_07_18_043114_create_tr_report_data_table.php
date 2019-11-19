<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateTrReportDataTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('tr_report_data', function (Blueprint $table) {
            $global_db = DB::connection('globaldb')->getDatabaseName();

            $table->bigInteger('jrnl_id')->unsigned()->nullable();
            $table->bigInteger('book_id')->unsigned()->nullable();
            $table->unsignedInteger('prov_id');
            $table->unsignedInteger('publisher_id');
            $table->unsignedInteger('plat_id');
            $table->unsignedInteger('inst_id');
            $table->string('yearmon', 7);
            $table->unsignedInteger('datatype_id')->nullable();
            $table->unsignedInteger('sectiontype_id')->nullable();
            $table->string('YOP', 9)->nullable();
            $table->unsignedInteger('accesstype_id')->nullable();
            $table->unsignedInteger('accessmethod_id')->default(1);
            $table->unsignedInteger('total_item_investigations')->default(0);
            $table->unsignedInteger('total_item_requests')->default(0);
            $table->unsignedInteger('unique_item_investigations')->default(0);
            $table->unsignedInteger('unique_item_requests')->default(0);
            $table->unsignedInteger('unique_title_investigations')->default(0);
            $table->unsignedInteger('unique_title_requests')->default(0);
            $table->unsignedInteger('limit_exceeded')->default(0);
            $table->unsignedInteger('no_license')->default(0);
            $table->timestamps();

            $table->foreign('jrnl_id')->references('id')->on($global_db . '.journals');
            $table->foreign('book_id')->references('id')->on($global_db . '.books');
            $table->foreign('prov_id')->references('id')->on('providers');
            $table->foreign('publisher_id')->references('id')->on($global_db . '.publishers');
            $table->foreign('plat_id')->references('id')->on($global_db . '.platforms');
            $table->foreign('inst_id')->references('id')->on('institutions');
            $table->foreign('datatype_id')->references('id')->on($global_db . '.datatypes');
            $table->foreign('sectiontype_id')->references('id')->on($global_db . '.sectiontypes');
            $table->foreign('accesstype_id')->references('id')->on($global_db . '.accesstypes');
            $table->foreign('accessmethod_id')->references('id')->on($global_db . '.accessmethods');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('tr_report_data');
    }
}
