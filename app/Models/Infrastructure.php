<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Infrastructure extends Model
{
    protected $table = 'infrastructures';

    public $timestamps = false;

    protected $fillable = [
        'name',
        'type',
        'address',
        'phone_number',
        'capacity',
        'geom',
    ];
}
