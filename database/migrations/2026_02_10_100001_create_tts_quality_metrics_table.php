<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tts_quality_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('video_segment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('speaker_id')->constrained()->cascadeOnDelete();
            $table->float('duration_ratio')->nullable();
            $table->float('rms_db')->nullable();
            $table->float('pitch_hz')->nullable();
            $table->float('tempo_applied')->nullable();
            $table->boolean('was_trimmed')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tts_quality_metrics');
    }
};
