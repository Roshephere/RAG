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
        Schema::create('documents', function (Blueprint $table) {
            $table->id();

            // Each document belong sto a knowledge base

            $table->foreignId('knowledge_base_id')->constrained()->cascadeOnDelete();

            // File metadata
            $table->string(('original_name'));
            $table->string('storage_disk')->default('local');
            $table->string('storage_path');
            $table->string('mime_type', 150);
            $table->unsignedBigInteger('size_bytes');

            // Document processing status

            $table->string('status', 20)->default('pending')->index();

            $table->text('error_message')->nullable();
            $table->timestamp('processed_at')->nullable();


            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
