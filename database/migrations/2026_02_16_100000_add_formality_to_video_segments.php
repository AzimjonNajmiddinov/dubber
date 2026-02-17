<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('video_segments', function (Blueprint $table) {
            $table->string('formality', 10)->nullable()->after('acting_note');
            $table->tinyInteger('translation_attempt')->default(0)->after('formality');
        });
    }

    public function down(): void
    {
        Schema::table('video_segments', function (Blueprint $table) {
            $table->dropColumn(['formality', 'translation_attempt']);
        });
    }
};
