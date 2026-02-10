<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('video_segments', function (Blueprint $table) {
            $table->string('direction', 20)->default('normal')->after('emotion');
        });
    }

    public function down(): void
    {
        Schema::table('video_segments', function (Blueprint $table) {
            $table->dropColumn('direction');
        });
    }
};
