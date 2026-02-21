<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('speakers', function (Blueprint $table) {
            $table->string('voice_profile', 20)->nullable()->after('voice_cloned');
            $table->float('speaking_rate_factor')->nullable()->after('voice_profile');
            $table->float('expressiveness')->nullable()->after('speaking_rate_factor');
            $table->float('openvoice_tau')->nullable()->after('expressiveness');
            $table->float('voice_sample_duration')->nullable()->after('openvoice_tau');
        });
    }

    public function down(): void
    {
        Schema::table('speakers', function (Blueprint $table) {
            $table->dropColumn([
                'voice_profile',
                'speaking_rate_factor',
                'expressiveness',
                'openvoice_tau',
                'voice_sample_duration',
            ]);
        });
    }
};
