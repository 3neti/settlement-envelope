<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('envelope_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('envelope_id')->constrained('envelopes')->cascadeOnDelete();
            $table->foreignId('checklist_item_id')->nullable()->constrained('envelope_checklist_items')->nullOnDelete();
            $table->string('doc_type', 64);
            $table->string('original_filename');
            $table->string('file_path');
            $table->string('disk', 32)->default('public');
            $table->string('mime_type', 128);
            $table->unsignedBigInteger('size');
            $table->string('hash', 64); // SHA-256
            $table->json('metadata')->nullable(); // additional doc metadata
            $table->foreignId('uploaded_by')->nullable();
            $table->string('review_status', 32)->default('pending'); // pending, accepted, rejected
            $table->foreignId('reviewer_id')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();

            $table->index(['envelope_id', 'doc_type']);
            $table->index(['envelope_id', 'review_status']);
            $table->index('hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('envelope_attachments');
    }
};
