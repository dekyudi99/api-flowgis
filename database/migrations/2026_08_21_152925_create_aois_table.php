<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aois', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            
            // Kategori sumber: 'drawn_circle', 'drawn_polygon', 'upload_geojson', 'upload_kml', 'upload_shp'
            $table->string('source_type'); 
            
            // Kolom geometri fleksibel PostGIS (SRID 4326) untuk Polygon/MultiPolygon
            $table->geometry('geometry', subtype: 'geometry', srid: 4326);
            
            // Menyimpan atribut file upload (tabel atribut SHP/KML) atau radius lingkaran
            $table->jsonb('metadata')->nullable();
            
            // Hasil kalkulasi spasial & path file cadangan
            $table->decimal('area_ha', 12, 4)->nullable();
            $table->string('file_path')->nullable(); // Jalur file ZIP/KML/GeoJSON jika diunggah
            
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aois');
    }
};