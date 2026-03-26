<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('envelope_signals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('envelope_id')->constrained('envelopes')->cascadeOnDelete();
            $table->string('key', 64); // e.g., 'kyc_passed', 'account_created'
            $table->string('type', 32)->default('boolean'); // boolean, string, enum
            $table->string('value')->nullable();
            $table->string('source', 32)->default('host'); // host, external
            $table->foreignId('actor_id')->nullable();
            $table->string('actor_type')->nullable();
            $table->timestamps();

            $table->unique(['envelope_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('envelope_signals');
    }
};
