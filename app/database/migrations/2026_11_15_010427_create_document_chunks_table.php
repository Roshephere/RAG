<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('document_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignid('document_id')->constrained()->onDelete('cascade');
            $table->unsignedInteger('chunk_index');

            $table->longText('content');

            $table->vector('embedding', 1536);

            $table->string('embedding_model', 100);

            $table->timestamps();

            $table->unique(['document_id', 'chunk_index']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_chunks');
    }
};
