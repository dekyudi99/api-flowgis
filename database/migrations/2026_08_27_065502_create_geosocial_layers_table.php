<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('geosocial_layers', function (Blueprint $table) {
            $table->id();
            $table->string('layer_key')->unique(); // Contoh: 'nakhon_pathom:administrative_line'
            $table->string('name');                // Nama tampilan: 'Administrative Line'
            $table->string('type')->default('wms'); // Tipe layer (wms, vector, dll)
            $table->string('url');                 // URL GeoServer: 'http://localhost:8080/geoserver/wms'
            $table->string('legend_url')->nullable(); // (Opsional) URL legenda
            $table->boolean('is_active')->default(true); // Status aktif/non-aktif layer
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('geosocial_layers');
    }
};