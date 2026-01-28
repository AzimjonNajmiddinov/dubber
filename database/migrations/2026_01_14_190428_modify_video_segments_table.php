<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('video_segments', function (Blueprint $table) {
            $table->string('emotion')->nullable()->after('gender'); // optional override
            $table->float('tts_gain_db')->nullable()->after('tts_audio_path'); // per-seg gain
            $table->float('tts_lufs')->nullable()->after('tts_gain_db');       // measured after normalization (optional)
        });
    }

    public function down(): void
    {
        Schema::table('video_segments', function (Blueprint $table) {
            $table->dropColumn(['emotion', 'tts_gain_db', 'tts_lufs']);
        });
    }
};
