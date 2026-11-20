<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('jurisdiction', 20);
            $table->string('entity_type', 40);
            $table->unsignedSmallInteger('tax_year');
            $table->string('taxpayer_id_ref', 255)->nullable();
            $table->string('filing_frequency', 30)->default('annual');
            $table->string('accounting_basis', 20)->default('accrual');
            $table->string('currency', 3)->default('USD');
            $table->json('registrations')->nullable();
            $table->json('settings')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'jurisdiction', 'entity_type', 'tax_year'], 'tax_profiles_company_scope_unique');
            $table->index(['jurisdiction', 'tax_year']);
        });

        Schema::create('tax_rule_packs', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 80);
            $table->string('jurisdiction', 20);
            $table->unsignedSmallInteger('tax_year');
            $table->string('version', 40);
            $table->string('status', 30)->default('draft');
            $table->string('source_uri', 1000)->nullable();
            $table->string('source_hash', 128)->nullable();
            $table->timestamp('effective_from')->nullable();
            $table->timestamp('effective_to')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['code', 'version']);
            $table->index(['jurisdiction', 'tax_year', 'status']);
        });

        Schema::create('tax_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tax_rule_pack_id')->constrained('tax_rule_packs')->cascadeOnDelete();
            $table->string('rule_key', 160);
            $table->string('value_type', 30);
            $table->decimal('numeric_value', 24, 12)->nullable();
            $table->string('text_value', 500)->nullable();
            $table->json('value_json')->nullable();
            $table->string('unit', 30)->nullable();
            $table->string('effective_condition', 1000)->nullable();
            $table->string('source_uri', 1000)->nullable();
            $table->string('source_reference', 255)->nullable();
            $table->boolean('requires_legal_review')->default(true);
            $table->timestamps();

            $table->unique(['tax_rule_pack_id', 'rule_key']);
            $table->index(['rule_key', 'value_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_rules');
        Schema::dropIfExists('tax_rule_packs');
        Schema::dropIfExists('tax_profiles');
    }
};
