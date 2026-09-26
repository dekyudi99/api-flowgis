<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS postgis'); 

        Schema::create('administrative_boundaries', function (Blueprint $table) {
            $table->id();
            $table->string('region_name', 100);
            $table->string('level', 50)->nullable();

            $table->geometry('geom', 'multipolygon', 4326);
        });
        
        DB::statement('CREATE INDEX idx_administrative_boundaries_geom ON administrative_boundaries USING GIST (geom);');

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('administrative_boundaries');
    }
};
