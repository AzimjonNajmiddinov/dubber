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
            // Note: video_url and title use prefix indexes (added via raw SQL below)
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

            $table->index('status');
        });

        // Prefix-length indexes needed because video_url/title are long strings
        DB::statement('CREATE INDEX instant_dubs_video_url_language_index ON instant_dubs (video_url(191), language)');
        DB::statement('CREATE INDEX instant_dubs_title_index ON instant_dubs (title(191))');
    }

    public function down(): void
    {
        Schema::dropIfExists('instant_dubs');
    }
};
