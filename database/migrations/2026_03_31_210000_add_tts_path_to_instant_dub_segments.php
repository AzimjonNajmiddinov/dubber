<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instant_dub_segments', function (Blueprint $table) {
            $table->string('tts_path', 512)->nullable()->after('aac_path');
            $table->double('tts_duration')->nullable()->after('aac_duration');
        });
    }

    public function down(): void
    {
        Schema::table('instant_dub_segments', function (Blueprint $table) {
            $table->dropColumn(['tts_path', 'tts_duration']);
        });
    }
};
