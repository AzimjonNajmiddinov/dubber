<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('speakers', function (Blueprint $table) {
            $table->dropColumn(['elevenlabs_voice_id', 'xtts_voice_id']);
        });
    }

    public function down(): void
    {
        Schema::table('speakers', function (Blueprint $table) {
            $table->string('elevenlabs_voice_id')->nullable()->after('tts_driver');
            $table->string('xtts_voice_id')->nullable()->after('elevenlabs_voice_id');
        });
    }
};
