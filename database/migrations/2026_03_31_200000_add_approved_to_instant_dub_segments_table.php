<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instant_dub_segments', function (Blueprint $table) {
            $table->boolean('approved')->default(false)->after('needs_retts');
        });
    }

    public function down(): void
    {
        Schema::table('instant_dub_segments', function (Blueprint $table) {
            $table->dropColumn('approved');
        });
    }
};
