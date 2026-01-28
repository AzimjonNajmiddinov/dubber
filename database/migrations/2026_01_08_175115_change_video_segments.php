<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('video_segments', function (Blueprint $table) {
            // If you have existing rows, you may need to ensure values fit the new precision.
            $table->decimal('start_time', 10, 3)->change();
            $table->decimal('end_time', 10, 3)->change();

            $table->index(['video_id', 'start_time']);
            $table->index(['video_id', 'speaker_id', 'start_time']);
        });
    }

    public function down(): void
    {
        Schema::table('video_segments', function (Blueprint $table) {
            $table->dropIndex(['video_id', 'start_time']);
            $table->dropIndex(['video_id', 'speaker_id', 'start_time']);

            $table->float('start_time')->change();
            $table->float('end_time')->change();
        });
    }
};
