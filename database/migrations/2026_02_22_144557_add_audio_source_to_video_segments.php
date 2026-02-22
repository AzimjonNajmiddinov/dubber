<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('video_segments', function (Blueprint $table) {
            $table->string('audio_source', 20)->default('direct')->after('direction');
        });
    }

    public function down(): void
    {
        Schema::table('video_segments', function (Blueprint $table) {
            $table->dropColumn('audio_source');
        });
    }
};
