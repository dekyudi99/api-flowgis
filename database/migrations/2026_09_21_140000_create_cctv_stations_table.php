<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('cctv_stations')) {
            Schema::create('cctv_stations', function (Blueprint $table) {
                $table->id();
                $table->string('station_code', 50)->unique();
                $table->string('name', 255);
                $table->text('location')->nullable();
                $table->text('stream_url');
                $table->text('snapshot_url')->nullable();
                $table->string('username')->default('live');
                $table->string('password')->default('Live2025!');
                $table->decimal('lat', 10, 6)->nullable();
                $table->decimal('lng', 10, 6)->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cctv_stations');
    }
};
