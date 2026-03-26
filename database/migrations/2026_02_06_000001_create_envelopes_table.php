<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('envelopes', function (Blueprint $table) {
            $table->id();
            $table->string('reference_code', 64)->unique()->index();
            $table->nullableMorphs('reference'); // reference_type, reference_id for polymorphic binding
            $table->string('driver_id', 128);
            $table->string('driver_version', 32);
            $table->json('payload')->nullable();
            $table->unsignedInteger('payload_version')->default(0);
            $table->string('status', 32)->default('draft');
            $table->json('context')->nullable(); // host-provided context for rule evaluation
            $table->json('gates_cache')->nullable(); // cached gate computation results
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['driver_id', 'driver_version']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('envelopes');
    }
};
