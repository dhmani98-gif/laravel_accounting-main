<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\TaxMappingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TaxMappingServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_maps_posted_journal_lines_to_form_fields_and_keeps_provenance(): void
    {
        $companyId = DB::table('companies')->insertGetId([
            'name' => 'US Tax Mapping Test',
            'country_code' => 'US',
            'currency' => 'USD',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $userId = DB::table('users')->insertGetId([
            'name' => 'Tax Tester',
            'first_name' => 'Tax',
            'last_name' => 'Tester',
            'email' => 'tax-mapping@example.test',
            'password' => 'secret',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $revenueAccountId = DB::table('accounts')->insertGetId([
            'code' => '4000',
            'name' => 'Revenue',
            'account_type' => 'revenue',
            'company_id' => $companyId,
            'is_active' => true,
            'is_system' => false,
            'balance' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $cashAccountId = DB::table('accounts')->insertGetId([
            'code' => '1000',
            'name' => 'Cash',
            'account_type' => 'asset',
            'company_id' => $companyId,
            'is_active' => true,
            'is_system' => false,
            'balance' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $entryId = DB::table('journal_entries')->insertGetId([
            'entry_number' => 'JE-001',
            'entry_date' => '2026-03-31',
            'description' => 'Revenue entry',
            'entry_type' => 'manual',
            'status' => 'posted',
            'total_debit' => 100.005,
            'total_credit' => 100.005,
            'company_id' => $companyId,
            'created_by' => $userId,
            'posted_by' => $userId,
            'posted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('journal_lines')->insert([
            [
                'journal_entry_id' => $entryId,
                'account_id' => $cashAccountId,
                'description' => 'Cash',
                'debit' => 100.005,
                'credit' => 0,
            ],
            [
                'journal_entry_id' => $entryId,
                'account_id' => $revenueAccountId,
                'description' => 'Revenue',
                'debit' => 0,
                'credit' => 100.005,
            ],
        ]);

        $definitionId = DB::table('tax_form_definitions')->insertGetId([
            'code' => 'US-TEST-RETURN',
            'name' => 'Test return',
            'jurisdiction' => 'US-FED',
            'tax_year' => 2026,
            'period_type' => 'quarter',
            'version' => '1.0',
            'status' => 'approved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $fieldId = DB::table('tax_form_fields')->insertGetId([
            'tax_form_definition_id' => $definitionId,
            'field_key' => 'gross_receipts',
            'form_line' => '1a',
            'label' => 'Gross receipts',
            'data_type' => 'decimal',
            'scale' => 2,
            'rounding_policy' => 'half_up',
            'sign_policy' => 'normal',
            'zero_policy' => 'emit_zero',
            'is_required' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('tax_form_field_account_mappings')->insert([
            'tax_form_field_id' => $fieldId,
            'company_id' => null,
            'account_id' => $revenueAccountId,
            'tax_tag' => 'us_income.gross_receipts',
            'direction' => 'both',
            'mapping_mode' => 'include',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $calculation = app(TaxMappingService::class)->calculateForm(
            $companyId,
            $definitionId,
            '2026-01-01',
            '2026-03-31'
        );

        $this->assertSame('100.005000000000', $calculation['fields']['gross_receipts']['raw_value']);
        $this->assertSame('100.01', $calculation['fields']['gross_receipts']['reported_value']);
        $this->assertSame('100.005000000000', $calculation['facts']['us_income.gross_receipts']);
        $this->assertSame([1], [count($calculation['fields']['gross_receipts']['provenance']['journal_line_ids'])]);
        $this->assertSame([], $calculation['validations']);
    }

    public function test_it_persists_return_values_facts_and_validation_status(): void
    {
        $companyId = DB::table('companies')->insertGetId([
            'name' => 'Persistence Test',
            'country_code' => 'DE',
            'currency' => 'EUR',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $userId = DB::table('users')->insertGetId([
            'name' => 'Tax Tester 2',
            'first_name' => 'Tax',
            'last_name' => 'Tester 2',
            'email' => 'tax-mapping-2@example.test',
            'password' => 'secret',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $accountId = DB::table('accounts')->insertGetId([
            'code' => '4000',
            'name' => 'Revenue',
            'account_type' => 'revenue',
            'company_id' => $companyId,
            'is_active' => true,
            'is_system' => false,
            'balance' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $definitionId = DB::table('tax_form_definitions')->insertGetId([
            'code' => 'DE-TEST-RETURN',
            'name' => 'Test return',
            'jurisdiction' => 'DE',
            'tax_year' => 2026,
            'period_type' => 'quarter',
            'version' => '1.0',
            'status' => 'approved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $fieldId = DB::table('tax_form_fields')->insertGetId([
            'tax_form_definition_id' => $definitionId,
            'field_key' => 'vat_base',
            'form_line' => '81',
            'label' => 'VAT base',
            'data_type' => 'decimal',
            'scale' => 2,
            'rounding_policy' => 'half_up',
            'sign_policy' => 'normal',
            'zero_policy' => 'omit',
            'is_required' => true,
            'validation_rules' => json_encode(['min' => '1']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('tax_form_field_account_mappings')->insert([
            'tax_form_field_id' => $fieldId,
            'account_id' => $accountId,
            'tax_tag' => 'de.vat.base',
            'direction' => 'both',
            'mapping_mode' => 'include',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $runId = DB::table('tax_return_runs')->insertGetId([
            'company_id' => $companyId,
            'tax_form_definition_id' => $definitionId,
            'run_type' => 'original',
            'status' => 'draft',
            'period_start' => '2026-01-01',
            'period_end' => '2026-03-31',
            'idempotency_key' => 'de-test-run-1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = app(TaxMappingService::class);
        $calculation = $service->calculateForm($companyId, $definitionId, '2026-01-01', '2026-03-31');
        $service->persistCalculation($runId, $calculation);

        $this->assertDatabaseHas('tax_return_runs', ['id' => $runId, 'status' => 'validation_failed']);
        $this->assertDatabaseHas('tax_return_values', ['tax_return_run_id' => $runId, 'tax_form_field_id' => $fieldId]);
        $this->assertDatabaseHas('tax_facts', ['company_id' => $companyId, 'fact_key' => 'de.vat.base']);
        $this->assertDatabaseHas('tax_validations', ['tax_return_run_id' => $runId, 'rule_code' => 'required_non_zero']);
    }
}
