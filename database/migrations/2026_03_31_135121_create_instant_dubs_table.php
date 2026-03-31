<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instant_dubs', function (Blueprint $table) {
            $table->id();
            $table->string('title', 500)->default('Untitled');
            $table->string('video_url', 2048);
            $table->string('language', 10);
            $table->string('translate_from', 10)->nullable();
            $table->string('tts_driver', 32)->default('edge');
            $table->enum('status', ['complete', 'needs_retts', 'processing', 'error'])->default('processing');
            $table->unsignedInteger('total_segments')->default(0);
            $table->string('aac_dir', 512)->nullable();
            $table->string('session_id', 64)->nullable();
            $table->timestamps();

            // Use prefix lengths to stay within MySQL 3072-byte index limit
            $table->index([DB::raw('video_url(191)'), 'language']);
            $table->index('status');
            $table->index(DB::raw('title(191)'));
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instant_dubs');
    }
};
