<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('instant_dubs', function (Blueprint $table) {
            $table->string('video_content_key', 64)->nullable()->after('video_url');
            $table->index(['video_content_key', 'language'], 'idx_content_key_lang');
        });
    }

    public function down(): void
    {
        Schema::table('instant_dubs', function (Blueprint $table) {
            $table->dropIndex('idx_content_key_lang');
            $table->dropColumn('video_content_key');
        });
    }
};
