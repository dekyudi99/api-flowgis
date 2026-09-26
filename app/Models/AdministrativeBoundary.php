<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdministrativeBoundary extends Model
{
    protected $table = 'administrative_boundaries';

    // Matikan timestamps karena tabel tidak punya created_at & updated_at
    public $timestamps = false;
    protected $fillable = [
        'region_name',
        'level',
        'geom',
    ];
}
