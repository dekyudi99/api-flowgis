<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Analysis extends Model
{
    protected $fillable = [
        'project_id',
        'aoi_id',
        'type',
        'analysis_type',
        'parameters',
        'status',
        'statistics',
        'file_path',
    ];

    protected $casts = [
        'statistics' => 'array', // Otomatis konversi JSON DB ke Array PHP
        'parameters' => 'array',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }
}