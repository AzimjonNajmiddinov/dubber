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
        Schema::table('video_segments', function (Blueprint $table) {
            // Intent field - what the speaker is trying to achieve
            $table->string('intent', 30)->default('inform')->after('direction');

            // Acting note - human-readable direction for voice actor
            $table->string('acting_note', 100)->nullable()->after('intent');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('video_segments', function (Blueprint $table) {
            $table->dropColumn(['intent', 'acting_note']);
        });
    }
};
