<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('envelope_attachments', function (Blueprint $table): void {
            $table->string('document_id', 26)->nullable();
            $table->string('audit_reference', 100)->nullable();
            $table->unique(['envelope_id', 'document_id'], 'envelope_attachment_document_unique');
            $table->unique(['envelope_id', 'audit_reference'], 'envelope_attachment_audit_unique');
        });
    }

    public function down(): void
    {
        Schema::table('envelope_attachments', function (Blueprint $table): void {
            $table->dropUnique('envelope_attachment_document_unique');
            $table->dropUnique('envelope_attachment_audit_unique');
            $table->dropColumn(['document_id', 'audit_reference']);
        });
    }
};
