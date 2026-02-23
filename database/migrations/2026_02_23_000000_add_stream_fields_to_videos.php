<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->text('stream_url')->nullable()->after('source_url');
            $table->text('stream_audio_url')->nullable()->after('stream_url');
            $table->float('duration')->nullable()->after('stream_audio_url');
        });
    }

    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->dropColumn(['stream_url', 'stream_audio_url', 'duration']);
        });
    }
};
