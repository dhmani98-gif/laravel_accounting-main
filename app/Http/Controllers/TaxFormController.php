<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\TaxMappingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

class TaxFormController extends Controller
{
    public function __construct(
        private readonly TaxMappingService $taxMappingService,
    ) {
    }

    public function definitions(Request $request): JsonResponse
    {
        $query = DB::table('tax_form_definitions')
            ->where('status', '!=', 'retired')
            ->orderByDesc('tax_year')
            ->orderBy('jurisdiction')
            ->orderBy('code');

        if ($request->filled('jurisdiction')) {
            $query->where('jurisdiction', $request->string('jurisdiction')->toString());
        }

        if ($request->filled('tax_year')) {
            $query->where('tax_year', $request->integer('tax_year'));
        }

        return response()->json([
            'data' => $query->get()->map(fn (object $definition): array => [
                'id' => (int) $definition->id,
                'code' => $definition->code,
                'name' => $definition->name,
                'jurisdiction' => $definition->jurisdiction,
                'tax_year' => (int) $definition->tax_year,
                'period_type' => $definition->period_type,
                'version' => $definition->version,
                'status' => $definition->status,
                'supported_outputs' => $this->decodeJson($definition->supported_outputs, []),
            ])->values(),
        ]);
    }

    public function createRun(Request $request, int $taxFormDefinitionId): JsonResponse
    {
        $validated = $request->validate([
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'tax_profile_id' => ['nullable', 'integer'],
        ]);

        $companyId = $this->companyId($request);
        $definition = DB::table('tax_form_definitions')->where('id', $taxFormDefinitionId)->first();

        abort_if(! $definition, 404, 'Tax form definition not found.');

        if ($validated['tax_profile_id'] ?? null) {
            $profileExists = DB::table('tax_profiles')
                ->where('id', $validated['tax_profile_id'])
                ->where('company_id', $companyId)
                ->exists();
            abort_unless($profileExists, 422, 'The tax profile does not belong to the current company.');
        }

        $idempotencyKey = $request->header('Idempotency-Key')
            ?: sprintf('tax-run:%d:%d:%s:%s', $companyId, $taxFormDefinitionId, $validated['period_start'], $validated['period_end']);

        $existing = DB::table('tax_return_runs')
            ->where('company_id', $companyId)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing) {
            return $this->showRun($request, (int) $existing->id);
        }

        $runId = DB::table('tax_return_runs')->insertGetId([
            'company_id' => $companyId,
            'tax_profile_id' => $validated['tax_profile_id'] ?? null,
            'tax_form_definition_id' => $taxFormDefinitionId,
            'run_type' => 'original',
            'status' => 'draft',
            'period_start' => $validated['period_start'],
            'period_end' => $validated['period_end'],
            'idempotency_key' => $idempotencyKey,
            'prepared_by' => $request->user()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $calculation = $this->taxMappingService->calculateForm(
            $companyId,
            $taxFormDefinitionId,
            $validated['period_start'],
            $validated['period_end'],
            $validated['tax_profile_id'] ?? null,
        );
        $this->taxMappingService->persistCalculation($runId, $calculation);

        return $this->showRun($request, $runId, Response::HTTP_CREATED);
    }

    public function showRun(Request $request, int $taxReturnRunId, int $status = Response::HTTP_OK): JsonResponse
    {
        $run = $this->runForCompany($request, $taxReturnRunId);
        $values = DB::table('tax_return_values as value')
            ->join('tax_form_fields as field', 'field.id', '=', 'value.tax_form_field_id')
            ->where('value.tax_return_run_id', $run->id)
            ->select([
                'field.id as field_id',
                'field.field_key',
                'field.form_line',
                'field.label',
                'field.data_type',
                'field.scale',
                'value.raw_numeric_value',
                'value.reported_numeric_value',
                'value.text_value',
                'value.currency',
                'value.rounding_difference',
                'value.provenance',
                'value.calculation_trace',
                'value.is_overridden',
                'value.override_reason',
            ])
            ->orderBy('field.id')
            ->get()
            ->map(fn (object $value): array => [
                'field_id' => (int) $value->field_id,
                'field_key' => $value->field_key,
                'form_line' => $value->form_line,
                'label' => $value->label,
                'data_type' => $value->data_type,
                'scale' => (int) $value->scale,
                'raw_value' => $value->raw_numeric_value === null
                    ? null
                    : $this->decimalString($value->raw_numeric_value, (int) $value->scale),
                'reported_value' => $value->reported_numeric_value === null
                    ? null
                    : $this->decimalString($value->reported_numeric_value, (int) $value->scale),
                'text_value' => $value->text_value,
                'currency' => $value->currency,
                'rounding_difference' => $value->rounding_difference === null
                    ? null
                    : $this->decimalString($value->rounding_difference, (int) $value->scale),
                'is_overridden' => (bool) $value->is_overridden,
                'override_reason' => $value->override_reason,
                'provenance' => $this->decodeJson($value->provenance, []),
                'calculation_trace' => $this->decodeJson($value->calculation_trace, []),
            ])->values();

        $validations = DB::table('tax_validations')
            ->where('tax_return_run_id', $run->id)
            ->orderBy('severity')
            ->orderBy('id')
            ->get()
            ->map(fn (object $validation): array => [
                'id' => (int) $validation->id,
                'rule_code' => $validation->rule_code,
                'severity' => $validation->severity,
                'status' => $validation->status,
                'field_key' => $validation->field_key,
                'message' => $validation->message,
                'context' => $this->decodeJson($validation->context, []),
            ])->values();

        return response()->json([
            'data' => [
                'run' => [
                    'id' => (int) $run->id,
                    'status' => $run->status,
                    'run_type' => $run->run_type,
                    'period_start' => $run->period_start,
                    'period_end' => $run->period_end,
                    'idempotency_key' => $run->idempotency_key,
                    'form' => [
                        'id' => (int) $run->tax_form_definition_id,
                        'code' => $run->form_code,
                        'name' => $run->form_name,
                        'jurisdiction' => $run->jurisdiction,
                        'tax_year' => (int) $run->tax_year,
                        'version' => $run->form_version,
                    ],
                ],
                'fields' => $values,
                'validations' => $validations,
                'can_edit' => $this->canEdit($run),
                'can_export' => $this->canExport($run, $validations),
            ],
        ], $status);
    }

    public function updateField(Request $request, int $taxReturnRunId, int $taxFormFieldId): JsonResponse
    {
        $run = $this->runForCompany($request, $taxReturnRunId);
        $this->assertEditable($run);

        $validated = $request->validate([
            'reported_value' => ['required', 'numeric'],
            'override_reason' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        $field = DB::table('tax_form_fields')
            ->where('id', $taxFormFieldId)
            ->where('tax_form_definition_id', $run->tax_form_definition_id)
            ->first();
        abort_if(! $field, 404, 'Tax form field not found for this return.');

        $value = DB::table('tax_return_values')
            ->where('tax_return_run_id', $run->id)
            ->where('tax_form_field_id', $field->id)
            ->first();
        abort_if(! $value, 404, 'Calculated value not found. Recalculate the return first.');

        $reportedValue = $this->decimalString($validated['reported_value'], (int) $field->scale);
        $oldValue = $value->reported_numeric_value;

        DB::transaction(function () use ($request, $run, $field, $value, $reportedValue, $oldValue, $validated): void {
            DB::table('tax_return_values')->where('id', $value->id)->update([
                'reported_numeric_value' => $reportedValue,
                'is_overridden' => true,
                'overridden_by' => $request->user()->id,
                'override_reason' => $validated['override_reason'],
                'updated_at' => now(),
            ]);
            DB::table('tax_return_runs')->where('id', $run->id)->update([
                'status' => 'ready_for_review',
                'updated_at' => now(),
            ]);
            DB::table('tax_audit_events')->insert([
                'company_id' => $run->company_id,
                'tax_return_run_id' => $run->id,
                'user_id' => $request->user()->id,
                'event_type' => 'tax_field_overridden',
                'entity_type' => 'tax_form_field',
                'entity_id' => $field->id,
                'payload_hash' => hash('sha256', json_encode([
                    'old' => $oldValue,
                    'new' => $reportedValue,
                    'reason' => $validated['override_reason'],
                ], JSON_THROW_ON_ERROR)),
                'before_snapshot' => json_encode(['reported_value' => $oldValue], JSON_THROW_ON_ERROR),
                'after_snapshot' => json_encode(['reported_value' => $reportedValue], JSON_THROW_ON_ERROR),
                'reason' => $validated['override_reason'],
                'occurred_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return $this->showRun($request, $run->id);
    }

    public function recalculate(Request $request, int $taxReturnRunId): JsonResponse
    {
        $run = $this->runForCompany($request, $taxReturnRunId);
        $this->assertEditable($run);

        $calculation = $this->taxMappingService->calculateForm(
            (int) $run->company_id,
            (int) $run->tax_form_definition_id,
            $run->period_start,
            $run->period_end,
            $run->tax_profile_id ? (int) $run->tax_profile_id : null,
        );
        $this->taxMappingService->persistCalculation($run->id, $calculation);

        return $this->showRun($request, $run->id);
    }

    public function exportPreview(Request $request, int $taxReturnRunId): JsonResponse
    {
        $run = $this->runForCompany($request, $taxReturnRunId);
        $validations = DB::table('tax_validations')
            ->where('tax_return_run_id', $run->id)
            ->where('status', 'open')
            ->where('severity', 'error')
            ->get();

        abort_if($validations->isNotEmpty(), 422, 'The return has blocking validation errors and cannot be exported.');
        abort_unless(in_array($run->status, ['calculated', 'ready_for_review', 'approved'], true), 409, 'The return is not ready for export.');

        return response()->json([
            'data' => [
                'run_id' => (int) $run->id,
                'status' => $run->status,
                'exportable' => true,
                'message' => 'The reviewed values are ready for an export adapter. No tax filing was submitted.',
            ],
        ]);
    }

    private function runForCompany(Request $request, int $taxReturnRunId): object
    {
        $run = DB::table('tax_return_runs as run')
            ->join('tax_form_definitions as form', 'form.id', '=', 'run.tax_form_definition_id')
            ->where('run.id', $taxReturnRunId)
            ->where('run.company_id', $this->companyId($request))
            ->select([
                'run.*',
                'form.code as form_code',
                'form.name as form_name',
                'form.jurisdiction',
                'form.tax_year',
                'form.version as form_version',
            ])
            ->first();

        abort_if(! $run, 404, 'Tax return run not found.');

        return $run;
    }

    private function assertEditable(object $run): void
    {
        abort_unless(
            in_array($run->status, ['draft', 'calculated', 'validation_failed', 'ready_for_review'], true),
            409,
            'This tax return is locked because it was approved or submitted.'
        );
    }

    private function canEdit(object $run): bool
    {
        return in_array($run->status, ['draft', 'calculated', 'validation_failed', 'ready_for_review'], true);
    }

    private function canExport(object $run, $validations): bool
    {
        return in_array($run->status, ['calculated', 'ready_for_review', 'approved'], true)
            && $validations->where('severity', 'error')->where('status', 'open')->isEmpty();
    }

    private function companyId(Request $request): int
    {
        return (int) $request->user()->company_id;
    }

    private function decodeJson(?string $value, mixed $default): mixed
    {
        if (! $value) {
            return $default;
        }

        $decoded = json_decode($value, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $default;
    }

    private function decimalString(mixed $value, int $scale): string
    {
        $string = trim((string) $value);
        if (! is_numeric($string)) {
            throw new InvalidArgumentException('The tax field value must be numeric.');
        }

        return number_format((float) $string, max(0, min(12, $scale)), '.', '');
    }
}
