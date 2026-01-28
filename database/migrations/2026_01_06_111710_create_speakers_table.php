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
        Schema::create('speakers', function ($table) {
            $table->id();
            $table->foreignId('video_id')->constrained()->cascadeOnDelete();
            $table->string('external_key'); // SPEAKER_00
            $table->string('gender');       // male / female / unknown
            $table->string('tts_voice');    // alloy / nova
            $table->string('label')->nullable()->index();
            $table->unique(['video_id', 'label']);
            $table->timestamps();
            $table->unique(['video_id', 'external_key']);
        });


    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('speakers');
    }
};
