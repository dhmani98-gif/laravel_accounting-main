<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_submissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tax_return_run_id')->constrained('tax_return_runs')->cascadeOnDelete();
            $table->string('provider', 40);
            $table->string('channel', 40);
            $table->string('status', 40)->default('pending');
            $table->string('idempotency_key', 160);
            $table->string('remote_submission_id', 255)->nullable();
            $table->string('request_hash', 128)->nullable();
            $table->string('response_hash', 128)->nullable();
            $table->string('signed_artifact_path', 1000)->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->json('response_payload')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'idempotency_key']);
            $table->index(['tax_return_run_id', 'status']);
            $table->index(['remote_submission_id']);
        });

        Schema::create('tax_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('tax_return_run_id')->nullable()->constrained('tax_return_runs')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 80);
            $table->string('entity_type', 120)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('ip_hash', 128)->nullable();
            $table->string('payload_hash', 128)->nullable();
            $table->json('before_snapshot')->nullable();
            $table->json('after_snapshot')->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['company_id', 'event_type', 'occurred_at']);
            $table->index(['tax_return_run_id', 'occurred_at']);
            $table->index(['entity_type', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_audit_events');
        Schema::dropIfExists('tax_submissions');
    }
};
