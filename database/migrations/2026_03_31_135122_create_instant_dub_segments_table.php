<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instant_dub_segments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instant_dub_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('segment_index');
            $table->string('speaker', 16)->default('M1');
            $table->float('start_time');
            $table->float('end_time');
            $table->float('slot_end')->nullable();
            $table->text('source_text')->nullable();
            $table->text('translated_text');
            $table->string('aac_path', 512)->nullable();
            $table->float('aac_duration')->nullable();
            $table->boolean('needs_retts')->default(false);
            $table->timestamps();

            $table->index(['instant_dub_id', 'segment_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instant_dub_segments');
    }
};
