<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_facts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('tax_profile_id')->nullable()->constrained('tax_profiles')->nullOnDelete();
            $table->string('fact_key', 180);
            $table->string('value_type', 30)->default('decimal');
            $table->decimal('numeric_value', 24, 12)->nullable();
            $table->string('text_value', 1000)->nullable();
            $table->json('value_json')->nullable();
            $table->string('currency', 3)->nullable();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->string('source_hash', 128)->nullable();
            $table->json('provenance')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'fact_key', 'period_start', 'period_end']);
            $table->index(['tax_profile_id', 'fact_key']);
        });

        Schema::create('tax_return_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('tax_profile_id')->nullable()->constrained('tax_profiles')->nullOnDelete();
            $table->foreignId('tax_form_definition_id')->constrained('tax_form_definitions')->restrictOnDelete();
            $table->foreignId('tax_rule_pack_id')->nullable()->constrained('tax_rule_packs')->restrictOnDelete();
            $table->foreignId('supersedes_run_id')->nullable()->constrained('tax_return_runs')->nullOnDelete();
            $table->string('run_type', 30)->default('original');
            $table->string('status', 40)->default('draft');
            $table->date('period_start');
            $table->date('period_end');
            $table->string('idempotency_key', 160)->unique();
            $table->string('input_snapshot_hash', 128)->nullable();
            $table->string('output_hash', 128)->nullable();
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->json('summary')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status', 'period_end']);
            $table->index(['tax_form_definition_id', 'period_start', 'period_end']);
        });

        Schema::create('tax_return_values', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tax_return_run_id')->constrained('tax_return_runs')->cascadeOnDelete();
            $table->foreignId('tax_form_field_id')->constrained('tax_form_fields')->restrictOnDelete();
            $table->decimal('raw_numeric_value', 24, 12)->nullable();
            $table->decimal('reported_numeric_value', 24, 12)->nullable();
            $table->string('text_value', 2000)->nullable();
            $table->string('currency', 3)->nullable();
            $table->decimal('rounding_difference', 24, 12)->default(0);
            $table->json('provenance')->nullable();
            $table->json('calculation_trace')->nullable();
            $table->boolean('is_overridden')->default(false);
            $table->foreignId('overridden_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('override_reason')->nullable();
            $table->timestamps();

            $table->unique(['tax_return_run_id', 'tax_form_field_id']);
            $table->index(['tax_form_field_id', 'reported_numeric_value']);
        });

        Schema::create('tax_validations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tax_return_run_id')->constrained('tax_return_runs')->cascadeOnDelete();
            $table->string('rule_code', 160);
            $table->string('severity', 20)->default('error');
            $table->string('status', 20)->default('open');
            $table->string('field_key', 160)->nullable();
            $table->text('message');
            $table->json('context')->nullable();
            $table->timestamps();

            $table->index(['tax_return_run_id', 'severity', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_validations');
        Schema::dropIfExists('tax_return_values');
        Schema::dropIfExists('tax_return_runs');
        Schema::dropIfExists('tax_facts');
    }
};
