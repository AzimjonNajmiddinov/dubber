<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('speakers', function (Blueprint $table) {
            $table->string('tts_driver')->nullable()->after('emotion_confidence');
            $table->string('elevenlabs_voice_id')->nullable()->after('tts_driver');
            $table->string('xtts_voice_id')->nullable()->after('elevenlabs_voice_id');
            $table->string('voice_sample_path')->nullable()->after('xtts_voice_id');
            $table->boolean('voice_cloned')->default(false)->after('voice_sample_path');
        });
    }

    public function down(): void
    {
        Schema::table('speakers', function (Blueprint $table) {
            $table->dropColumn([
                'tts_driver',
                'elevenlabs_voice_id',
                'xtts_voice_id',
                'voice_sample_path',
                'voice_cloned',
            ]);
        });
    }
};
