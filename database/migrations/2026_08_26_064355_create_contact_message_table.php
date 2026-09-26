<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
// use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('contact_message', function (Blueprint $table) {
            $table->id();
            $table->string('sender_name', 100);
            $table->string('sender_email', 100);
            $table->text('message');
            
            $table->timestampTz('received_at')->useCurrent();
            
            $table->boolean('is_read')->nullable()->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contact_message');
    }
};
