<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instant_dub_voice_map', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instant_dub_id')->constrained()->cascadeOnDelete();
            $table->string('speaker_tag', 16);
            $table->json('voice_config');
            $table->timestamps();

            $table->index(['instant_dub_id', 'speaker_tag']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instant_dub_voice_map');
    }
};
