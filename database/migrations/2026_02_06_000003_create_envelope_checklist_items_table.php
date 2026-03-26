<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('envelope_checklist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('envelope_id')->constrained('envelopes')->cascadeOnDelete();
            $table->string('key', 64); // e.g., 'borrower_id_front'
            $table->string('label'); // human-readable label
            $table->string('kind', 32); // document, payload_field, attestation, signal
            $table->string('doc_type', 64)->nullable(); // for document kind
            $table->string('payload_pointer')->nullable(); // for payload_field kind, e.g., /loan/tcp
            $table->string('attestation_type', 64)->nullable(); // for attestation kind
            $table->string('signal_key', 64)->nullable(); // for signal kind
            $table->boolean('required')->default(true);
            $table->string('review_mode', 32)->default('none'); // none, optional, required
            $table->string('status', 32)->default('missing'); // missing, uploaded, needs_review, accepted, rejected
            $table->timestamps();

            $table->unique(['envelope_id', 'key']);
            $table->index(['envelope_id', 'kind']);
            $table->index(['envelope_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('envelope_checklist_items');
    }
};
