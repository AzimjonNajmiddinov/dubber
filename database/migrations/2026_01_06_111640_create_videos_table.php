<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('videos', function ($table) {
            $table->id();
            $table->string('original_path');
            $table->string('audio_path')->nullable();
            $table->string('dubbed_path')->nullable();
            $table->string('status')->default('uploaded');
            $table->string('target_language')->nullable();
            $table->string('final_audio_path')->nullable();
            $table->timestamps();
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('videos');
    }
};
