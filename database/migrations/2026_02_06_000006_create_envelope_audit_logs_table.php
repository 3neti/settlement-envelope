<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('envelope_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('envelope_id')->constrained('envelopes')->cascadeOnDelete();
            $table->string('action', 64); // payload_patch, attachment_upload, attachment_review, signal_set, status_change, etc.
            $table->foreignId('actor_id')->nullable();
            $table->string('actor_type')->nullable();
            $table->string('actor_role', 64)->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->json('metadata')->nullable(); // ip, device, etc.
            $table->timestamp('created_at');

            $table->index(['envelope_id', 'action']);
            $table->index(['envelope_id', 'created_at']);
            $table->index('actor_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('envelope_audit_logs');
    }
};
