<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Journal extends Model
{
   /**
    * The database table used by the model.
    *
    * @var string
    */
    protected $connection = 'globaldb';
    protected $table = 'journals';

    /**
     * Mass assignable attributes.
     *
     * @var array
     */
    protected $fillable = [
        'Title', 'ISSN', 'eISSN', 'DOI', 'PropID', 'URI'
    ];

    public function titleReports()
    {
        return $this->hasMany('App\TitleReport');
    }
}
