<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('envelope_contribution_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('envelope_id')->constrained('envelopes')->cascadeOnDelete();
            $table->uuid('token')->unique();
            $table->string('label')->nullable(); // "Vendor ABC", "Supplier Invoice"

            // Recipient identification (all optional)
            $table->string('recipient_name')->nullable();
            $table->string('recipient_email')->nullable();
            $table->string('recipient_mobile', 50)->nullable();
            $table->json('metadata')->nullable(); // Additional custom fields

            // Security
            $table->string('password')->nullable(); // Hashed, verified with Hash::check()

            // Tracking
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->unsignedInteger('use_count')->default(0);

            $table->timestamps();

            // Indexes for common queries
            $table->index('expires_at');
            $table->index(['envelope_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('envelope_contribution_tokens');
    }
};
