<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('videos', 'source_url')) {
            Schema::table('videos', function (Blueprint $table) {
                $table->text('source_url')->nullable()->after('original_path');
            });
        }
    }

    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->dropColumn('source_url');
        });
    }
};
