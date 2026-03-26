<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('envelope_payload_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('envelope_id')->constrained('envelopes')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->json('payload');
            $table->json('patch')->nullable(); // the diff that was applied
            $table->string('payload_hash', 64)->nullable(); // SHA-256 of payload
            $table->foreignId('actor_id')->nullable();
            $table->string('actor_type')->nullable();
            $table->timestamp('created_at');

            $table->unique(['envelope_id', 'version']);
            $table->index('payload_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('envelope_payload_versions');
    }
};
