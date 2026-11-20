<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_form_definitions', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 80);
            $table->string('name', 255);
            $table->string('jurisdiction', 20);
            $table->unsignedSmallInteger('tax_year');
            $table->string('period_type', 30)->default('annual');
            $table->string('version', 40);
            $table->string('status', 30)->default('draft');
            $table->string('schema_uri', 1000)->nullable();
            $table->string('business_rules_uri', 1000)->nullable();
            $table->json('supported_outputs')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->unique(['code', 'tax_year', 'version']);
            $table->index(['jurisdiction', 'tax_year', 'status']);
        });

        Schema::create('tax_form_fields', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tax_form_definition_id')->constrained('tax_form_definitions')->cascadeOnDelete();
            $table->string('field_key', 160);
            $table->string('form_line', 80)->nullable();
            $table->string('label', 255);
            $table->string('data_type', 30)->default('decimal');
            $table->unsignedTinyInteger('scale')->default(2);
            $table->string('rounding_policy', 30)->default('half_up');
            $table->string('sign_policy', 30)->default('normal');
            $table->string('zero_policy', 30)->default('emit_zero');
            $table->boolean('is_required')->default(false);
            $table->string('required_condition', 1000)->nullable();
            $table->text('formula')->nullable();
            $table->json('validation_rules')->nullable();
            $table->json('source_fact_keys')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['tax_form_definition_id', 'field_key']);
            $table->index(['form_line', 'data_type']);
        });

        Schema::create('tax_form_field_account_mappings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tax_form_field_id')->constrained('tax_form_fields')->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->string('tax_tag', 120)->nullable();
            $table->string('direction', 20)->default('both');
            $table->decimal('allocation_percent', 9, 6)->nullable();
            $table->string('mapping_mode', 30)->default('include');
            $table->string('effective_condition', 1000)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tax_form_field_id', 'company_id']);
            $table->index(['account_id', 'tax_tag']);
            $table->unique(['tax_form_field_id', 'company_id', 'account_id', 'tax_tag'], 'tax_field_account_scope_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_form_field_account_mappings');
        Schema::dropIfExists('tax_form_fields');
        Schema::dropIfExists('tax_form_definitions');
    }
};
