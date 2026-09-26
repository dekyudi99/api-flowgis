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
        Schema::create('infrastructures', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->nullable();
            $table->string('type', 50);
            $table->text('address')->nullable();
            $table->string('phone_number', 20)->nullable();
            $table->integer('capacity')->nullable();
            
            $table->geometry('geom', 'point', 4326);
        });

        DB::statement('CREATE INDEX idx_infrastructures_geom ON infrastructures USING GIST (geom);');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('infrastructures');
    }
};
