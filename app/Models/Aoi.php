<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Aoi extends Model
{
    protected $fillable = [
        'project_id',
        'name',
        'source_type',
        'geometry',
        'metadata',
        'area_ha',
        'file_path',
    ];

    protected $casts = [
        'metadata' => 'array',
        'area_ha' => 'float',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function analyses(): HasMany
    {
        return $this->hasMany(Analysis::class);
    }
}