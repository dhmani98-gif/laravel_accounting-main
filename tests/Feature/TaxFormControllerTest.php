<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TaxFormControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    public function test_api_creates_a_return_exposes_fields_and_records_manual_overrides(): void
    {
        $companyId = DB::table('companies')->insertGetId([
            'name' => 'Tax API Test Company',
            'country_code' => 'US',
            'currency' => 'USD',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $user = User::create([
            'name' => 'Tax API Owner',
            'first_name' => 'Tax',
            'last_name' => 'Owner',
            'email' => 'tax-api-owner@example.test',
            'password' => 'secret',
            'role' => 'owner',
            'company_id' => $companyId,
            'must_change_password' => false,
            'is_active' => true,
        ]);
        $definitionId = DB::table('tax_form_definitions')->insertGetId([
            'code' => 'US-API-TEST',
            'name' => 'API test return',
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
            'is_required' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user);
        $create = $this->withHeader('Accept', 'application/json')->postJson(
            "/api/tax/forms/{$definitionId}/runs",
            [
                'period_start' => '2026-01-01',
                'period_end' => '2026-03-31',
            ]
        );
        $create->assertCreated()
            ->assertJsonPath('data.run.form.code', 'US-API-TEST')
            ->assertJsonPath('data.fields.0.field_key', 'gross_receipts')
            ->assertJsonPath('data.can_edit', true);

        $runId = $create->json('data.run.id');
        $this->getJson("/api/tax/runs/{$runId}")
            ->assertOk()
            ->assertJsonPath('data.fields.0.provenance.period_start', '2026-01-01');

        $this->patchJson("/api/tax/runs/{$runId}/fields/{$fieldId}", [
            'reported_value' => '125.50',
            'override_reason' => 'Approved adjustment from tax reviewer.',
        ])->assertOk()
            ->assertJsonPath('data.fields.0.reported_value', '125.50')
            ->assertJsonPath('data.fields.0.is_overridden', true)
            ->assertJsonPath('data.run.status', 'ready_for_review');

        $this->assertDatabaseHas('tax_audit_events', [
            'tax_return_run_id' => $runId,
            'event_type' => 'tax_field_overridden',
            'entity_id' => $fieldId,
        ]);
    }

    public function test_api_rejects_short_override_reasons(): void
    {
        $companyId = DB::table('companies')->insertGetId([
            'name' => 'Tax API Validation Company',
            'country_code' => 'DE',
            'currency' => 'EUR',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $user = User::create([
            'name' => 'Tax API Owner 2',
            'first_name' => 'Tax',
            'last_name' => 'Owner 2',
            'email' => 'tax-api-owner-2@example.test',
            'password' => 'secret',
            'role' => 'owner',
            'company_id' => $companyId,
            'must_change_password' => false,
            'is_active' => true,
        ]);
        $definitionId = DB::table('tax_form_definitions')->insertGetId([
            'code' => 'DE-API-TEST',
            'name' => 'API validation return',
            'jurisdiction' => 'DE',
            'tax_year' => 2026,
            'period_type' => 'quarter',
            'version' => '1.0',
            'status' => 'approved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user);
        $response = $this->postJson("/api/tax/forms/{$definitionId}/runs", [
            'period_start' => '2026-01-01',
            'period_end' => '2026-03-31',
        ]);
        $runId = $response->json('data.run.id');

        $response = $this->patchJson("/api/tax/runs/{$runId}/fields/999", [
            'reported_value' => '10',
            'override_reason' => 'short',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['override_reason']);
    }
}
