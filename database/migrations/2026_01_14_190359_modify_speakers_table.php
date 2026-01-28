<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('speakers', function (Blueprint $table) {
            $table->string('age_group')->nullable()->after('gender'); // child/young_adult/adult/senior/unknown
            $table->string('emotion')->nullable()->after('age_group'); // neutral/angry/sad/happy...

            $table->float('gender_confidence')->nullable()->after('emotion');
            $table->float('pitch_median_hz')->nullable()->after('gender_confidence');

            // Per-speaker TTS tuning knobs
            $table->float('tts_gain_db')->default(0)->after('tts_voice');   // e.g. +3.0 / -2.0
            $table->string('tts_rate')->default('+0%')->after('tts_gain_db');   // edge-tts rate
            $table->string('tts_pitch')->default('+0Hz')->after('tts_rate');    // edge-tts pitch
        });
    }

    public function down(): void
    {
        Schema::table('speakers', function (Blueprint $table) {
            $table->dropColumn([
                'age_group',
                'emotion',
                'gender_confidence',
                'pitch_median_hz',
                'tts_gain_db',
                'tts_rate',
                'tts_pitch',
            ]);
        });
    }
};
