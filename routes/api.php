<?php

use App\Http\Controllers\TaxFormController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'password.change', 'company'])->prefix('tax')->group(function (): void {
    Route::middleware('permission:view_reports')->group(function (): void {
        Route::get('/forms', [TaxFormController::class, 'definitions'])->name('api.tax.forms.index');
        Route::get('/runs/{taxReturnRunId}', [TaxFormController::class, 'showRun'])->name('api.tax.runs.show');
        Route::get('/runs/{taxReturnRunId}/export-preview', [TaxFormController::class, 'exportPreview'])->name('api.tax.runs.export-preview');
    });

    Route::middleware('permission:manage_accounts')->group(function (): void {
        Route::post('/forms/{taxFormDefinitionId}/runs', [TaxFormController::class, 'createRun'])->name('api.tax.runs.store');
        Route::patch('/runs/{taxReturnRunId}/fields/{taxFormFieldId}', [TaxFormController::class, 'updateField'])->name('api.tax.fields.update');
        Route::post('/runs/{taxReturnRunId}/recalculate', [TaxFormController::class, 'recalculate'])->name('api.tax.runs.recalculate');
    });
});
