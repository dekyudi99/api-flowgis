<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GeosocialLayer extends Model
{
    use HasFactory;

    protected $table = 'geosocial_layers';

    protected $fillable = [
        'layer_key',
        'name',
        'type',
        'url',
        'legend_url',
        'is_active',
    ];
}