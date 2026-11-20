<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class TaxMappingService
{
    /**
     * Calculate a tax form from posted journal lines and persisted field mappings.
     *
     * The returned payload is deliberately framework-friendly and contains:
     * - fields: rounded values ready for rendering;
     * - facts: unrounded account/tag aggregates;
     * - validations: blocking and warning messages;
     * - provenance: journal-line sources for every calculated field.
     */
    public function calculateForm(
        int $companyId,
        int $taxFormDefinitionId,
        string $periodStart,
        string $periodEnd,
        ?int $taxProfileId = null,
    ): array {
        if ($periodStart > $periodEnd) {
            throw new InvalidArgumentException('The tax period start must be before or equal to its end.');
        }

        $definition = DB::table('tax_form_definitions')
            ->where('id', $taxFormDefinitionId)
            ->first();

        if (! $definition) {
            throw new InvalidArgumentException('The requested tax form definition does not exist.');
        }

        $fields = DB::table('tax_form_fields')
            ->where('tax_form_definition_id', $taxFormDefinitionId)
            ->orderBy('id')
            ->get();

        $mappings = DB::table('tax_form_field_account_mappings as mapping')
            ->join('accounts', 'accounts.id', '=', 'mapping.account_id')
            ->whereIn('mapping.tax_form_field_id', $fields->pluck('id'))
            ->where('accounts.company_id', $companyId)
            ->where(function ($query) use ($companyId): void {
                $query->whereNull('mapping.company_id')
                    ->orWhere('mapping.company_id', $companyId);
            })
            ->select([
                'mapping.id as mapping_id',
                'mapping.tax_form_field_id',
                'mapping.company_id as mapping_company_id',
                'mapping.tax_tag',
                'mapping.direction',
                'mapping.allocation_percent',
                'mapping.mapping_mode',
                'accounts.id as account_id',
                'accounts.code as account_code',
                'accounts.name as account_name',
                'accounts.account_type',
            ])
            ->get();

        $accountIds = $mappings->pluck('account_id')->unique()->values();
        $lines = $this->journalLines($companyId, $periodStart, $periodEnd, $accountIds);
        $fieldsById = $fields->keyBy('id');
        $mappingsByField = $this->effectiveMappings($mappings, $companyId);

        $result = [
            'form' => [
                'id' => (int) $definition->id,
                'code' => $definition->code,
                'name' => $definition->name,
                'jurisdiction' => $definition->jurisdiction,
                'tax_year' => (int) $definition->tax_year,
                'version' => $definition->version,
                'company_id' => $companyId,
                'tax_profile_id' => $taxProfileId,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
            ],
            'fields' => [],
            'facts' => [],
            'validations' => [],
        ];

        foreach ($fields as $field) {
            $fieldMappings = $mappingsByField->get($field->id, collect());
            $fieldLines = collect();
            $rawValue = '0';
            $fieldFacts = [];

            foreach ($fieldMappings as $mapping) {
                $mappingLines = $lines->where('account_id', (int) $mapping->account_id);
                $amount = $this->amountForMapping($mappingLines, $mapping);

                if ($mapping->mapping_mode === 'exclude') {
                    $amount = $this->decimalMultiply($amount, '-1');
                }

                if ($mapping->allocation_percent !== null) {
                    $amount = $this->decimalMultiply(
                        $amount,
                        $this->decimalDivide((string) $mapping->allocation_percent, '100')
                    );
                }

                $rawValue = $this->decimalAdd($rawValue, $amount);
                $fieldLines = $fieldLines->merge($mappingLines);

                $factKey = $mapping->tax_tag ?: 'account.' . $mapping->account_code;
                $result['facts'][$factKey] = $this->decimalAdd(
                    $result['facts'][$factKey] ?? '0',
                    $amount
                );
                $fieldFacts[] = [
                    'fact_key' => $factKey,
                    'mapping_id' => (int) $mapping->mapping_id,
                    'account_id' => (int) $mapping->account_id,
                    'account_code' => $mapping->account_code,
                    'amount' => $amount,
                ];
            }

            $scale = max(0, min(12, (int) $field->scale));
            $reportedValue = $this->decimalRound($rawValue, $scale, (string) $field->rounding_policy);
            $roundingDifference = $this->decimalSubtract($reportedValue, $rawValue);
            $validationMessages = $this->validateField($field, $reportedValue);

            foreach ($validationMessages as $validation) {
                $result['validations'][] = array_merge($validation, [
                    'field_key' => $field->field_key,
                ]);
            }

            $result['fields'][$field->field_key] = [
                'field_id' => (int) $field->id,
                'form_line' => $field->form_line,
                'label' => $field->label,
                'raw_value' => $rawValue,
                'reported_value' => $reportedValue,
                'rounding_difference' => $roundingDifference,
                'currency' => $taxProfileId ? $this->profileCurrency($taxProfileId) : null,
                'provenance' => [
                    'period_start' => $periodStart,
                    'period_end' => $periodEnd,
                    'tax_profile_id' => $taxProfileId,
                    'mappings' => $fieldFacts,
                    'journal_line_ids' => $fieldLines->pluck('journal_line_id')->unique()->values()->all(),
                ],
            ];
        }

        return $result;
    }

    /**
     * Persist calculated fields and facts for an existing TaxReturnRun.
     * Existing values for the same run are replaced atomically.
     */
    public function persistCalculation(int $taxReturnRunId, array $calculation): void
    {
        if (! isset($calculation['form']['period_start'], $calculation['form']['period_end'])) {
            throw new InvalidArgumentException('A calculation payload must include its tax period.');
        }

        DB::transaction(function () use ($taxReturnRunId, $calculation): void {
            $run = DB::table('tax_return_runs')->where('id', $taxReturnRunId)->lockForUpdate()->first();
            if (! $run) {
                throw new InvalidArgumentException('The requested tax return run does not exist.');
            }

            DB::table('tax_return_values')->where('tax_return_run_id', $taxReturnRunId)->delete();
            DB::table('tax_validations')->where('tax_return_run_id', $taxReturnRunId)->delete();

            foreach ($calculation['fields'] as $field) {
                DB::table('tax_return_values')->insert([
                    'tax_return_run_id' => $taxReturnRunId,
                    'tax_form_field_id' => $field['field_id'],
                    'raw_numeric_value' => $field['raw_value'],
                    'reported_numeric_value' => $field['reported_value'],
                    'text_value' => null,
                    'currency' => $field['currency'],
                    'rounding_difference' => $field['rounding_difference'],
                    'provenance' => json_encode($field['provenance'], JSON_THROW_ON_ERROR),
                    'calculation_trace' => json_encode([
                        'form' => $calculation['form'],
                        'field_key' => $field['label'],
                    ], JSON_THROW_ON_ERROR),
                    'is_overridden' => false,
                    'overridden_by' => null,
                    'override_reason' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            foreach ($calculation['validations'] as $validation) {
                DB::table('tax_validations')->insert([
                    'tax_return_run_id' => $taxReturnRunId,
                    'rule_code' => $validation['rule_code'],
                    'severity' => $validation['severity'],
                    'status' => 'open',
                    'field_key' => $validation['field_key'] ?? null,
                    'message' => $validation['message'],
                    'context' => json_encode($validation['context'] ?? [], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $currency = collect($calculation['fields'])
                ->pluck('currency')
                ->filter()
                ->first();

            foreach ($calculation['facts'] as $factKey => $factValue) {
                DB::table('tax_facts')->insert([
                    'company_id' => $calculation['form']['company_id'],
                    'tax_profile_id' => $calculation['form']['tax_profile_id'] ?? null,
                    'fact_key' => $factKey,
                    'value_type' => 'decimal',
                    'numeric_value' => $factValue,
                    'text_value' => null,
                    'value_json' => null,
                    'currency' => $currency,
                    'period_start' => $calculation['form']['period_start'],
                    'period_end' => $calculation['form']['period_end'],
                    'source_hash' => null,
                    'provenance' => json_encode([
                        'tax_return_run_id' => $taxReturnRunId,
                        'form' => $calculation['form'],
                    ], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('tax_return_runs')->where('id', $taxReturnRunId)->update([
                'status' => empty($calculation['validations']) ? 'calculated' : 'validation_failed',
                'summary' => json_encode([
                    'field_count' => count($calculation['fields']),
                    'fact_count' => count($calculation['facts']),
                    'validation_count' => count($calculation['validations']),
                ], JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
        });
    }

    private function journalLines(int $companyId, string $periodStart, string $periodEnd, $accountIds)
    {
        if ($accountIds->isEmpty()) {
            return collect();
        }

        return DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.company_id', $companyId)
            ->where('journal_entries.status', 'posted')
            ->whereBetween('journal_entries.entry_date', [$periodStart, $periodEnd])
            ->whereIn('journal_lines.account_id', $accountIds)
            ->select([
                'journal_lines.id as journal_line_id',
                'journal_lines.account_id',
                'journal_lines.debit',
                'journal_lines.credit',
                'journal_entries.entry_date',
                'journal_entries.entry_number',
            ])
            ->orderBy('journal_lines.id')
            ->get();
    }

    private function effectiveMappings($mappings, int $companyId)
    {
        return $mappings
            ->groupBy('tax_form_field_id')
            ->map(function ($fieldMappings) use ($companyId) {
                $companyMappings = $fieldMappings->where('mapping_company_id', $companyId);

                return $companyMappings->isNotEmpty()
                    ? $companyMappings->values()
                    : $fieldMappings->whereNull('mapping_company_id')->values();
            });
    }

    private function amountForMapping($lines, object $mapping): string
    {
        $direction = strtolower((string) ($mapping->direction ?: 'both'));
        $amount = '0';

        foreach ($lines as $line) {
            $value = match ($direction) {
                'debit' => (string) $line->debit,
                'credit' => (string) $line->credit,
                'net' => $this->decimalSubtract((string) $line->debit, (string) $line->credit),
                default => $this->normalBalanceAmount($line, (string) $mapping->account_type),
            };
            $amount = $this->decimalAdd($amount, $value);
        }

        return $amount;
    }

    private function normalBalanceAmount(object $line, string $accountType): string
    {
        $debitAccounts = ['asset', 'expense', 'cogs'];
        return in_array($accountType, $debitAccounts, true)
            ? $this->decimalSubtract((string) $line->debit, (string) $line->credit)
            : $this->decimalSubtract((string) $line->credit, (string) $line->debit);
    }

    private function validateField(object $field, string $value): array
    {
        $rules = json_decode((string) ($field->validation_rules ?? '[]'), true) ?: [];
        $validations = [];

        if (($field->is_required ?? false)
            && $this->decimalCompare($value, '0') === 0
            && ($field->zero_policy ?? 'emit_zero') === 'omit') {
            $validations[] = [
                'rule_code' => 'required_non_zero',
                'severity' => 'error',
                'message' => 'The required tax field must contain a non-zero value.',
            ];
        }

        if (isset($rules['min']) && $this->decimalCompare($value, (string) $rules['min']) < 0) {
            $validations[] = [
                'rule_code' => 'field_minimum',
                'severity' => $rules['min_severity'] ?? 'error',
                'message' => 'The calculated value is below the configured minimum.',
                'context' => ['min' => $rules['min'], 'value' => $value],
            ];
        }

        if (isset($rules['max']) && $this->decimalCompare($value, (string) $rules['max']) > 0) {
            $validations[] = [
                'rule_code' => 'field_maximum',
                'severity' => $rules['max_severity'] ?? 'error',
                'message' => 'The calculated value exceeds the configured maximum.',
                'context' => ['max' => $rules['max'], 'value' => $value],
            ];
        }

        return $validations;
    }

    private function profileCurrency(int $taxProfileId): ?string
    {
        return DB::table('tax_profiles')->where('id', $taxProfileId)->value('currency');
    }

    private function decimalAdd(string $left, string $right): string
    {
        return bcadd($left, $right, 12);
    }

    private function decimalSubtract(string $left, string $right): string
    {
        return bcsub($left, $right, 12);
    }

    private function decimalMultiply(string $left, string $right): string
    {
        return bcmul($left, $right, 12);
    }

    private function decimalDivide(string $left, string $right): string
    {
        if (bccomp($right, '0', 12) === 0) {
            throw new RuntimeException('Cannot divide a tax mapping amount by zero.');
        }

        return bcdiv($left, $right, 12);
    }

    private function decimalCompare(string $left, string $right): int
    {
        return bccomp($left, $right, 12);
    }

    private function decimalRound(string $value, int $scale, string $policy): string
    {
        if ($policy !== 'half_up') {
            throw new InvalidArgumentException('Unsupported tax rounding policy: ' . $policy);
        }

        $negative = bccomp($value, '0', 12) < 0;
        $absolute = $negative ? bcmul($value, '-1', 12) : $value;
        $factor = bcpow('10', (string) $scale, 12);
        $scaled = bcmul($absolute, $factor, 12);
        $rounded = bcadd($scaled, '0.5', 0);
        $result = bcdiv($rounded, $factor, $scale);

        return $negative && bccomp($result, '0', $scale) !== 0 ? '-' . $result : $result;
    }
}
