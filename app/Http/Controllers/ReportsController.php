<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InventoryMovement;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\SalesChannel;
use App\Models\Supplier;
use App\Models\TaxSetting;
use App\Models\User;
use App\Support\AccountingService;
use App\Support\ChartOfAccountsSynchronizer;
use App\Support\DocumentNumberGenerator;
use App\Support\InventoryMovementService;
use App\Support\PaymentSyncService;
use App\Support\ReferenceGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportsController extends Controller
{
    public function operationsActivityReport(Request $request): View
    {
        $company = $this->company($request);
        $validated = $request->validate([
            'group_by' => ['nullable', Rule::in(['day', 'reference'])],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'reference' => ['nullable', 'string', 'max:100'],
            'sort_direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ]);

        $groupBy = $validated['group_by'] ?? 'day';
        $dateFrom = $validated['date_from'] ?? now()->subDays(29)->toDateString();
        $dateTo = $validated['date_to'] ?? now()->toDateString();
        $reference = trim((string) ($validated['reference'] ?? ''));
        $sortDirection = $validated['sort_direction'] ?? 'desc';

        $payments = Payment::query()
            ->with(['purchase.supplier', 'invoice.customer', 'supplier'])
            ->where('company_id', $company->id)
            ->whereBetween('payment_date', [$dateFrom, $dateTo])
            ->when($reference !== '', fn($query) => $query->where('reference', 'like', '%' . $reference . '%'))
            ->orderBy('payment_date', $sortDirection)
            ->orderBy('id', $sortDirection)
            ->get();

        $inventoryMovements = InventoryMovement::query()
            ->with('product')
            ->where('company_id', $company->id)
            ->whereBetween('movement_date', [$dateFrom, $dateTo])
            ->when($reference !== '', fn($query) => $query->where('reference_number', 'like', '%' . $reference . '%'))
            ->orderBy('movement_date', $sortDirection)
            ->orderBy('id', $sortDirection)
            ->get();

        $paymentGroups = $payments
            ->groupBy(function (Payment $payment) use ($groupBy) {
                if ($groupBy === 'reference') {
                    return $payment->reference ?: 'بدون مرجع';
                }

                return optional($payment->payment_date)->format('Y-m-d') ?: 'بدون تاريخ';
            })
            ->map(function (Collection $rows, string $label) {
                return [
                    'label' => $label,
                    'count' => $rows->count(),
                    'in_total' => round((float) $rows->where('payment_direction', 'in')->sum('amount'), 2),
                    'out_total' => round((float) $rows->where('payment_direction', 'out')->sum('amount'), 2),
                ];
            })
            ->values();

        $movementGroups = $inventoryMovements
            ->groupBy(function (InventoryMovement $movement) use ($groupBy) {
                if ($groupBy === 'reference') {
                    return $movement->reference_number ?: 'بدون مرجع';
                }

                return optional($movement->movement_date)->format('Y-m-d') ?: 'بدون تاريخ';
            })
            ->map(function (Collection $rows, string $label) {
                return [
                    'label' => $label,
                    'count' => $rows->count(),
                    'incoming_quantity' => round((float) $rows->where('direction', 'in')->sum('quantity'), 2),
                    'outgoing_quantity' => round((float) $rows->where('direction', 'out')->sum('quantity'), 2),
                    'inventory_cost' => round((float) $rows->sum('total_cost'), 2),
                ];
            })
            ->values();

        return view('reports.operations_activity', [
            'company' => $company,
            'groupBy' => $groupBy,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'reference' => $reference,
            'sortDirection' => $sortDirection,
            'payments' => $payments,
            'inventoryMovements' => $inventoryMovements,
            'paymentGroups' => $paymentGroups,
            'movementGroups' => $movementGroups,
            'paymentSummary' => [
                'incoming' => round((float) $payments->where('payment_direction', 'in')->sum('amount'), 2),
                'outgoing' => round((float) $payments->where('payment_direction', 'out')->sum('amount'), 2),
                'count' => $payments->count(),
            ],
            'movementSummary' => [
                'incoming_quantity' => round((float) $inventoryMovements->where('direction', 'in')->sum('quantity'), 2),
                'outgoing_quantity' => round((float) $inventoryMovements->where('direction', 'out')->sum('quantity'), 2),
                'count' => $inventoryMovements->count(),
                'inventory_cost' => round((float) $inventoryMovements->sum('total_cost'), 2),
            ],
        ]);
    }

    public function reports(Request $request): View
    {
        $company = $this->company($request);

        if ($request->boolean('print')) {
            return view('reports_print', $this->legacyReportsViewData($request, $company));
        }

        $sections = $this->interactiveReportSections();
        $catalog = $this->interactiveReportCatalog();
        $periodOptions = $this->interactivePeriodOptions();

        $validated = $request->validate([
            'section' => ['nullable', Rule::in(array_keys($sections))],
            'report' => ['nullable', Rule::in(array_keys($catalog))],
            'report_type' => ['nullable', 'string'],
            'period' => ['nullable', Rule::in(array_keys($periodOptions))],
            'date_from' => [
                'nullable',
                'date',
                Rule::requiredIf(fn() => $request->input('period') === 'custom'),
            ],
            'date_to' => [
                'nullable',
                'date',
                Rule::requiredIf(fn() => $request->input('period') === 'custom'),
                'after_or_equal:date_from',
            ],
        ]);

        $selectedPeriod = $validated['period'] ?? config('accounting.reports.default_period', 'monthly');
        [$dateFrom, $dateTo] = $this->resolveReportRange(
            $selectedPeriod,
            $validated['date_from'] ?? null,
            $validated['date_to'] ?? null,
        );

        $initialReportKey = $validated['report']
            ?? $this->legacyReportTypeToInteractiveKey($validated['report_type'] ?? null)
            ?? 'sales_by_invoice';

        if (!array_key_exists($initialReportKey, $catalog)) {
            $initialReportKey = 'sales_by_invoice';
        }

        $initialSection = $validated['section'] ?? ($catalog[$initialReportKey]['section'] ?? 'sales');

        if (!array_key_exists($initialSection, $sections)) {
            $initialSection = $catalog[$initialReportKey]['section'] ?? 'sales';
        }

        $stats = $this->reportSummaryStats($company->id, $dateFrom, $dateTo);
        $openPayables = (float) Purchase::where('company_id', $company->id)->sum('balance_due');
        $netVat = (float) Invoice::where('company_id', $company->id)
            ->whereIn('status', ['sent', 'partial', 'paid'])
            ->whereBetween('invoice_date', [$dateFrom->toDateString(), $dateTo->toDateString()])
            ->sum('tax_amount')
            - (
                (float) Purchase::where('company_id', $company->id)
                    ->whereIn('status', ['approved', 'partial', 'paid'])
                    ->whereBetween('purchase_date', [$dateFrom->toDateString(), $dateTo->toDateString()])
                    ->sum('tax_amount')
                + (float) Expense::where('company_id', $company->id)
                    ->whereBetween('expense_date', [$dateFrom->toDateString(), $dateTo->toDateString()])
                    ->sum('tax_amount')
            );

        $sectionCounts = collect($catalog)
            ->groupBy('section')
            ->map(fn(Collection $items) => $items->count())
            ->all();

        return view('reports', [
            'company' => $company,
            'sections' => $sections,
            'sectionCounts' => $sectionCounts,
            'reportCatalog' => $catalog,
            'periodOptions' => $periodOptions,
            'selectedPeriod' => $selectedPeriod,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'initialSection' => $initialSection,
            'initialReportKey' => $initialReportKey,
            'dashboardStats' => [
                ['label' => 'إيرادات الفترة', 'value' => (float) $stats['total_revenue'], 'icon' => 'fa-sack-dollar', 'tone' => 'primary'],
                ['label' => 'صافي الربح', 'value' => (float) $stats['net_profit'], 'icon' => 'fa-chart-line', 'tone' => 'success'],
                ['label' => 'الذمم الدائنة', 'value' => $openPayables, 'icon' => 'fa-file-invoice-dollar', 'tone' => 'warning'],
                ['label' => 'صافي الضريبة', 'value' => $netVat, 'icon' => 'fa-percent', 'tone' => 'accent'],
            ],
        ]);
    }

    public function reportShow(Request $request, string $report): View
    {
        $company = $this->company($request);
        $catalog = $this->interactiveReportCatalog();
        $periodOptions = $this->interactivePeriodOptions();

        abort_unless(array_key_exists($report, $catalog), 404);

        $validated = $request->validate([
            'period' => ['nullable', Rule::in(array_keys($periodOptions))],
            'date_from' => [
                'nullable',
                'date',
                Rule::requiredIf(fn() => $request->input('period') === 'custom'),
            ],
            'date_to' => [
                'nullable',
                'date',
                Rule::requiredIf(fn() => $request->input('period') === 'custom'),
                'after_or_equal:date_from',
            ],
        ]);

        $selectedPeriod = $validated['period'] ?? config('accounting.reports.default_period', 'monthly');
        [$dateFrom, $dateTo] = $this->resolveReportRange(
            $selectedPeriod,
            $validated['date_from'] ?? null,
            $validated['date_to'] ?? null,
        );

        $reportPayload = $this->buildInteractiveReportResponse($company, $report, $dateFrom, $dateTo);

        if ($request->boolean('print')) {
            return view('reports_show_print', [
                'company' => $company,
                'reportKey' => $report,
                'reportMeta' => $catalog[$report],
                'reportPayload' => $reportPayload,
                'periodOptions' => $periodOptions,
                'selectedPeriod' => $selectedPeriod,
                'dateFrom' => $dateFrom,
                'dateTo' => $dateTo,
                'printMode' => true,
            ]);
        }

        return view('reports_show', [
            'company' => $company,
            'reportKey' => $report,
            'reportMeta' => $catalog[$report],
            'reportPayload' => $reportPayload,
            'printReportType' => $this->interactiveKeyToLegacyReportType($report),
            'periodOptions' => $periodOptions,
            'selectedPeriod' => $selectedPeriod,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
        ]);
    }

    public function reportQuery(Request $request): JsonResponse
    {
        $company = $this->company($request);
        $catalog = $this->interactiveReportCatalog();
        $periodOptions = $this->interactivePeriodOptions();

        $validated = $request->validate([
            'report' => ['required', Rule::in(array_keys($catalog))],
            'period' => ['nullable', Rule::in(array_keys($periodOptions))],
            'date_from' => [
                'nullable',
                'date',
                Rule::requiredIf(fn() => $request->input('period') === 'custom'),
            ],
            'date_to' => [
                'nullable',
                'date',
                Rule::requiredIf(fn() => $request->input('period') === 'custom'),
                'after_or_equal:date_from',
            ],
        ]);

        $selectedPeriod = $validated['period'] ?? config('accounting.reports.default_period', 'monthly');
        [$dateFrom, $dateTo] = $this->resolveReportRange(
            $selectedPeriod,
            $validated['date_from'] ?? null,
            $validated['date_to'] ?? null,
        );

        return response()->json($this->buildInteractiveReportResponse($company, $validated['report'], $dateFrom, $dateTo));
    }

    private function legacyReportsViewData(Request $request, Company $company): array
    {
        $reportTypes = [
            'income_statement' => ['label' => 'قائمة الدخل', 'description' => 'مقارنة الإيرادات بالمشتريات والمصروفات للوصول إلى صافي الربح.', 'focus' => null],
            'account_balances' => ['label' => 'أرصدة الحسابات', 'description' => 'عرض حركة وأرصدة شجرة الحسابات أو حساب محدد.', 'focus' => 'account'],
            'product_sales' => ['label' => 'مبيعات المنتجات', 'description' => 'تحليل مبيعات كل المنتجات أو منتج محدد خلال فترة معينة.', 'focus' => 'product'],
            'expense_details' => ['label' => 'تفاصيل المصروفات', 'description' => 'تقرير بالمصروفات المسجلة أو مصروف محدد بالتفصيل.', 'focus' => 'expense'],
            'receivables' => ['label' => 'الذمم المدينة', 'description' => 'أرصدة العملاء المستحقة أو عميل محدد.', 'focus' => 'customer'],
            'payables' => ['label' => 'الذمم الدائنة', 'description' => 'أرصدة الموردين المستحقة أو مورد محدد.', 'focus' => 'supplier'],
            'tax_summary' => ['label' => 'تقرير الضرائب', 'description' => 'ملخص ضريبة المخرجات وضريبة المدخلات وصافي الالتزام الضريبي خلال الفترة.', 'focus' => null],
        ];

        $periodOptions = $this->interactivePeriodOptions();

        $validated = $request->validate([
            'report_type' => ['nullable', Rule::in(array_keys($reportTypes))],
            'period' => ['nullable', Rule::in(array_keys($periodOptions))],
            'date_from' => ['nullable', 'date', Rule::requiredIf(fn() => $request->input('period') === 'custom')],
            'date_to' => ['nullable', 'date', Rule::requiredIf(fn() => $request->input('period') === 'custom'), 'after_or_equal:date_from'],
            'account_id' => ['nullable', Rule::exists('accounts', 'id')->where(fn($query) => $query->where('company_id', $company->id))],
            'product_id' => ['nullable', Rule::exists('products', 'id')->where(fn($query) => $query->where('company_id', $company->id))],
            'expense_id' => ['nullable', Rule::exists('expenses', 'id')->where(fn($query) => $query->where('company_id', $company->id))],
            'customer_id' => ['nullable', Rule::exists('customers', 'id')->where(fn($query) => $query->where('company_id', $company->id))],
            'supplier_id' => ['nullable', Rule::exists('suppliers', 'id')->where(fn($query) => $query->where('company_id', $company->id))],
            'print' => ['nullable', 'boolean'],
        ]);

        $selectedReportType = $validated['report_type'] ?? 'income_statement';
        $selectedPeriod = $validated['period'] ?? config('accounting.reports.default_period', 'monthly');
        [$dateFrom, $dateTo] = $this->resolveReportRange($selectedPeriod, $validated['date_from'] ?? null, $validated['date_to'] ?? null);

        $accounts = Account::where('company_id', $company->id)->orderBy('code')->get(['id', 'code', 'name', 'account_type']);
        $products = Product::forCompany($company->id)->active()->orderBy('name')->get(['id', 'name', 'code']);
        $expenses = Expense::where('company_id', $company->id)->orderByDesc('expense_date')->get(['id', 'name', 'reference', 'expense_date', 'total']);
        $customers = Customer::where('company_id', $company->id)->orderBy('name')->get(['id', 'name']);
        $suppliers = Supplier::where('company_id', $company->id)->orderBy('name')->get(['id', 'name']);

        $stats = $this->reportSummaryStats($company->id, $dateFrom, $dateTo);
        $companyCountry = $this->countryConfigForCompany($company);
        $report = $this->buildReportData($company, $selectedReportType, $validated, $dateFrom, $dateTo, $reportTypes);

        return [
            'company' => $company,
            'companyCountry' => $companyCountry,
            'stats' => $stats,
            'reportRows' => $report['rows'],
            'report' => $report,
            'reportTypes' => $reportTypes,
            'periodOptions' => $periodOptions,
            'selectedReportType' => $selectedReportType,
            'selectedPeriod' => $selectedPeriod,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'accounts' => $accounts,
            'products' => $products,
            'expenses' => $expenses,
            'customers' => $customers,
            'suppliers' => $suppliers,
            'selectedAccountId' => isset($validated['account_id']) ? (int) $validated['account_id'] : null,
            'selectedProductId' => isset($validated['product_id']) ? (int) $validated['product_id'] : null,
            'selectedExpenseId' => isset($validated['expense_id']) ? (int) $validated['expense_id'] : null,
            'selectedCustomerId' => isset($validated['customer_id']) ? (int) $validated['customer_id'] : null,
            'selectedSupplierId' => isset($validated['supplier_id']) ? (int) $validated['supplier_id'] : null,
            'printMode' => $request->boolean('print'),
        ];
    }

    private function interactiveReportSections(): array
    {
        return [
            'favorites' => ['label' => 'المفضلة', 'icon' => 'fa-star', 'summary' => 'الوصول السريع إلى التقارير التي تستخدمها أكثر.'],
            'sales' => ['label' => 'المبيعات', 'icon' => 'fa-receipt', 'summary' => 'تقارير الأداء البيعي والفواتير والعملاء وحالات الدفع.'],
            'inventory' => ['label' => 'المخزون', 'icon' => 'fa-boxes-stacked', 'summary' => 'رؤية فورية للمخزون والحركة والمنتجات الأسرع دوراناً.'],
            'taxes' => ['label' => 'الضرائب', 'icon' => 'fa-percent', 'summary' => 'ملخصات الضريبة ومصادرها من المبيعات والمشتريات.'],
            'warehouse' => ['label' => 'المخزن', 'icon' => 'fa-warehouse', 'summary' => 'استلامات الموردين وتغطية الحد الأدنى ومستوى الجاهزية.'],
            'finance' => ['label' => 'المالية', 'icon' => 'fa-wallet', 'summary' => 'قائمة الدخل والذمم والمصروفات وأرصدة الحسابات.'],
        ];
    }

    private function interactivePeriodOptions(): array
    {
        return [
            'monthly' => 'هذا الشهر',
            'quarterly' => 'هذا الربع',
            'yearly' => 'هذه السنة',
            'custom' => 'فترة مخصصة',
        ];
    }

    private function interactiveReportCatalog(): array
    {
        return [
            'sales_by_location' => ['section' => 'sales', 'icon' => 'fa-map-location-dot', 'title' => 'تقرير المبيعات لكل موقع', 'description' => 'تجميع المبيعات حسب الفروع والمواقع التشغيلية الفعلية داخل الشركة.', 'query_preview' => "SELECT b.name AS branch, SUM(s.total_amount) AS total_sales\nFROM sales s\nJOIN branches b ON s.branch_id = b.id\nGROUP BY b.name;"],
            'sales_by_invoice' => ['section' => 'sales', 'icon' => 'fa-file-invoice-dollar', 'title' => 'تقرير المبيعات من كل فاتورة', 'description' => 'عرض تفصيلي لكل عملية بيع على مستوى المستند وقيمتها والضريبة المرتبطة بها.', 'query_preview' => "SELECT sale_number, total_amount, tax_amount, sale_date\nFROM sales\nWHERE company_id = ?\nORDER BY sale_date DESC;"],
            'sales_by_category' => ['section' => 'sales', 'icon' => 'fa-layer-group', 'title' => 'المبيعات حسب الفئات', 'description' => 'تحليل المبيعات حسب فئات المنتجات المعتمدة في قاعدة البيانات.', 'query_preview' => "SELECT c.name, SUM(si.total_amount) AS total_sales\nFROM sales_items si\nJOIN categories c ON si.category_id = c.id\nJOIN sales s ON si.sale_id = s.id\nGROUP BY c.name;"],
            'sales_by_employee' => ['section' => 'sales', 'icon' => 'fa-user-tie', 'title' => 'تقرير المبيعات من كل موظف', 'description' => 'تجميع عمليات البيع حسب الموظف المسؤول عن العملية عند وجود الربط.', 'query_preview' => "SELECT e.first_name, e.last_name, SUM(s.total_amount) AS total_sales\nFROM sales s\nLEFT JOIN employees e ON s.employee_id = e.id\nGROUP BY e.id, e.first_name, e.last_name;"],
            'sales_by_payment_status' => ['section' => 'sales', 'icon' => 'fa-credit-card', 'title' => 'تقرير المبيعات لكل حالة دفع', 'description' => 'تحليل حجم المبيعات حسب حالة السداد الحالية والرصيد المتبقي.', 'query_preview' => "SELECT payment_status, SUM(total_amount)\nFROM sales\nGROUP BY payment_status;"],
            'customer_transactions' => ['section' => 'sales', 'icon' => 'fa-arrows-rotate', 'title' => 'تقرير معاملات العملاء', 'description' => 'عدد المعاملات والإجمالي والمتبقي لكل عميل خلال الفترة المحددة.', 'query_preview' => "SELECT c.name, s.id, s.total_amount, s.sale_date\nFROM sales s\nJOIN customers c ON s.customer_id = c.id;"],
            'sales_by_channel' => ['section' => 'sales', 'icon' => 'fa-store', 'title' => 'تقرير المبيعات من كل قناة بيع', 'description' => 'تجميع الإيرادات حسب قنوات البيع المسجلة على كل عملية.', 'query_preview' => "SELECT sc.name, SUM(s.total_amount)\nFROM sales s\nJOIN sales_channels sc ON s.channel_id = sc.id\nGROUP BY sc.name;"],
            'sales_by_customer' => ['section' => 'sales', 'icon' => 'fa-users', 'title' => 'تقرير المبيعات من كل عميل', 'description' => 'إجمالي مبيعات كل عميل ومتوسط قيمة العملية وعدد العمليات.', 'query_preview' => "SELECT c.name, SUM(s.total_amount)\nFROM sales s\nJOIN customers c ON s.customer_id = c.id\nGROUP BY c.name;"],
            'transactions_by_branch' => ['section' => 'sales', 'icon' => 'fa-building-circle-arrow-right', 'title' => 'تقرير المعاملات في كل موقع', 'description' => 'حصر عدد معاملات البيع المنفذة في كل فرع أو موقع تشغيل.', 'query_preview' => "SELECT b.name, COUNT(s.id) AS total_transactions\nFROM sales s\nJOIN branches b ON s.branch_id = b.id\nGROUP BY b.name;"],
            'customer_product_sales' => ['section' => 'sales', 'icon' => 'fa-bag-shopping', 'title' => 'تقرير المنتجات المباعة للعميل', 'description' => 'تتبع المنتجات والكميات المباعة لكل عميل داخل الفترة المحددة.', 'query_preview' => "SELECT c.name, si.product_id, SUM(si.quantity)\nFROM sales_items si\nJOIN sales s ON si.sale_id = s.id\nJOIN customers c ON s.customer_id = c.id\nGROUP BY c.name, si.product_id;"],
            'sales_by_period' => ['section' => 'sales', 'icon' => 'fa-calendar-days', 'title' => 'تقرير المبيعات حسب الفترة الزمنية', 'description' => 'تجميع المبيعات زمنياً لمراقبة الاتجاهات اليومية داخل الفترة.', 'query_preview' => "SELECT DATE(sale_date) AS day, SUM(total_amount)\nFROM sales\nGROUP BY DATE(sale_date)\nORDER BY DATE(sale_date);"],
            'customer_statement' => ['section' => 'sales', 'icon' => 'fa-file-lines', 'title' => 'كشف حساب مدين', 'description' => 'إجمالي المبيعات والمدفوعات والمتبقي لكل عميل لعرض كشف حساب المدين.', 'query_preview' => "SELECT c.name, s.total_amount, p.amount AS paid, (s.total_amount - COALESCE(p.amount, 0)) AS remaining\nFROM sales s\nLEFT JOIN payments p ON s.id = p.invoice_id\nJOIN customers c ON s.customer_id = c.id;"],
            'product_tracking' => ['section' => 'inventory', 'icon' => 'fa-crosshairs', 'title' => 'استعلامات تتبع المنتج', 'description' => 'مراقبة المنتجات المتتبعة في نظامك من خلال متابعة معلومات مثل الموقع، حالة المنتج، تاريخ الانتهاء وغيرها من المعلومات.', 'query_preview' => "SELECT p.name, p.location, p.status, p.stock_quantity, b.expiry_date\nFROM products p\nLEFT JOIN product_batches b ON b.product_id = p.id\nWHERE p.company_id = ? AND p.type = 'product';"],
            'product_performance' => ['section' => 'inventory', 'icon' => 'fa-chart-line', 'title' => 'تقرير أداء المنتج', 'description' => 'عرض مبيعات كل منتج في النظام على حدة حتى تتمكن من معرفة المنتجات الأفضل أداءاً والأكثر تحقيقاً للأرباح.', 'query_preview' => "SELECT p.name, SUM(ii.quantity) AS qty, SUM(ii.total) AS sales, SUM((ii.price - p.cost_price) * ii.quantity) AS profit\nFROM products p\nJOIN invoice_items ii ON ii.product_id = p.id\nJOIN invoices i ON i.id = ii.invoice_id\nWHERE i.company_id = ? AND i.status = 'completed'\nGROUP BY p.id;"],
            'product_movements' => ['section' => 'inventory', 'icon' => 'fa-dolly', 'title' => 'حركة المنتجات', 'description' => 'تعرف على حركة منتجات مخزونك علماً بانها يتم ادراجها في تقارير المخزون.', 'query_preview' => "SELECT sm.created_at, p.name, sm.type, sm.quantity, sm.reference_type, w.name AS warehouse\nFROM stock_movements sm\nJOIN products p ON p.id = sm.product_id\nLEFT JOIN warehouses w ON w.id = sm.warehouse_id\nWHERE sm.company_id = ?\nORDER BY sm.created_at DESC;"],
            'tax_return' => ['section' => 'taxes', 'icon' => 'fa-file-contract', 'title' => 'الإقرار الضريبي', 'description' => 'ملخص شامل لكل ما يتعلق بالضرائب في النظام، يساعدك على البقاء متوافقا مع متطلبات هيئة الزكاة والدخل والإفصاح عن تقريرك الضريبي بكل سهولة.', 'query_preview' => "SELECT tax_type, SUM(tax_amount) AS tax\nFROM invoices\nWHERE company_id = ? AND status = 'completed'\nGROUP BY tax_type;\n\nSELECT tax_type, SUM(tax_amount) AS tax\nFROM purchases\nWHERE company_id = ? AND status = 'completed'\nGROUP BY tax_type;"],
            'warehouse_supplier_transactions' => ['section' => 'warehouse', 'icon' => 'fa-truck-field', 'title' => 'تقرير معاملات الموردين', 'description' => 'تتبع العمليات التي قام بها الموردون في متجرك.', 'query_preview' => '-- استعلام معاملات الموردين'],
            'warehouse_supplier_payables' => ['section' => 'warehouse', 'icon' => 'fa-file-invoice-dollar', 'title' => 'مستحقات للموردين', 'description' => 'كشف حساب دائن شامل لكل الموردين يشمل العمليات والمبالغ المستحقة لكل فترة.', 'query_preview' => '-- استعلام مستحقات الموردين'],
            'warehouse_inventory_audit' => ['section' => 'warehouse', 'icon' => 'fa-clipboard-check', 'title' => 'تقرير ملخص جرد المخزون', 'description' => 'إحصائيات عن عمليات جرد المخزون وتأثيرها على الكميات في المخزن.', 'query_preview' => '-- استعلام جرد المخزون'],
            'warehouse_purchase_summary' => ['section' => 'warehouse', 'icon' => 'fa-cart-shopping', 'title' => 'ملخص فواتير المشتريات', 'description' => 'ملخص لعمليات المشتريات وقيمتها وكمياتها لتسهيل القرارات المستقبلية.', 'query_preview' => '-- استعلام ملخص المشتريات'],
            'warehouse_purchase_details' => ['section' => 'warehouse', 'icon' => 'fa-receipt', 'title' => 'تفاصيل فاتورة الشراء', 'description' => 'تقرير تفصيلي يشمل التاريخ والكميات وبيانات تدعم اتخاذ قرار الشراء.', 'query_preview' => '-- استعلام تفاصيل الشراء'],
            'warehouse_supplier_summary' => ['section' => 'warehouse', 'icon' => 'fa-address-book', 'title' => 'تقرير ملخص معاملات الموردين', 'description' => 'نظرة عامة على الموردين، بيانات التواصل، وإجمالي المعاملات والكميات.', 'query_preview' => '-- استعلام ملخص الموردين'],
            'warehouse_supplier_receivables' => ['section' => 'warehouse', 'icon' => 'fa-hand-holding-dollar', 'title' => 'مستحقات من الموردين', 'description' => 'كشف حساب مدين شامل لكل الموردين يشمل العمليات والمبالغ المستحقة.', 'query_preview' => '-- استعلام مستحقات من الموردين'],
            'warehouse_cost_change' => ['section' => 'warehouse', 'icon' => 'fa-chart-line', 'title' => 'التغير في سعر التكلفة', 'description' => 'ملخص لتغييرات تكلفة المنتج عبر الزمن وتأثيرها على صافي الربح.', 'query_preview' => '-- استعلام تغيير التكلفة'],
            'finance_balance_sheet' => ['section' => 'finance', 'icon' => 'fa-scale-balanced', 'title' => 'قائمة المركز المالي', 'description' => 'هي قائمة تلخص أصول، وخصوم، وحقوق الملكية للمنشأة في تاريخ معين.', 'query_preview' => '-- استعلام قائمة المركز المالي'],
            'finance_trial_balance' => ['section' => 'finance', 'icon' => 'fa-scale-unbalanced', 'title' => 'ميزان المراجعة', 'description' => 'هو كشف تظهر فيه مجاميع الأطراف المدينة ومجموع الأطراف الدائنة للحسابات.', 'query_preview' => '-- استعلام ميزان المراجعة'],
            'finance_expense_categories' => ['section' => 'finance', 'icon' => 'fa-chart-pie', 'title' => 'تقرير فئات المصروفات', 'description' => 'تقرير أعلى المصروفات حسب الفئات خلال فترة زمنية محددة.', 'query_preview' => '-- استعلام فئات المصروفات'],
            'finance_income_statement' => ['section' => 'finance', 'icon' => 'fa-file-invoice-dollar', 'title' => 'قائمة الدخل', 'description' => 'هي قائمة تظهر ربحية الشركة خلال فترة زمنية معينة، وتتكون من إيرادات ومصروفات المنشأة.', 'query_preview' => '-- استعلام قائمة الدخل'],
            'finance_account_statements' => ['section' => 'finance', 'icon' => 'fa-file-lines', 'title' => 'كشوف الحسابات', 'description' => 'هو عبارة عن تقرير وميزان مالي يوضح وضع الحساب. فإما أن يكون دائنًا أو مدينًا.', 'query_preview' => '-- استعلام كشوف الحسابات'],
            'finance_general_ledger' => ['section' => 'finance', 'icon' => 'fa-book', 'title' => 'دفتر الأستاذ العام', 'description' => 'سجل محاسبي يشتمل على جميع تفاصيل حسابات الشركة وحركاتها المالية.', 'query_preview' => '-- استعلام دفتر الأستاذ العام'],
            'finance_wallet_transactions' => ['section' => 'finance', 'icon' => 'fa-wallet', 'title' => 'معاملات المحفظة', 'description' => 'توفير معلومات عن التغيرات التاريخية في النقد ومعدلات التدفقات النقدية.', 'query_preview' => '-- استعلام معاملات المحفظة'],
        ];
    }

    private function legacyReportTypeToInteractiveKey(?string $legacyReportType): ?string
    {
        return match ($legacyReportType) {
            'income_statement' => 'finance_income_statement',
            'account_balances' => 'finance_general_ledger',
            'product_sales' => 'sales_by_category',
            'expense_details' => 'finance_expense_categories',
            'receivables' => 'customer_statement',
            'payables' => 'warehouse_supplier_payables',
            'tax_summary' => 'tax_summary_live',
            default => null,
        };
    }

    private function interactiveKeyToLegacyReportType(string $interactiveKey): string
    {
        return match ($interactiveKey) {
            'finance_income_statement' => 'income_statement',
            'finance_general_ledger' => 'account_balances',
            'finance_expense_categories' => 'expense_details',
            'finance_wallet_transactions' => 'cash_flow',
            'finance_balance_sheet' => 'balance_sheet',
            'finance_trial_balance' => 'trial_balance',
            'finance_account_statements' => 'receivables',
            'sales_by_category', 'sales_by_location', 'sales_by_invoice', 'sales_by_customer', 'sales_by_payment_status', 'customer_transactions', 'sales_by_employee', 'sales_by_channel', 'transactions_by_branch', 'customer_product_sales', 'sales_by_period' => 'product_sales',
            'customer_statement' => 'receivables',
            'warehouse_supplier_payables', 'warehouse_purchase_summary', 'warehouse_purchase_details' => 'payables',
            default => 'income_statement',
        };
    }

    private function buildInteractiveReportResponse(Company $company, string $reportKey, Carbon $dateFrom, Carbon $dateTo): array
    {
        $catalog = $this->interactiveReportCatalog();
        $meta = $catalog[$reportKey];

        $report = match ($reportKey) {
            'sales_by_location' => $this->salesByLocationInteractiveReport($company, $dateFrom, $dateTo),
            'sales_by_invoice' => $this->salesByInvoiceInteractiveReport($company, $dateFrom, $dateTo),
            'sales_by_category' => $this->salesByCategoryInteractiveReport($company, $dateFrom, $dateTo),
            'sales_by_employee' => $this->salesByEmployeeInteractiveReport($company, $dateFrom, $dateTo),
            'sales_by_payment_status' => $this->salesByPaymentStatusInteractiveReport($company, $dateFrom, $dateTo),
            'customer_transactions' => $this->customerTransactionsInteractiveReport($company, $dateFrom, $dateTo),
            'sales_by_channel' => $this->salesByChannelInteractiveReport($company, $dateFrom, $dateTo),
            'sales_by_customer' => $this->salesByCustomerInteractiveReport($company, $dateFrom, $dateTo),
            'transactions_by_branch' => $this->transactionsByBranchInteractiveReport($company, $dateFrom, $dateTo),
            'customer_product_sales' => $this->customerProductSalesInteractiveReport($company, $dateFrom, $dateTo),
            'sales_by_period' => $this->salesByPeriodInteractiveReport($company, $dateFrom, $dateTo),
            'customer_statement' => $this->customerStatementInteractiveReport($company, $dateFrom, $dateTo),
            'tax_return' => $this->taxReturnInteractiveReport($company, $dateFrom, $dateTo),
            'warehouse_coverage' => $this->warehouseCoverageInteractiveReport($company),
            'warehouse_incoming' => $this->warehouseIncomingInteractiveReport($company, $dateFrom, $dateTo),
            'warehouse_suppliers' => $this->warehouseSuppliersInteractiveReport($company, $dateFrom, $dateTo),
            'finance_income_statement' => $this->interactiveFromLegacy($this->incomeStatementReport($company, $dateFrom, $dateTo)),
            'finance_receivables' => $this->interactiveFromLegacy($this->receivablesReport($company, [], $dateFrom, $dateTo)),
            'finance_payables' => $this->interactiveFromLegacy($this->payablesReport($company, [], $dateFrom, $dateTo)),
            'finance_expenses' => $this->interactiveFromLegacy($this->expenseDetailsReport($company, [], $dateFrom, $dateTo)),
            default => $this->interactiveFromLegacy($this->accountBalancesReport($company, [], $dateFrom, $dateTo)),
        };

        return array_merge($report, [
            'key' => $reportKey,
            'section' => $meta['section'],
            'title' => $meta['title'],
            'description' => $meta['description'],
            'query_preview' => $meta['query_preview'],
            'value_format' => $report['value_format'] ?? 'currency',
            'date_range_label' => sprintf('من %s إلى %s', $dateFrom->format('Y-m-d'), $dateTo->format('Y-m-d')),
            'columns' => $report['columns'] ?? [
                ['key' => 'label', 'label' => 'البند'],
                ['key' => 'meta', 'label' => 'التفاصيل'],
                ['key' => 'value', 'label' => 'القيمة', 'format' => 'currency'],
            ],
        ]);
    }

    private function interactiveFromLegacy(array $report): array
    {
        return [
            'supported' => true,
            'rows' => $report['rows'],
            'chart' => $report['chart'],
            'highlights' => $report['highlights'],
            'value_format' => 'currency',
            'empty_message' => $report['empty_message'],
            'insight' => $report['rows']->isNotEmpty() ? 'أعلى بند حالياً: ' . $report['rows']->first()['label'] : $report['empty_message'],
        ];
    }

    private function salesByLocationInteractiveReport(Company $company, Carbon $dateFrom, Carbon $dateTo): array
    {
        $rows = DB::table('invoices as i')
            ->leftJoin('branches as b', 'b.id', '=', 'i.branch_id')
            ->leftJoin('invoice_items as ii', 'ii.invoice_id', '=', 'i.id')
            ->leftJoin('products as p', 'p.id', '=', 'ii.product_id')
            ->where('i.company_id', $company->id)
            ->whereIn('i.status', ['sent', 'partial', 'paid'])
            ->whereBetween('i.invoice_date', [$dateFrom->toDateString(), $dateTo->toDateString()])
            ->selectRaw("COALESCE(NULLIF(b.name, ''), 'غير محدد') as location_name, COALESCE(SUM(ii.total - ii.tax_amount), 0) as net_sales, COALESCE(SUM(ii.tax_amount), 0) as tax_amount, COALESCE(SUM(ii.total), 0) as gross_sales, COALESCE(SUM(ii.quantity), 0) as sold_qty, COALESCE(SUM(COALESCE(p.cost_price, 0) * ii.quantity), 0) as cogs")
            ->groupBy('location_name')
            ->orderByDesc('gross_sales')
            ->get()
            ->map(function ($row) {
                $profit = (float) $row->net_sales - (float) $row->cogs;

                return [
                    'location' => $row->location_name,
                    'sales' => (float) $row->net_sales,
                    'cogs' => (float) $row->cogs,
                    'profit' => $profit,
                    'sales_tax' => (float) $row->tax_amount,
                    'sales_with_tax' => (float) $row->gross_sales,
                    'sold_quantity' => (float) $row->sold_qty,
                    'returned_quantity' => 0,
                    'label' => $row->location_name,
                    'value' => (float) $row->gross_sales,
                    'format' => 'currency',
                ];
            });

        $report = $this->interactiveCollectionReport($rows, 'لا توجد بيانات مبيعات موزعة على مواقع خلال الفترة المحددة.');
        $report['columns'] = [
            ['key' => 'location', 'label' => 'الموقع', 'format' => 'text'],
            ['key' => 'sales', 'label' => 'المبيعات', 'format' => 'currency'],
            ['key' => 'cogs', 'label' => 'تكلفة البضاعة المباعة', 'format' => 'currency'],
            ['key' => 'profit', 'label' => 'قيمة الربح', 'format' => 'currency'],
            ['key' => 'sales_tax', 'label' => 'ضريبة المبيعات', 'format' => 'currency'],
            ['key' => 'sales_with_tax', 'label' => 'المبيعات (شاملة الضريبة)', 'format' => 'currency'],
            ['key' => 'sold_quantity', 'label' => 'الكمية المباعة', 'format' => 'number'],
            ['key' => 'returned_quantity', 'label' => 'الكمية المرتجعة', 'format' => 'number'],
        ];

        return $report;
    }

    private function salesByInvoiceInteractiveReport(Company $company, Carbon $dateFrom, Carbon $dateTo): array
    {
        $summary = DB::table('invoices as i')
            ->leftJoin('invoice_items as ii', 'ii.invoice_id', '=', 'i.id')
            ->leftJoin('products as p', 'p.id', '=', 'ii.product_id')
            ->where('i.company_id', $company->id)
            ->whereIn('i.status', ['sent', 'partial', 'paid'])
            ->whereBetween('i.invoice_date', [$dateFrom->toDateString(), $dateTo->toDateString()])
            ->selectRaw('COUNT(DISTINCT i.id) as invoice_count, COALESCE(SUM(i.subtotal), 0) as total_sales, COALESCE(AVG(i.subtotal), 0) as average_invoice_sales, COALESCE(SUM(i.total), 0) as sales_with_tax, COALESCE(SUM(COALESCE(p.cost_price, 0) * ii.quantity), 0) as cogs, COALESCE(SUM(ii.quantity), 0) as sold_qty, COALESCE(SUM(ii.tax_amount), 0) as total_tax')
            ->first();

        $rows = collect();

        if ($summary && (int) $summary->invoice_count > 0) {
            $profit = (float) $summary->total_sales - (float) $summary->cogs;
            $rows->push([
                'total_sales' => (float) $summary->total_sales,
                'total_invoices' => (int) $summary->invoice_count,
                'average_invoice_sales' => (float) $summary->average_invoice_sales,
                'sales_with_tax' => (float) $summary->sales_with_tax,
                'total_cogs' => (float) $summary->cogs,
                'total_profit' => $profit,
                'total_sold_qty' => (float) $summary->sold_qty,
                'total_returned_qty' => 0,
                'vat_15' => (float) $summary->total_tax,
                'vat_100' => 0,
                'other_taxes' => 0,
                'total_tax' => (float) $summary->total_tax,
                'label' => 'ملخص الفواتير',
                'value' => (float) $summary->sales_with_tax,
                'format' => 'currency',
            ]);
        }

        $report = $this->interactiveCollectionReport($rows, 'لا توجد فواتير مبيعات ضمن الفترة المحددة.');
        $report['columns'] = [
            ['key' => 'total_sales', 'label' => 'إجمالي المبيعات', 'format' => 'currency'],
            ['key' => 'total_invoices', 'label' => 'إجمالي الفواتير', 'format' => 'number'],
            ['key' => 'average_invoice_sales', 'label' => 'متوسط مبيعات الفواتير', 'format' => 'currency'],
            ['key' => 'sales_with_tax', 'label' => 'المبيعات (شاملة الضريبة)', 'format' => 'currency'],
            ['key' => 'total_cogs', 'label' => 'إجمالي تكلفة البضاعة المباعة', 'format' => 'currency'],
            ['key' => 'total_profit', 'label' => 'إجمالي قيمة الربح', 'format' => 'currency'],
            ['key' => 'total_sold_qty', 'label' => 'إجمالي الكميات المباعة', 'format' => 'number'],
            ['key' => 'total_returned_qty', 'label' => 'إجمالي الكميات المرتجعة', 'format' => 'number'],
            ['key' => 'vat_15', 'label' => 'ضريبة المبيعات %15', 'format' => 'currency'],
            ['key' => 'vat_100', 'label' => 'ضريبة المبيعات %100', 'format' => 'currency'],
            ['key' => 'other_taxes', 'label' => 'الضرائب الأخرى', 'format' => 'currency'],
            ['key' => 'total_tax', 'label' => 'إجمالي الضريبة', 'format' => 'currency'],
        ];

        return $report;
    }

    private function salesByCategoryInteractiveReport(Company $company, Carbon $dateFrom, Carbon $dateTo): array
    {
        $summary = DB::table('invoice_items as ii')
            ->join('invoices as i', 'i.id', '=', 'ii.invoice_id')
            ->leftJoin('categories as c', 'c.id', '=', 'ii.category_id')
            ->leftJoin('products as p', 'p.id', '=', 'ii.product_id')
            ->where('i.company_id', $company->id)
            ->whereIn('i.status', ['sent', 'partial', 'paid'])
            ->whereBetween('i.invoice_date', [$dateFrom->toDateString(), $dateTo->toDateString()])
            ->selectRaw('COUNT(DISTINCT c.id) as category_count, COALESCE(SUM(ii.total - ii.tax_amount), 0) as total_sales, COALESCE(SUM(ii.total), 0) as sales_with_tax, COALESCE(SUM(ii.quantity), 0) as sold_qty, COALESCE(SUM(COALESCE(p.cost_price, 0) * ii.quantity), 0) as cogs')
            ->first();

        $rows = collect();

        if ($summary && ((int) $summary->category_count > 0 || (float) $summary->total_sales > 0)) {
            $averageCategorySales = (int) $summary->category_count > 0
                ? round((float) $summary->total_sales / (int) $summary->category_count, 2)
                : 0;

            $rows->push([
                'total_sales' => (float) $summary->total_sales,
                'total_categories' => (int) $summary->category_count,
                'average_category_sales' => $averageCategorySales,
                'sales_with_tax' => (float) $summary->sales_with_tax,
                'total_sold_qty' => (float) $summary->sold_qty,
                'total_returned_qty' => 0,
                'total_cogs' => (float) $summary->cogs,
                'total_profit' => round((float) $summary->total_sales - (float) $summary->cogs, 2),
                'label' => 'ملخص الفئات',
                'value' => (float) $summary->sales_with_tax,
                'format' => 'currency',
            ]);
        }

        $report = $this->interactiveCollectionReport($rows, 'لا توجد بيانات مبيعات حسب الفئات خلال الفترة المحددة.');
        $report['columns'] = [
            ['key' => 'total_sales', 'label' => 'إجمالي المبيعات', 'format' => 'currency'],
            ['key' => 'total_categories', 'label' => 'إجمالي الفئات', 'format' => 'number'],
            ['key' => 'average_category_sales', 'label' => 'متوسط مبيعات الفئات', 'format' => 'currency'],
            ['key' => 'sales_with_tax', 'label' => 'إجمالي المبيعات (شاملة الضريبة)', 'format' => 'currency'],
            ['key' => 'total_sold_qty', 'label' => 'إجمالي الكميات المباعة', 'format' => 'number'],
            ['key' => 'total_returned_qty', 'label' => 'إجمالي الكميات المرتجعة', 'format' => 'number'],
            ['key' => 'total_cogs', 'label' => 'إجمالي تكلفة البضاعة المباعة', 'format' => 'currency'],
            ['key' => 'total_profit', 'label' => 'إجمالي قيمة الربح', 'format' => 'currency'],
        ];

        return $report;
    }

    private function salesByEmployeeInteractiveReport(Company $company, Carbon $dateFrom, Carbon $dateTo): array
    {
        $rows = DB::table('invoices as i')
            ->leftJoin('employees', 'employees.id', '=', 'i.employee_id')
            ->leftJoin('branches as b', 'b.id', '=', 'i.branch_id')
            ->leftJoin('invoice_items as ii', 'ii.invoice_id', '=', 'i.id')
            ->leftJoin('products as p', 'p.id', '=', 'ii.product_id')
            ->where('i.company_id', $company->id)
            ->whereIn('i.status', ['sent', 'partial', 'paid'])
            ->whereBetween('i.invoice_date', [$dateFrom->toDateString(), $dateTo->toDateString()])
            ->groupBy('employees.id', 'employees.first_name', 'employees.last_name', 'employees.position', 'b.name')
            ->selectRaw("employees.first_name, employees.last_name, employees.position, COALESCE(NULLIF(b.name, ''), 'غير محدد') as branch_name, COALESCE(SUM(ii.total - ii.tax_amount), 0) as net_sales, COALESCE(SUM(COALESCE(p.cost_price, 0) * ii.quantity), 0) as cogs, COALESCE(SUM(ii.total), 0) as gross_sales, COALESCE(SUM(ii.quantity), 0) as sold_qty")
            ->orderByDesc('gross_sales')
            ->get()
            ->map(function ($row) {
                $fullName = trim(implode(' / ', array_filter([
                    trim(implode(' ', array_filter([$row->first_name, $row->last_name]))),
                    $row->position,
                ])));

                return [
                    'employee' => $fullName !== '' ? $fullName : 'غير محدد',
                    'location' => $row->branch_name,
                    'sales' => (float) $row->net_sales,
                    'cogs' => (float) $row->cogs,
                    'profit' => round((float) $row->net_sales - (float) $row->cogs, 2),
                    'sales_with_tax' => (float) $row->gross_sales,
                    'sold_quantity' => (float) $row->sold_qty,
                    'returned_quantity' => 0,
                    'label' => $fullName !== '' ? $fullName : 'غير محدد',
                    'value' => (float) $row->gross_sales,
                    'format' => 'currency',
                ];
            });

        $report = $this->interactiveCollectionReport($rows, 'لا توجد مبيعات مرتبطة بموظفين خلال الفترة المحددة.');
        $report['columns'] = [
            ['key' => 'employee', 'label' => 'المستخدم / الوظيفة', 'format' => 'text'],
            ['key' => 'location', 'label' => 'الموقع', 'format' => 'text'],
            ['key' => 'sales', 'label' => 'المبيعات', 'format' => 'currency'],
            ['key' => 'cogs', 'label' => 'تكلفة البضاعة المباعة', 'format' => 'currency'],
            ['key' => 'profit', 'label' => 'قيمة الربح', 'format' => 'currency'],
            ['key' => 'sales_with_tax', 'label' => 'المبيعات (شاملة الضريبة)', 'format' => 'currency'],
            ['key' => 'sold_quantity', 'label' => 'الكمية المباعة', 'format' => 'number'],
            ['key' => 'returned_quantity', 'label' => 'الكمية المرتجعة', 'format' => 'number'],
        ];

        return $report;
    }

    private function salesByPaymentStatusInteractiveReport(Company $company, Carbon $dateFrom, Carbon $dateTo): array
    {
        $labels = ['full' => 'كامل', 'partial' => 'جزئي', 'deferred' => 'آجل', 'paid' => 'كامل', 'pending' => 'آجل'];

        $rows = DB::table('invoices as i')
            ->leftJoin('branches as b', 'b.id', '=', 'i.branch_id')
            ->leftJoin('invoice_items as ii', 'ii.invoice_id', '=', 'i.id')
            ->leftJoin('products as p', 'p.id', '=', 'ii.product_id')
            ->where('i.company_id', $company->id)
            ->whereIn('i.status', ['sent', 'partial', 'paid'])
            ->whereBetween('i.invoice_date', [$dateFrom->toDateString(), $dateTo->toDateString()])
            ->selectRaw("i.payment_status as status_key, COALESCE(NULLIF(b.name, ''), 'غير محدد') as branch_name, COALESCE(SUM(ii.total - ii.tax_amount), 0) as net_sales, COALESCE(SUM(COALESCE(p.cost_price, 0) * ii.quantity), 0) as cogs, COALESCE(SUM(ii.total), 0) as gross_sales, COALESCE(SUM(ii.quantity), 0) as sold_qty")
            ->groupBy('status_key', 'branch_name')
            ->orderByDesc('gross_sales')
            ->get()
            ->map(fn($row) => [
                'payment_status' => $labels[$row->status_key] ?? $row->status_key,
                'location' => $row->branch_name,
                'sales' => (float) $row->net_sales,
                'cogs' => (float) $row->cogs,
                'profit' => round((float) $row->net_sales - (float) $row->cogs, 2),
                'sales_with_tax' => (float) $row->gross_sales,
                'sold_quantity' => (float) $row->sold_qty,
                'returned_quantity' => 0,
                'label' => $labels[$row->status_key] ?? $row->status_key,
                'value' => (float) $row->gross_sales,
                'format' => 'currency',
            ]);

        $report = $this->interactiveCollectionReport($rows, 'لا توجد حالات دفع ضمن الفترة المحددة.');
        $report['columns'] = [
            ['key' => 'payment_status', 'label' => 'حالة الدفع', 'format' => 'text'],
            ['key' => 'location', 'label' => 'الموقع', 'format' => 'text'],
            ['key' => 'sales', 'label' => 'المبيعات', 'format' => 'currency'],
            ['key' => 'cogs', 'label' => 'تكلفة البضاعة المباعة', 'format' => 'currency'],
            ['key' => 'profit', 'label' => 'قيمة الربح', 'format' => 'currency'],
            ['key' => 'sales_with_tax', 'label' => 'المبيعات (شاملة الضريبة)', 'format' => 'currency'],
            ['key' => 'sold_quantity', 'label' => 'الكمية المباعة', 'format' => 'number'],
            ['key' => 'returned_quantity', 'label' => 'الكمية المرتجعة', 'format' => 'number'],
        ];

        return $report;
    }

    private function customerTransactionsInteractiveReport(Company $company, Carbon $dateFrom, Carbon $dateTo): array
    {
        $rows = DB::table('invoices as i')
            ->join('customers', 'customers.id', '=', 'i.customer_id')
            ->leftJoin('branches as b', 'b.id', '=', 'i.branch_id')
            ->leftJoin('sales_channels as sc', 'sc.id', '=', 'i.sales_channel_id')
            ->leftJoin('employees', 'employees.id', '=', 'i.employee_id')
            ->leftJoin('invoice_items as ii', 'ii.invoice_id', '=', 'i.id')
            ->where('i.company_id', $company->id)
            ->whereIn('i.status', ['sent', 'partial', 'paid'])
            ->whereBetween('i.invoice_date', [$dateFrom->toDateString(), $dateTo->toDateString()])
            ->groupBy('i.id', 'customers.name', 'customers.phone', 'b.name', 'i.invoice_number', 'i.invoice_date', 'i.paid_amount', 'i.total', 'sc.name', 'employees.first_name', 'employees.last_name')
            ->selectRaw("customers.name as customer_name, COALESCE(customers.phone, '') as customer_phone, COALESCE(NULLIF(b.name, ''), 'غير محدد') as branch_name, i.invoice_number, i.invoice_date, COALESCE(SUM(ii.quantity), 0) as qty_per_transaction, i.paid_amount, i.total, COALESCE(NULLIF(sc.name, ''), 'غير محدد') as sales_channel_name, employees.first_name, employees.last_name")
            ->orderByDesc('i.invoice_date')
            ->get()
            ->map(function ($row) {
                $employeeName = trim(implode(' ', array_filter([$row->first_name, $row->last_name])));

                return [
                    'customer_name' => $row->customer_name,
                    'customer_phone' => $row->customer_phone,
                    'location' => $row->branch_name,
                    'transaction_reference' => $row->invoice_number,
                    'transaction_type' => 'بيع',
                    'transaction_date' => $row->invoice_date,
                    'quantity_per_transaction' => (float) $row->qty_per_transaction,
                    'paid_amount' => (float) $row->paid_amount,
                    'received_amount' => (float) $row->total,
                    'sales_channel' => $row->sales_channel_name,
                    'user_name' => $employeeName !== '' ? $employeeName : 'غير محدد',
                    'label' => $row->customer_name,
                    'value' => (float) $row->total,
                    'format' => 'currency',
                ];
            });

        $report = $this->interactiveCollectionReport($rows, 'لا توجد معاملات عملاء خلال الفترة المحددة.');
        $report['columns'] = [
            ['key' => 'customer_name', 'label' => 'اسم العميل', 'format' => 'text'],
            ['key' => 'customer_phone', 'label' => 'رقم هاتف العميل', 'format' => 'text'],
            ['key' => 'location', 'label' => 'الموقع', 'format' => 'text'],
            ['key' => 'transaction_reference', 'label' => 'مرجع العملية', 'format' => 'text'],
            ['key' => 'transaction_type', 'label' => 'نوع العملية', 'format' => 'text'],
            ['key' => 'transaction_date', 'label' => 'تاريخ العملية', 'format' => 'date'],
            ['key' => 'quantity_per_transaction', 'label' => 'الكمية لكل عملية', 'format' => 'number'],
            ['key' => 'paid_amount', 'label' => 'المبلغ المدفوع', 'format' => 'currency'],
            ['key' => 'received_amount', 'label' => 'المستلم', 'format' => 'currency'],
            ['key' => 'sales_channel', 'label' => 'قناة البيع', 'format' => 'text'],
            ['key' => 'user_name', 'label' => 'المستخدم', 'format' => 'text'],
        ];

        return $report;
    }

    private function salesByChannelInteractiveReport(Company $company, Carbon $dateFrom, Carbon $dateTo): array
    {
        $rows = DB::table('invoices as i')
            ->leftJoin('sales_channels as sc', 'sc.id', '=', 'i.sales_channel_id')
            ->leftJoin('branches as b', 'b.id', '=', 'i.branch_id')
            ->leftJoin('invoice_items as ii', 'ii.invoice_id', '=', 'i.id')
            ->leftJoin('products as p', 'p.id', '=', 'ii.product_id')
            ->where('i.company_id', $company->id)
            ->whereIn('i.status', ['sent', 'partial', 'paid'])
            ->whereBetween('i.invoice_date', [$dateFrom->toDateString(), $dateTo->toDateString()])
            ->selectRaw("COALESCE(NULLIF(sc.name, ''), 'غير محدد') as channel_name, COALESCE(NULLIF(b.name, ''), 'غير محدد') as branch_name, COALESCE(SUM(ii.total - ii.tax_amount), 0) as net_sales, COALESCE(SUM(COALESCE(p.cost_price, 0) * ii.quantity), 0) as cogs, COALESCE(SUM(ii.total), 0) as gross_sales, COALESCE(SUM(ii.quantity), 0) as sold_qty")
            ->groupBy('channel_name', 'branch_name')
            ->orderByDesc('gross_sales')
            ->get()
            ->map(fn($row) => [
                'sales_channel' => $row->channel_name,
                'location' => $row->branch_name,
                'sales' => (float) $row->net_sales,
                'cogs' => (float) $row->cogs,
                'profit' => round((float) $row->net_sales - (float) $row->cogs, 2),
                'sales_with_tax' => (float) $row->gross_sales,
                'sold_quantity' => (float) $row->sold_qty,
                'returned_quantity' => 0,
                'label' => $row->channel_name,
                'value' => (float) $row->gross_sales,
                'format' => 'currency',
            ]);

        $report = $this->interactiveCollectionReport($rows, 'لا توجد قنوات بيع مسجلة خلال الفترة المحددة.');
        $report['columns'] = [
            ['key' => 'sales_channel', 'label' => 'قناة البيع', 'format' => 'text'],
            ['key' => 'location', 'label' => 'الموقع', 'format' => 'text'],
            ['key' => 'sales', 'label' => 'المبيعات', 'format' => 'currency'],
            ['key' => 'cogs', 'label' => 'تكلفة البضاعة المباعة', 'format' => 'currency'],
            ['key' => 'profit', 'label' => 'قيمة الربح', 'format' => 'currency'],
            ['key' => 'sales_with_tax', 'label' => 'المبيعات (شاملة الضريبة)', 'format' => 'currency'],
            ['key' => 'sold_quantity', 'label' => 'الكمية المباعة', 'format' => 'number'],
            ['key' => 'returned_quantity', 'label' => 'الكمية المرتجعة', 'format' => 'number'],
        ];

        return $report;
    }

    private function salesByCustomerInteractiveReport(Company $company, Carbon $dateFrom, Carbon $dateTo): array
    {
        $rows = DB::table('invoices as i')
            ->join('customers', 'customers.id', '=', 'i.customer_id')
            ->leftJoin('branches as b', 'b.id', '=', 'i.branch_id')
            ->leftJoin('invoice_items as ii', 'ii.invoice_id', '=', 'i.id')
            ->leftJoin('products as p', 'p.id', '=', 'ii.product_id')
            ->where('i.company_id', $company->id)
            ->whereIn('i.status', ['sent', 'partial', 'paid'])
            ->whereBetween('i.invoice_date', [$dateFrom->toDateString(), $dateTo->toDateString()])
            ->selectRaw("customers.name as customer_name, COALESCE(customers.phone, '') as customer_phone, COALESCE(NULLIF(b.name, ''), 'غير محدد') as branch_name, COALESCE(SUM(ii.total - ii.tax_amount), 0) as net_sales, COALESCE(SUM(COALESCE(p.cost_price, 0) * ii.quantity), 0) as cogs, COALESCE(SUM(ii.total), 0) as gross_sales, COALESCE(SUM(ii.quantity), 0) as sold_qty")
            ->groupBy('customers.id', 'customers.name', 'customers.phone', 'branch_name')
            ->orderByDesc('gross_sales')
            ->get()
            ->map(fn($row) => [
                'customer_name' => $row->customer_name,
                'customer_phone' => $row->customer_phone,
                'location' => $row->branch_name,
                'sales' => (float) $row->net_sales,
                'cogs' => (float) $row->cogs,
                'profit' => round((float) $row->net_sales - (float) $row->cogs, 2),
                'sales_with_tax' => (float) $row->gross_sales,
                'sold_quantity' => (float) $row->sold_qty,
                'returned_quantity' => 0,
                'label' => $row->customer_name,
                'value' => (float) $row->gross_sales,
                'format' => 'currency',
            ]);

        $report = $this->interactiveCollectionReport($rows, 'لا توجد مبيعات مجمعة حسب العملاء خلال الفترة المحددة.');
        $report['columns'] = [
            ['key' => 'customer_name', 'label' => 'اسم العميل', 'format' => 'text'],
            ['key' => 'customer_phone', 'label' => 'رقم هاتف العميل', 'format' => 'text'],
            ['key' => 'location', 'label' => 'الموقع', 'format' => 'text'],
            ['key' => 'sales', 'label' => 'المبيعات', 'format' => 'currency'],
            ['key' => 'cogs', 'label' => 'تكلفة البضاعة المباعة', 'format' => 'currency'],
            ['key' => 'profit', 'label' => 'قيمة الربح', 'format' => 'currency'],
            ['key' => 'sales_with_tax', 'label' => 'المبيعات (شامل الضريبة)', 'format' => 'currency'],
            ['key' => 'sold_quantity', 'label' => 'الكمية المباعة', 'format' => 'number'],
            ['key' => 'returned_quantity', 'label' => 'الكمية المرتجعة', 'format' => 'number'],
        ];

        return $report;
    }

    private function transactionsByBranchInteractiveReport(Company $company, Carbon $dateFrom, Carbon $dateTo): array
    {
        $rows = DB::table('invoices as i')
            ->leftJoin('branches as b', 'b.id', '=', 'i.branch_id')
            ->leftJoin('sales_channels as sc', 'sc.id', '=', 'i.sales_channel_id')
            ->leftJoin('employees', 'employees.id', '=', 'i.employee_id')
            ->leftJoin('invoice_items as ii', 'ii.invoice_id', '=', 'i.id')
            ->leftJoin('products as p', 'p.id', '=', 'ii.product_id')
            ->where('i.company_id', $company->id)
            ->whereIn('i.status', ['sent', 'partial', 'paid'])
            ->whereBetween('i.invoice_date', [$dateFrom->toDateString(), $dateTo->toDateString()])
            ->groupBy('i.id', 'b.name', 'b.code', 'i.invoice_number', 'i.invoice_date', 'i.total', 'sc.name', 'employees.first_name', 'employees.last_name')
            ->orderByDesc('i.invoice_date')
            ->selectRaw("COALESCE(NULLIF(b.name, ''), 'غير محدد') as branch_name, COALESCE(NULLIF(b.code, ''), '-') as branch_code, i.invoice_number, i.invoice_date, COALESCE(SUM(ii.quantity), 0) as qty_per_transaction, i.total as amount_paid_received, COALESCE(SUM(COALESCE(p.cost_price, 0) * ii.quantity), 0) as operation_cost, COALESCE(NULLIF(sc.name, ''), 'غير محدد') as sales_channel_name, employees.first_name, employees.last_name")
            ->get()
            ->map(function ($row) {
                $employeeName = trim(implode(' ', array_filter([$row->first_name, $row->last_name])));

                return [
                    'location' => $row->branch_name,
                    'location_code' => $row->branch_code,
                    'transaction_reference' => $row->invoice_number,
                    'transaction_type' => 'بيع',
                    'transaction_date' => $row->invoice_date,
                    'quantity_per_transaction' => (float) $row->qty_per_transaction,
                    'paid_or_received_amount' => (float) $row->amount_paid_received,
                    'operation_cost' => (float) $row->operation_cost,
                    'sales_channel' => $row->sales_channel_name,
                    'user_name' => $employeeName !== '' ? $employeeName : 'غير محدد',
                    'label' => $row->invoice_number,
                    'value' => (float) $row->amount_paid_received,
                    'format' => 'currency',
                ];
            });

        $report = $this->interactiveCollectionReport($rows, 'لا توجد معاملات موزعة على المواقع خلال الفترة المحددة.');
        $report['columns'] = [
            ['key' => 'location', 'label' => 'الموقع', 'format' => 'text'],
            ['key' => 'location_code', 'label' => 'رمز الموقع', 'format' => 'text'],
            ['key' => 'transaction_reference', 'label' => 'مرجع العملية', 'format' => 'text'],
            ['key' => 'transaction_type', 'label' => 'نوع العملية', 'format' => 'text'],
            ['key' => 'transaction_date', 'label' => 'تاريخ العملية', 'format' => 'date'],
            ['key' => 'quantity_per_transaction', 'label' => 'الكمية لكل عملية', 'format' => 'number'],
            ['key' => 'paid_or_received_amount', 'label' => 'المبلغ المدفوع/المستلم', 'format' => 'currency'],
            ['key' => 'operation_cost', 'label' => 'تكلفة العملية', 'format' => 'currency'],
            ['key' => 'sales_channel', 'label' => 'قناة البيع', 'format' => 'text'],
            ['key' => 'user_name', 'label' => 'المستخدم', 'format' => 'text'],
        ];

        return $report;
    }

    private function customerProductSalesInteractiveReport(Company $company, Carbon $dateFrom, Carbon $dateTo): array
    {
        $rows = DB::table('sales_items as si')
            ->join('sales as s', 's.id', '=', 'si.sale_id')
            ->join('customers', 'customers.id', '=', 's.customer_id')
            ->leftJoin('products', 'products.id', '=', 'si.product_id')
            ->where('s.company_id', $company->id)
            ->whereIn('s.status', ['sent', 'partial', 'paid'])
            ->whereBetween('s.sale_date', [$dateFrom->toDateString(), $dateTo->toDateString()])
            ->groupBy('customers.id', 'customers.name', 'products.id', 'products.name', 'si.description')
            ->selectRaw("customers.name as customer_name, COALESCE(NULLIF(products.name, ''), si.description) as product_name, SUM(si.quantity) as sold_qty, SUM(si.total_amount) as total_sales")
            ->orderByDesc('sold_qty')
            ->get()
            ->map(fn($row) => ['label' => $row->customer_name . ' / ' . $row->product_name, 'meta' => 'إجمالي المبيعات: ' . number_format((float) $row->total_sales, 2), 'value' => (float) $row->sold_qty, 'format' => 'number']);

        return $this->interactiveCollectionReport($rows, 'لا توجد منتجات مباعة للعملاء خلال الفترة المحددة.', 'number');
    }

    private function salesByPeriodInteractiveReport(Company $company, Carbon $dateFrom, Carbon $dateTo): array
    {
        $rows = DB::table('invoice_items as ii')
            ->join('invoices as i', 'i.id', '=', 'ii.invoice_id')
            ->leftJoin('products as p', 'p.id', '=', 'ii.product_id')
            ->leftJoin('branches as b', 'b.id', '=', 'i.branch_id')
            ->where('i.company_id', $company->id)
            ->whereIn('i.status', ['sent', 'partial', 'paid'])
            ->whereBetween('i.invoice_date', [$dateFrom->toDateString(), $dateTo->toDateString()])
            ->selectRaw("i.invoice_date, COALESCE(NULLIF(ii.description, ''), NULLIF(p.name, ''), 'غير محدد') as product_name, COALESCE(NULLIF(p.code, ''), '-') as product_number, COALESCE(NULLIF(b.name, ''), 'غير محدد') as branch_name, COALESCE(SUM(ii.quantity), 0) as quantity, COALESCE(AVG(COALESCE(p.cost_price, 0)), 0) as unit_cost, COALESCE(SUM(ii.total - ii.tax_amount), 0) as net_sales, 0 as discount_amount, COALESCE(SUM(ii.tax_amount), 0) as sales_tax, COALESCE(SUM(ii.total), 0) as gross_sales, COALESCE(SUM(COALESCE(p.cost_price, 0) * ii.quantity), 0) as cogs")
            ->groupBy('i.invoice_date', 'product_name', 'product_number', 'branch_name')
            ->orderBy('i.invoice_date')
            ->get()
            ->map(fn($row) => [
                'transaction_date' => $row->invoice_date,
                'product_name' => $row->product_name,
                'product_number' => $row->product_number,
                'location' => $row->branch_name,
                'quantity' => (float) $row->quantity,
                'unit_cost' => (float) $row->unit_cost,
                'profit' => round((float) $row->net_sales - (float) $row->cogs, 2),
                'sales' => (float) $row->net_sales,
                'discount' => (float) $row->discount_amount,
                'sales_tax' => (float) $row->sales_tax,
                'sales_with_tax' => (float) $row->gross_sales,
                'label' => $row->invoice_date,
                'value' => (float) $row->gross_sales,
                'format' => 'currency',
            ]);

        $report = $this->interactiveCollectionReport($rows, 'لا توجد مبيعات ضمن الفترة الزمنية المحددة.');
        $report['columns'] = [
            ['key' => 'transaction_date', 'label' => 'تاريخ العملية', 'format' => 'date'],
            ['key' => 'product_name', 'label' => 'اسم المنتج/الرقم التعريفي', 'format' => 'text'],
            ['key' => 'product_number', 'label' => 'رقم المنتج', 'format' => 'text'],
            ['key' => 'location', 'label' => 'الموقع', 'format' => 'text'],
            ['key' => 'quantity', 'label' => 'الكمية', 'format' => 'number'],
            ['key' => 'unit_cost', 'label' => 'التكلفة لكل وحدة', 'format' => 'currency'],
            ['key' => 'profit', 'label' => 'قيمة الربح', 'format' => 'currency'],
            ['key' => 'sales', 'label' => 'المبيعات', 'format' => 'currency'],
            ['key' => 'discount', 'label' => 'الخصم', 'format' => 'currency'],
            ['key' => 'sales_tax', 'label' => 'ضريبة المبيعات', 'format' => 'currency'],
            ['key' => 'sales_with_tax', 'label' => 'المبيعات (شامل الضريبة)', 'format' => 'currency'],
        ];

        return $report;
    }

    private function customerStatementInteractiveReport(Company $company, Carbon $dateFrom, Carbon $dateTo): array
    {
        $agingDate = $dateTo->copy()->endOfDay();

        $rows = DB::table('invoices as i')
            ->join('customers', 'customers.id', '=', 'i.customer_id')
            ->where('i.company_id', $company->id)
            ->whereIn('i.status', ['sent', 'partial', 'paid'])
            ->whereBetween('i.invoice_date', [$dateFrom->toDateString(), $dateTo->toDateString()])
            ->orderBy('customers.name')
            ->get(['customers.name as customer_name', 'customers.tax_number', 'i.total', 'i.paid_amount', 'i.balance_due', 'i.due_date', 'i.invoice_date'])
            ->groupBy('customer_name')
            ->map(function ($customerInvoices, $customerName) use ($agingDate) {
                $firstInvoice = $customerInvoices->first();
                $bucket_1_15 = 0.0;
                $bucket_16_31 = 0.0;
                $bucket_31_60 = 0.0;
                $bucket_61_90 = 0.0;
                $bucket_over_90 = 0.0;

                foreach ($customerInvoices as $invoice) {
                    $balance = (float) $invoice->balance_due;
                    if ($balance <= 0) {
                        continue;
                    }

                    $referenceDate = $invoice->due_date ?: $invoice->invoice_date;
                    $ageInDays = Carbon::parse($referenceDate)->diffInDays($agingDate, false);

                    if ($ageInDays >= 1 && $ageInDays <= 15) {
                        $bucket_1_15 += $balance;
                    } elseif ($ageInDays >= 16 && $ageInDays <= 31) {
                        $bucket_16_31 += $balance;
                    } elseif ($ageInDays >= 32 && $ageInDays <= 60) {
                        $bucket_31_60 += $balance;
                    } elseif ($ageInDays >= 61 && $ageInDays <= 90) {
                        $bucket_61_90 += $balance;
                    } elseif ($ageInDays > 90) {
                        $bucket_over_90 += $balance;
                    }
                }

                return [
                    'customer_name' => $customerName,
                    'customer_tax_number' => $firstInvoice->tax_number ?? '',
                    'sales_with_tax' => round((float) $customerInvoices->sum('total'), 2),
                    'paid_amount' => round((float) $customerInvoices->sum('paid_amount'), 2),
                    'outstanding_amount' => round((float) $customerInvoices->sum('balance_due'), 2),
                    'bucket_1_15' => round($bucket_1_15, 2),
                    'bucket_16_31' => round($bucket_16_31, 2),
                    'bucket_31_60' => round($bucket_31_60, 2),
                    'bucket_61_90' => round($bucket_61_90, 2),
                    'bucket_over_90' => round($bucket_over_90, 2),
                    'label' => $customerName,
                    'value' => round((float) $customerInvoices->sum('balance_due'), 2),
                    'format' => 'currency',
                ];
            })
            ->filter(fn($row) => $row['sales_with_tax'] > 0 || $row['outstanding_amount'] > 0)
            ->sortByDesc('outstanding_amount')
            ->values();

        $report = $this->interactiveCollectionReport($rows, 'لا توجد بيانات كافية لإظهار كشف حساب المدين خلال الفترة المحددة.');
        $report['columns'] = [
            ['key' => 'customer_name', 'label' => 'اسم العميل', 'format' => 'text'],
            ['key' => 'customer_tax_number', 'label' => 'الرقم الضريبي للعميل', 'format' => 'text'],
            ['key' => 'sales_with_tax', 'label' => 'المبيعات (شاملة الضريبة)', 'format' => 'currency'],
            ['key' => 'paid_amount', 'label' => 'المبلغ المدفوع', 'format' => 'currency'],
            ['key' => 'outstanding_amount', 'label' => 'المبلغ المستحق', 'format' => 'currency'],
            ['key' => 'bucket_1_15', 'label' => 'المبلغ المستحق خلال 1 - 15 يوم', 'format' => 'currency'],
            ['key' => 'bucket_16_31', 'label' => 'المبلغ المستحق خلال 16 - 31 يوم', 'format' => 'currency'],
            ['key' => 'bucket_31_60', 'label' => 'المبلغ المستحق خلال 31 - 60 يوم', 'format' => 'currency'],
            ['key' => 'bucket_61_90', 'label' => 'المبلغ المستحق خلال 61 - 90 يوم', 'format' => 'currency'],
            ['key' => 'bucket_over_90', 'label' => 'المبلغ المستحق لأكثر من 90 يوم', 'format' => 'currency'],
        ];

        return $report;
    }

    private function taxReturnInteractiveReport(Company $company, Carbon $dateFrom, Carbon $dateTo): array
    {
        // Sales (Output Tax)
        $outputVat = (float) Invoice::where('company_id', $company->id)
            ->whereIn('status', ['sent', 'partial', 'paid'])
            ->whereBetween('invoice_date', [$dateFrom->toDateString(), $dateTo->toDateString()])
            ->sum('tax_amount');

        $outputVat15 = (float) Invoice::where('company_id', $company->id)
            ->whereIn('status', ['sent', 'partial', 'paid'])
            ->whereBetween('invoice_date', [$dateFrom->toDateString(), $dateTo->toDateString()])
            ->where('tax_type', 'vat_15')
            ->sum('tax_amount');

        $outputZero = (float) Invoice::where('company_id', $company->id)
            ->whereIn('status', ['sent', 'partial', 'paid'])
            ->whereBetween('invoice_date', [$dateFrom->toDateString(), $dateTo->toDateString()])
            ->where('tax_type', 'vat_0')
            ->sum('total');

        $outputExempt = (float) Invoice::where('company_id', $company->id)
            ->whereIn('status', ['sent', 'partial', 'paid'])
            ->whereBetween('invoice_date', [$dateFrom->toDateString(), $dateTo->toDateString()])
            ->where('tax_type', 'exempt')
            ->sum('total');

        $totalSales = (float) Invoice::where('company_id', $company->id)
            ->whereIn('status', ['sent', 'partial', 'paid'])
            ->whereBetween('invoice_date', [$dateFrom->toDateString(), $dateTo->toDateString()])
            ->sum('total');

        // Purchases (Input Tax)
        $inputVat = (float) Purchase::where('company_id', $company->id)
            ->whereBetween('purchase_date', [$dateFrom->toDateString(), $dateTo->toDateString()])
            ->sum('tax_amount');

        $inputVat15 = (float) Purchase::where('company_id', $company->id)
            ->whereBetween('purchase_date', [$dateFrom->toDateString(), $dateTo->toDateString()])
            ->where('tax_type', 'vat_15')
            ->sum('tax_amount');

        $totalPurchases = (float) Purchase::where('company_id', $company->id)
            ->whereBetween('purchase_date', [$dateFrom->toDateString(), $dateTo->toDateString()])
            ->sum('total');

        // Calculate net tax
        $netTax = $outputVat - $inputVat;

        // Count documents
        $salesCount = Invoice::where('company_id', $company->id)
            ->whereIn('status', ['sent', 'partial', 'paid'])
            ->whereBetween('invoice_date', [$dateFrom->toDateString(), $dateTo->toDateString()])
            ->count();

        $purchaseCount = Purchase::where('company_id', $company->id)
            ->whereBetween('purchase_date', [$dateFrom->toDateString(), $dateTo->toDateString()])
            ->count();

        return [
            'type' => 'tax_return',
            'title' => 'الإقرار الضريبي',
            'period' => $dateFrom->format('Y-m-d') . ' - ' . $dateTo->format('Y-m-d'),
            'summary' => [
                'output_vat_15' => $outputVat15,
                'output_vat_total' => $outputVat,
                'output_zero_rated' => $outputZero,
                'output_exempt' => $outputExempt,
                'total_sales' => $totalSales,
                'input_vat_15' => $inputVat15,
                'input_vat_total' => $inputVat,
                'total_purchases' => $totalPurchases,
                'net_tax_payable' => $netTax,
                'sales_documents' => $salesCount,
                'purchase_documents' => $purchaseCount,
            ],
            'rows' => [],
            'empty_message' => 'لا توجد بيانات ضريبية للفترة المحددة.',
        ];
    }

    private function warehouseCoverageInteractiveReport(Company $company): array
    {
        $rows = Product::forCompany($company->id)
            ->orderByRaw('(stock_quantity - min_stock) asc')
            ->get()
            ->map(fn(Product $product) => ['label' => $product->name, 'meta' => sprintf('المخزون: %s | الحد الأدنى: %s', number_format((float) $product->stock_quantity, 2), number_format((float) $product->min_stock, 2)), 'value' => round((float) $product->stock_quantity - (float) $product->min_stock, 2)]);

        return $this->interactiveCollectionReport($rows, 'لا توجد بيانات كافية عن تغطية المخزون.');
    }

    private function warehouseIncomingInteractiveReport(Company $company, Carbon $dateFrom, Carbon $dateTo): array
    {
        $rows = DB::table('purchase_items')
            ->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
            ->leftJoin('products', 'products.id', '=', 'purchase_items.product_id')
            ->where('purchases.company_id', $company->id)
            ->whereBetween('purchases.purchase_date', [$dateFrom->toDateString(), $dateTo->toDateString()])
            ->selectRaw("COALESCE(products.name, purchase_items.description) as label, SUM(purchase_items.quantity) as incoming_qty, SUM(purchase_items.total) as total_value")
            ->groupBy('label')
            ->orderByDesc('incoming_qty')
            ->get()
            ->map(fn($row) => ['label' => $row->label, 'meta' => 'قيمة الوارد: ' . number_format((float) $row->total_value, 2), 'value' => (float) $row->incoming_qty]);

        return $this->interactiveCollectionReport($rows, 'لا توجد حركات وارد من المشتريات ضمن الفترة المحددة.');
    }

    private function warehouseSuppliersInteractiveReport(Company $company, Carbon $dateFrom, Carbon $dateTo): array
    {
        $rows = Purchase::query()
            ->join('suppliers', 'suppliers.id', '=', 'purchases.supplier_id')
            ->where('purchases.company_id', $company->id)
            ->whereBetween('purchases.purchase_date', [$dateFrom->toDateString(), $dateTo->toDateString()])
            ->groupBy('suppliers.id', 'suppliers.name')
            ->selectRaw('suppliers.name as label, COUNT(purchases.id) as purchase_count, SUM(purchases.total) as total_received')
            ->orderByDesc('total_received')
            ->get()
            ->map(fn($row) => ['label' => $row->label, 'meta' => 'عدد أوامر الشراء: ' . $row->purchase_count, 'value' => (float) $row->total_received]);

        return $this->interactiveCollectionReport($rows, 'لا توجد استلامات موردين ضمن الفترة المحددة.');
    }

    private function interactiveCollectionReport(Collection $rows, string $emptyMessage, string $valueFormat = 'currency'): array
    {
        return [
            'supported' => true,
            'rows' => $rows,
            'chart' => [
                'type' => 'bar',
                'labels' => $rows->pluck('label')->take(8)->values(),
                'values' => $rows->pluck('value')->map(fn($value) => round((float) $value, 2))->take(8)->values(),
            ],
            'highlights' => [
                ['label' => 'عدد النتائج', 'value' => $rows->count(), 'format' => 'number'],
                ['label' => 'الإجمالي', 'value' => (float) $rows->sum('value'), 'format' => $valueFormat],
                ['label' => 'أعلى نتيجة', 'value' => $rows->isNotEmpty() ? (float) $rows->max('value') : 0, 'format' => $valueFormat],
            ],
            'value_format' => $valueFormat,
            'columns' => [
                ['key' => 'label', 'label' => 'البند'],
                ['key' => 'meta', 'label' => 'التفاصيل'],
                ['key' => 'value', 'label' => 'القيمة', 'format' => $valueFormat],
            ],
            'empty_message' => $emptyMessage,
            'insight' => $rows->isNotEmpty() ? 'الأداء الأعلى حالياً: ' . $rows->first()['label'] : $emptyMessage,
        ];
    }

    private function resolveReportRange(string $period, ?string $dateFrom, ?string $dateTo): array
    {
        return match ($period) {
            'quarterly' => [now()->startOfQuarter(), now()->endOfQuarter()],
            'yearly' => [now()->startOfYear(), now()->endOfYear()],
            'custom' => [Carbon::parse((string) $dateFrom)->startOfDay(), Carbon::parse((string) $dateTo)->endOfDay()],
            default => [now()->startOfMonth(), now()->endOfMonth()],
        };
    }

    private function reportSummaryStats(int $companyId, Carbon $dateFrom, Carbon $dateTo): array
    {
        $revenueQuery = Invoice::where('company_id', $companyId)
            ->whereIn('status', ['sent', 'partial', 'paid'])
            ->whereBetween('invoice_date', [$dateFrom->toDateString(), $dateTo->toDateString()]);
        $purchaseQuery = Purchase::where('company_id', $companyId)
            ->whereIn('status', ['approved', 'partial', 'paid'])
            ->whereBetween('purchase_date', [$dateFrom->toDateString(), $dateTo->toDateString()]);
        $expenseQuery = Expense::where('company_id', $companyId)
            ->whereBetween('expense_date', [$dateFrom->toDateString(), $dateTo->toDateString()]);

        $totalRevenue = (float) $revenueQuery->sum('total');
        $totalExpenses = (float) $purchaseQuery->sum('total') + (float) $expenseQuery->sum('total');

        return [
            'total_revenue' => $totalRevenue,
            'total_expenses' => $totalExpenses,
            'net_profit' => $totalRevenue - $totalExpenses,
            'cash_flow' => $totalRevenue - $totalExpenses,
            'avg_order_value' => (float) Invoice::where('company_id', $companyId)
                ->whereBetween('invoice_date', [$dateFrom->toDateString(), $dateTo->toDateString()])
                ->avg('total'),
            'total_customers' => Customer::where('company_id', $companyId)->count(),
            'inventory_value' => (float) Product::forCompany($companyId)->sum(DB::raw('stock_quantity * cost_price')),
            'outstanding_receivables' => (float) Invoice::where('company_id', $companyId)->sum('balance_due'),
        ];
    }

    private function buildReportData(Company $company, string $reportType, array $filters, Carbon $dateFrom, Carbon $dateTo, array $reportTypes): array
    {
        $report = match ($reportType) {
            'account_balances' => $this->accountBalancesReport($company, $filters, $dateFrom, $dateTo),
            'product_sales' => $this->productSalesReport($company, $filters, $dateFrom, $dateTo),
            'expense_details' => $this->expenseDetailsReport($company, $filters, $dateFrom, $dateTo),
            'receivables' => $this->receivablesReport($company, $filters, $dateFrom, $dateTo),
            'payables' => $this->payablesReport($company, $filters, $dateFrom, $dateTo),
            'tax_summary' => $this->taxSummaryReport($company, $dateFrom, $dateTo),
            default => $this->incomeStatementReport($company, $dateFrom, $dateTo),
        };

        $report['type'] = $reportType;
        $report['description'] = $reportTypes[$reportType]['description'];
        $report['date_range_label'] = sprintf('من %s إلى %s', $dateFrom->format('Y-m-d'), $dateTo->format('Y-m-d'));

        return $report;
    }

    private function incomeStatementReport(Company $company, Carbon $dateFrom, Carbon $dateTo): array
    {
        $revenue = (float) Invoice::where('company_id', $company->id)
            ->whereIn('status', ['sent', 'partial', 'paid'])
            ->whereBetween('invoice_date', [$dateFrom->toDateString(), $dateTo->toDateString()])
            ->sum('total');
        $purchases = (float) Purchase::where('company_id', $company->id)
            ->whereIn('status', ['approved', 'partial', 'paid'])
            ->whereBetween('purchase_date', [$dateFrom->toDateString(), $dateTo->toDateString()])
            ->sum('total');
        $expenses = (float) Expense::where('company_id', $company->id)
            ->whereBetween('expense_date', [$dateFrom->toDateString(), $dateTo->toDateString()])
            ->sum('total');
        $netProfit = $revenue - $purchases - $expenses;

        $rows = collect([
            ['label' => 'إجمالي الإيرادات', 'value' => $revenue, 'meta' => 'فواتير المبيعات المعتمدة'],
            ['label' => 'إجمالي المشتريات', 'value' => $purchases, 'meta' => 'المشتريات المعتمدة خلال الفترة'],
            ['label' => 'إجمالي المصروفات', 'value' => $expenses, 'meta' => 'المصروفات المسجلة خلال الفترة'],
            ['label' => 'صافي الربح', 'value' => $netProfit, 'meta' => 'الإيرادات - المشتريات - المصروفات'],
        ]);

        return [
            'title' => 'قائمة الدخل',
            'rows' => $rows,
            'chart' => [
                'type' => 'bar',
                'labels' => $rows->pluck('label')->values(),
                'values' => $rows->pluck('value')->map(fn($value) => round((float) $value, 2))->values(),
            ],
            'highlights' => [
                ['label' => 'الإيرادات', 'value' => $revenue],
                ['label' => 'المصروفات الكلية', 'value' => $purchases + $expenses],
                ['label' => 'صافي الربح', 'value' => $netProfit],
            ],
            'empty_message' => 'لا توجد بيانات كافية للفترة المختارة.',
        ];
    }

    private function accountBalancesReport(Company $company, array $filters, Carbon $dateFrom, Carbon $dateTo): array
    {
        $selectedAccountId = isset($filters['account_id']) ? (int) $filters['account_id'] : null;
        $rows = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->where('journal_entries.company_id', $company->id)
            ->whereBetween('journal_entries.entry_date', [$dateFrom->toDateString(), $dateTo->toDateString()])
            ->when($selectedAccountId, fn($query) => $query->where('accounts.id', $selectedAccountId))
            ->groupBy('accounts.id', 'accounts.code', 'accounts.name', 'accounts.account_type')
            ->selectRaw('accounts.id, accounts.code, accounts.name, accounts.account_type, SUM(journal_lines.debit) as debit_total, SUM(journal_lines.credit) as credit_total')
            ->orderBy('accounts.code')
            ->get()
            ->map(function ($row) {
                $isDebitAccount = in_array($row->account_type, ['asset', 'expense', 'cogs'], true);
                $balance = $isDebitAccount
                    ? ((float) $row->debit_total - (float) $row->credit_total)
                    : ((float) $row->credit_total - (float) $row->debit_total);

                return [
                    'label' => trim($row->code . ' - ' . $row->name),
                    'value' => $balance,
                    'meta' => sprintf('مدين: %s | دائن: %s', number_format((float) $row->debit_total, 2), number_format((float) $row->credit_total, 2)),
                ];
            });

        return [
            'title' => $selectedAccountId ? 'تقرير حساب محدد' : 'أرصدة الحسابات',
            'rows' => $rows,
            'chart' => [
                'type' => 'bar',
                'labels' => $rows->pluck('label')->take(8)->values(),
                'values' => $rows->pluck('value')->take(8)->map(fn($value) => round((float) $value, 2))->values(),
            ],
            'highlights' => [
                ['label' => 'عدد الحسابات', 'value' => $rows->count()],
                ['label' => 'إجمالي الأرصدة', 'value' => $rows->sum('value')],
            ],
            'empty_message' => 'لا توجد حركات حسابات خلال الفترة المحددة.',
        ];
    }

    private function productSalesReport(Company $company, array $filters, Carbon $dateFrom, Carbon $dateTo): array
    {
        $selectedProductId = isset($filters['product_id']) ? (int) $filters['product_id'] : null;
        $rows = Invoice::query()
            ->join('invoice_items', 'invoice_items.invoice_id', '=', 'invoices.id')
            ->leftJoin('products', 'products.id', '=', 'invoice_items.product_id')
            ->where('invoices.company_id', $company->id)
            ->whereIn('invoices.status', ['sent', 'partial', 'paid'])
            ->whereBetween('invoices.invoice_date', [$dateFrom->toDateString(), $dateTo->toDateString()])
            ->when($selectedProductId, fn($query) => $query->where('products.id', $selectedProductId))
            ->groupBy('products.id', 'products.name', 'invoice_items.description')
            ->selectRaw('products.id, COALESCE(products.name, invoice_items.description) as label, SUM(invoice_items.quantity) as quantity_sold, SUM(invoice_items.total) as total_sales')
            ->orderByDesc('total_sales')
            ->get()
            ->map(fn($row) => [
                'label' => $row->label,
                'value' => (float) $row->total_sales,
                'meta' => 'الكمية المباعة: ' . number_format((float) $row->quantity_sold, 2),
            ]);

        return [
            'title' => $selectedProductId ? 'تقرير منتج محدد' : 'مبيعات المنتجات',
            'rows' => $rows,
            'chart' => [
                'type' => 'bar',
                'labels' => $rows->pluck('label')->take(8)->values(),
                'values' => $rows->pluck('value')->take(8)->map(fn($value) => round((float) $value, 2))->values(),
            ],
            'highlights' => [
                ['label' => 'عدد المنتجات', 'value' => $rows->count()],
                ['label' => 'إجمالي المبيعات', 'value' => $rows->sum('value')],
            ],
            'empty_message' => 'لا توجد مبيعات منتجات وفق المعايير المختارة.',
        ];
    }

    private function expenseDetailsReport(Company $company, array $filters, Carbon $dateFrom, Carbon $dateTo): array
    {
        $selectedExpenseId = isset($filters['expense_id']) ? (int) $filters['expense_id'] : null;
        $rows = Expense::with('expenseAccount')
            ->where('company_id', $company->id)
            ->whereBetween('expense_date', [$dateFrom->toDateString(), $dateTo->toDateString()])
            ->when($selectedExpenseId, fn($query) => $query->where('id', $selectedExpenseId))
            ->orderByDesc('expense_date')
            ->get()
            ->map(fn(Expense $expense) => [
                'label' => $expense->name ?: ($expense->reference ?: 'مصروف #' . $expense->id),
                'value' => (float) $expense->total,
                'meta' => trim(implode(' | ', array_filter([
                    $expense->expense_date?->format('Y-m-d'),
                    $expense->reference,
                    $expense->expenseAccount?->name,
                ]))),
            ]);

        return [
            'title' => $selectedExpenseId ? 'تفاصيل مصروف محدد' : 'تفاصيل المصروفات',
            'rows' => $rows,
            'chart' => [
                'type' => 'bar',
                'labels' => $rows->pluck('label')->take(8)->values(),
                'values' => $rows->pluck('value')->take(8)->map(fn($value) => round((float) $value, 2))->values(),
            ],
            'highlights' => [
                ['label' => 'عدد المصروفات', 'value' => $rows->count()],
                ['label' => 'إجمالي المصروفات', 'value' => $rows->sum('value')],
            ],
            'empty_message' => 'لا توجد مصروفات مطابقة للمعايير المختارة.',
        ];
    }

    private function receivablesReport(Company $company, array $filters, Carbon $dateFrom, Carbon $dateTo): array
    {
        $selectedCustomerId = isset($filters['customer_id']) ? (int) $filters['customer_id'] : null;
        $rows = Invoice::query()
            ->join('customers', 'customers.id', '=', 'invoices.customer_id')
            ->where('invoices.company_id', $company->id)
            ->whereBetween('invoices.invoice_date', [$dateFrom->toDateString(), $dateTo->toDateString()])
            ->where('invoices.balance_due', '>', 0)
            ->when($selectedCustomerId, fn($query) => $query->where('customers.id', $selectedCustomerId))
            ->groupBy('customers.id', 'customers.name')
            ->selectRaw('customers.name as label, SUM(invoices.balance_due) as balance_due, COUNT(invoices.id) as invoice_count')
            ->orderByDesc('balance_due')
            ->get()
            ->map(fn($row) => [
                'label' => $row->label,
                'value' => (float) $row->balance_due,
                'meta' => 'عدد الفواتير المفتوحة: ' . $row->invoice_count,
            ]);

        return [
            'title' => $selectedCustomerId ? 'ذمم عميل محدد' : 'الذمم المدينة',
            'rows' => $rows,
            'chart' => [
                'type' => 'bar',
                'labels' => $rows->pluck('label')->take(8)->values(),
                'values' => $rows->pluck('value')->take(8)->map(fn($value) => round((float) $value, 2))->values(),
            ],
            'highlights' => [
                ['label' => 'عدد العملاء', 'value' => $rows->count()],
                ['label' => 'إجمالي الذمم', 'value' => $rows->sum('value')],
            ],
            'empty_message' => 'لا توجد ذمم مدينة ضمن المعايير المختارة.',
        ];
    }

    private function payablesReport(Company $company, array $filters, Carbon $dateFrom, Carbon $dateTo): array
    {
        $selectedSupplierId = isset($filters['supplier_id']) ? (int) $filters['supplier_id'] : null;
        $rows = Purchase::query()
            ->join('suppliers', 'suppliers.id', '=', 'purchases.supplier_id')
            ->where('purchases.company_id', $company->id)
            ->whereBetween('purchases.purchase_date', [$dateFrom->toDateString(), $dateTo->toDateString()])
            ->where('purchases.balance_due', '>', 0)
            ->when($selectedSupplierId, fn($query) => $query->where('suppliers.id', $selectedSupplierId))
            ->groupBy('suppliers.id', 'suppliers.name')
            ->selectRaw('suppliers.name as label, SUM(purchases.subtotal) as subtotal_amount, SUM(purchases.tax_amount) as tax_amount, SUM(purchases.total) as total_amount, SUM(purchases.balance_due) as balance_due, COUNT(purchases.id) as purchase_count')
            ->orderByDesc('balance_due')
            ->get()
            ->map(fn($row) => [
                'label' => $row->label,
                'value' => (float) $row->balance_due,
                'meta' => sprintf(
                    'عدد المشتريات المفتوحة: %s | قبل الضريبة: %s | الضريبة: %s | الإجمالي: %s',
                    $row->purchase_count,
                    number_format((float) $row->subtotal_amount, 2),
                    number_format((float) $row->tax_amount, 2),
                    number_format((float) $row->total_amount, 2),
                ),
            ]);

        $purchaseTotals = Purchase::query()
            ->where('company_id', $company->id)
            ->whereBetween('purchase_date', [$dateFrom->toDateString(), $dateTo->toDateString()])
            ->where('balance_due', '>', 0)
            ->when($selectedSupplierId, fn($query) => $query->where('supplier_id', $selectedSupplierId))
            ->selectRaw('COUNT(id) as purchase_count, SUM(subtotal) as subtotal_amount, SUM(tax_amount) as tax_amount, SUM(total) as total_amount, SUM(balance_due) as balance_due')
            ->first();

        return [
            'title' => $selectedSupplierId ? 'ذمم مورد محدد' : 'الذمم الدائنة',
            'rows' => $rows,
            'chart' => [
                'type' => 'bar',
                'labels' => $rows->pluck('label')->take(8)->values(),
                'values' => $rows->pluck('value')->take(8)->map(fn($value) => round((float) $value, 2))->values(),
            ],
            'highlights' => [
                ['label' => 'عدد الموردين', 'value' => $rows->count()],
                ['label' => 'إجمالي قبل الضريبة', 'value' => (float) ($purchaseTotals->subtotal_amount ?? 0)],
                ['label' => 'إجمالي الضريبة', 'value' => (float) ($purchaseTotals->tax_amount ?? 0)],
                ['label' => 'إجمالي الذمم', 'value' => (float) ($purchaseTotals->balance_due ?? 0)],
            ],
            'empty_message' => 'لا توجد ذمم دائنة ضمن المعايير المختارة.',
        ];
    }

    private function taxSummaryReport(Company $company, Carbon $dateFrom, Carbon $dateTo): array
    {
        $outputVat = (float) Invoice::where('company_id', $company->id)
            ->whereIn('status', ['sent', 'partial', 'paid'])
            ->whereBetween('invoice_date', [$dateFrom->toDateString(), $dateTo->toDateString()])
            ->sum('tax_amount');

        $purchaseInputVat = (float) Purchase::where('company_id', $company->id)
            ->whereIn('status', ['approved', 'partial', 'paid'])
            ->whereBetween('purchase_date', [$dateFrom->toDateString(), $dateTo->toDateString()])
            ->sum('tax_amount');

        $expenseInputVat = (float) Expense::where('company_id', $company->id)
            ->whereBetween('expense_date', [$dateFrom->toDateString(), $dateTo->toDateString()])
            ->sum('tax_amount');

        $totalInputVat = $purchaseInputVat + $expenseInputVat;
        $netVat = $outputVat - $totalInputVat;

        $rows = collect([
            ['label' => 'ضريبة المخرجات من الفواتير', 'value' => $outputVat, 'meta' => 'إجمالي ضريبة المبيعات المعتمدة خلال الفترة'],
            ['label' => 'ضريبة المدخلات من المشتريات', 'value' => $purchaseInputVat, 'meta' => 'إجمالي ضريبة طلبات الشراء المعتمدة خلال الفترة'],
            ['label' => 'ضريبة المدخلات من المصروفات', 'value' => $expenseInputVat, 'meta' => 'إجمالي ضريبة المصروفات خلال الفترة'],
            ['label' => 'صافي الضريبة المستحقة', 'value' => $netVat, 'meta' => 'ضريبة المخرجات - إجمالي ضريبة المدخلات'],
        ]);

        return [
            'title' => 'تقرير الضرائب',
            'rows' => $rows,
            'chart' => [
                'type' => 'bar',
                'labels' => $rows->pluck('label')->values(),
                'values' => $rows->pluck('value')->map(fn($value) => round((float) $value, 2))->values(),
            ],
            'highlights' => [
                ['label' => 'ضريبة المخرجات', 'value' => $outputVat],
                ['label' => 'ضريبة المدخلات', 'value' => $totalInputVat],
                ['label' => 'صافي الضريبة', 'value' => $netVat],
            ],
            'empty_message' => 'لا توجد بيانات ضريبية للفترة المختارة.',
        ];
    }

    private function company(Request $request): Company
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        return $user->company;
    }

    private function countryConfigs(): Collection
    {
        return collect([
            'SA' => ['name_ar' => 'المملكة العربية السعودية', 'currency' => 'SAR', 'cities' => ['الرياض', 'جدة', 'مكة المكرمة', 'المدينة المنورة', 'الدمام', 'الخبر', 'الظهران', 'الطائف', 'أبها', 'تبوك']],
            'AE' => ['name_ar' => 'الإمارات العربية المتحدة', 'currency' => 'AED', 'cities' => ['دبي', 'أبوظبي', 'الشارقة', 'عجمان', 'رأس الخيمة', 'الفجيرة', 'أم القيوين', 'العين']],
            'US' => ['name_ar' => 'الولايات المتحدة', 'currency' => 'USD', 'cities' => ['New York', 'Los Angeles', 'Chicago', 'Houston', 'Miami', 'Dallas', 'Seattle', 'San Francisco']],
            'EG' => ['name_ar' => 'مصر', 'currency' => 'EGP', 'cities' => ['القاهرة', 'الجيزة', 'الإسكندرية', 'المنصورة', 'طنطا', 'أسيوط', 'الأقصر', 'أسوان']],
            'JO' => ['name_ar' => 'الأردن', 'currency' => 'JOD', 'cities' => ['عمّان', 'إربد', 'الزرقاء', 'العقبة', 'السلط', 'مادبا', 'جرش', 'الكرك']],
        ]);
    }

    private function countryConfigForCompany(Company $company): array
    {
        $countries = $this->countryConfigs();

        return $countries->get($company->country_code, $countries->get('SA'));
    }
}
