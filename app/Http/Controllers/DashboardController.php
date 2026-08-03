<?php

namespace App\Http\Controllers;

use App\Models\InstallmentPayment;
use App\Models\Product;
use App\Models\Sale;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public static function bustCache(): void
    {
        $tenant = config('database.connections.mysql.database');
        $today  = Carbon::today()->toDateString();
        Cache::forget($tenant . '_dashboard_' . $today . '_h' . now()->hour);
    }

    public function clearCache()
    {
        $tenant = config('database.connections.mysql.database');
        $today  = Carbon::today()->toDateString();

        for ($h = 0; $h <= 23; $h++) {
            Cache::forget($tenant . '_dashboard_' . $today . '_h' . $h);
        }

        return back()->with('flash', ['success' => 'Dashboard cache cleared.']);
    }

    public function index(Request $request)
    {
        $realToday    = Carbon::today()->toDateString();
        $selectedDate = $request->filled('date')
            ? Carbon::parse($request->date)->toDateString()
            : $realToday;
        $isToday = $selectedDate === $realToday;
        $hour    = now()->hour;

        $tenant   = config('database.connections.mysql.database');
        $cacheKey = $isToday
            ? $tenant . '_dashboard_' . $selectedDate . '_h' . $hour
            : $tenant . '_dashboard_date_' . $selectedDate;
        $cacheTtl = $isToday ? 3600 : 86400;

        $data = Cache::remember($cacheKey, $cacheTtl, function () use ($selectedDate, $isToday) {
            $selDate    = Carbon::parse($selectedDate);
            $monthStart = $selDate->copy()->startOfMonth()->startOfDay();
            $monthEnd   = $isToday ? now() : $selDate->copy()->endOfDay();

            // ── Scalar stats ──────────────────────────────────────────
            [$todaySales, $todayBills, $monthSales, $monthBills] = [
                Sale::whereDate('created_at', $selectedDate)->where('status', '!=', 'held')->sum(DB::raw('LEAST(paid, total)')),
                Sale::whereDate('created_at', $selectedDate)->where('status', '!=', 'held')->count(),
                Sale::whereBetween('created_at', [$monthStart, $monthEnd])->where('status', '!=', 'held')->sum(DB::raw('LEAST(paid, total)')),
                Sale::whereBetween('created_at', [$monthStart, $monthEnd])->where('status', '!=', 'held')->count(),
            ];

            // Total invoiced (including credit not yet collected)
            $todayTotal = Sale::whereDate('created_at', $selectedDate)->where('status', '!=', 'held')->sum('total');
            $monthTotal = Sale::whereBetween('created_at', [$monthStart, $monthEnd])->where('status', '!=', 'held')->sum('total');

            // Installment collections
            $todayInstallments = InstallmentPayment::whereDate('paid_at', $selectedDate)->where('status', 'paid')->sum('amount_paid');
            $monthInstallments = InstallmentPayment::whereBetween('paid_at', [$monthStart, $monthEnd])->where('status', 'paid')->sum('amount_paid');

            // Credit outstanding from today's sales (unpaid balance)
            $todayCredit = Sale::whereDate('created_at', $selectedDate)->where('status', '!=', 'held')->sum('balance');

            $totalProducts = Product::where('active', true)->count();
            $lowStockCount = Product::whereColumn('stock_qty', '<=', 'alert_qty')->count();

            // ── Selected date by payment method ──────────────────────
            // Credit payments store the outstanding balance (not received cash),
            // so exclude them from the received breakdown.
            $todayByPayment = DB::table('payments')
                ->join('sales', 'payments.sale_id', '=', 'sales.id')
                ->whereDate('sales.created_at', $selectedDate)
                ->where('sales.status', '!=', 'held')
                ->where('payments.method', '!=', 'credit')
                ->selectRaw('payments.method, SUM(LEAST(payments.amount, sales.total)) as total, COUNT(DISTINCT payments.sale_id) as bills')
                ->groupBy('payments.method')
                ->get();

            // ── 3 days ending at selected date ────────────────────────
            $last3Days = [];
            $selCarbon = Carbon::parse($selectedDate);
            for ($i = 2; $i >= 0; $i--) {
                $date = $selCarbon->copy()->subDays($i)->toDateString();
                $rows = DB::table('sales')
                    ->whereDate('created_at', $date)
                    ->where('status', '!=', 'held')
                    ->selectRaw('HOUR(created_at) as h, ROUND(SUM(LEAST(paid, total))) as t, COUNT(*) as b')
                    ->groupBy(DB::raw('HOUR(created_at)'))
                    ->get()
                    ->keyBy('h');

                $hourly = [];
                for ($h = 6; $h <= 22; $h++) {
                    $row      = $rows->get($h);
                    $hourly[] = [(int) $h, (int) ($row->t ?? 0), (int) ($row->b ?? 0)];
                }

                $label = $i === 0
                    ? ($isToday ? 'Today' : $selCarbon->format('D d'))
                    : ($i === 1 && $isToday ? 'Yesterday' : $selCarbon->copy()->subDays($i)->format('D d'));

                $last3Days[] = [
                    'date'   => $date,
                    'label'  => $label,
                    'total'  => (int) collect($rows)->sum('t'),
                    'bills'  => (int) collect($rows)->sum('b'),
                    'hourly' => $hourly,
                ];
            }

            // ── Heatmap — always relative to real today ───────────────
            $heatmapFrom = Carbon::today()->startOfWeek(Carbon::MONDAY)->subWeeks(9);
            $heatmapRows = DB::table('sales')
                ->where('created_at', '>=', $heatmapFrom)
                ->where('status', '!=', 'held')
                ->selectRaw('DATE(created_at) as d, ROUND(SUM(LEAST(paid, total))) as t, COUNT(*) as b')
                ->groupBy(DB::raw('DATE(created_at)'))
                ->get()
                ->keyBy('d');

            $heatmap = [];
            $cursor  = $heatmapFrom->copy();
            $end     = Carbon::today();
            while ($cursor->lte($end)) {
                $ds  = $cursor->toDateString();
                $row = $heatmapRows->get($ds);
                $heatmap[] = [
                    $ds,
                    $cursor->dayOfWeekIso,
                    $cursor->diffInWeeks($heatmapFrom),
                    (int) ($row->t ?? 0),
                    (int) ($row->b ?? 0),
                ];
                $cursor->addDay();
            }

            // ── Fast moving — last 30 days from real today ────────────
            $fastMoving = DB::table('sale_items')
                ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
                ->leftJoin('products', 'sale_items.product_id', '=', 'products.id')
                ->where('sales.created_at', '>=', Carbon::now()->subDays(30))
                ->where('sales.status', '!=', 'held')
                ->selectRaw('sale_items.product_name, MAX(products.image) as image, ROUND(SUM(sale_items.qty)) as total_qty, COUNT(DISTINCT sales.id) as bill_count')
                ->groupBy('sale_items.product_name')
                ->orderByDesc('total_qty')
                ->limit(8)
                ->get();

            // ── Recent sales for selected date ────────────────────────
            $recentSales = DB::table('sales')
                ->join('users', 'sales.user_id', '=', 'users.id')
                ->whereDate('sales.created_at', $selectedDate)
                ->where('sales.status', '!=', 'held')
                ->orderByDesc('sales.id')
                ->limit(8)
                ->get([
                    'sales.id', 'sales.invoice_no',
                    'sales.total', 'sales.paid', 'sales.balance',
                    'sales.created_at',
                    'users.name as user_name',
                ]);

            // ── Expiring soon — always real today ─────────────────────
            $expiringSoon = DB::table('products')
                ->whereNotNull('expiry_date')
                ->where('expiry_date', '<=', Carbon::today()->addDays(30))
                ->where('stock_qty', '>', 0)
                ->orderBy('expiry_date')
                ->limit(10)
                ->get(['id', 'name', 'name_si', 'expiry_date', 'stock_qty', 'unit']);

            return compact(
                'todaySales', 'todayBills', 'monthSales', 'monthBills',
                'todayTotal', 'monthTotal',
                'todayInstallments', 'monthInstallments',
                'todayCredit',
                'totalProducts', 'lowStockCount', 'todayByPayment',
                'last3Days', 'heatmap', 'fastMoving', 'recentSales', 'expiringSoon'
            );
        });

        // ── Installment alerts (not cached — always fresh) ──────────
        InstallmentPayment::unpaid()->where('due_date', '<', today())->update(['status' => 'overdue']);

        $overdueInstallments = InstallmentPayment::with(['plan.customer'])
            ->overdue()
            ->orderBy('due_date')
            ->get()
            ->map(fn ($p) => [
                'id'           => $p->id,
                'plan_id'      => $p->plan_id,
                'plan_no'      => $p->plan->plan_no,
                'customer'     => $p->plan->customer?->name,
                'installment_no' => $p->installment_no,
                'due_date'     => $p->due_date->toDateString(),
                'amount_due'   => $p->amount_due,
                'amount_paid'  => $p->amount_paid,
                'days_overdue' => $p->due_date->diffInDays(today()),
            ]);

        $upcomingInstallments = InstallmentPayment::with(['plan.customer'])
            ->dueSoon(2)
            ->orderBy('due_date')
            ->get()
            ->filter(fn ($p) => $p->plan !== null)
            ->map(fn ($p) => [
                'id'             => $p->id,
                'plan_id'        => $p->plan_id,
                'plan_no'        => $p->plan->plan_no,
                'customer'       => $p->plan->customer?->name,
                'installment_no' => $p->installment_no,
                'due_date'       => $p->due_date->toDateString(),
                'amount_due'     => $p->amount_due,
                'amount_paid'    => $p->amount_paid,
                'days_until'     => today()->diffInDays($p->due_date),
            ])->values();

        // ── Credit book outstanding (not cached) ───────────────────
        $creditCustomers = \App\Models\Customer::where('credit_balance', '>', 0)
            ->orderByDesc('credit_balance')
            ->get(['id', 'name', 'phone', 'credit_balance']);

        $customerIds = $creditCustomers->pluck('id');

        // Next pending installment due date per customer (for installment-plan customers)
        $nextDues = DB::table('installment_payments')
            ->join('installment_plans', 'installment_payments.plan_id', '=', 'installment_plans.id')
            ->whereIn('installment_plans.customer_id', $customerIds)
            ->whereIn('installment_payments.status', ['pending', 'partial', 'overdue'])
            ->groupBy('installment_plans.customer_id')
            ->select(DB::raw('installment_plans.customer_id as cid, MIN(installment_payments.due_date) as next_due'))
            ->pluck('next_due', 'cid');

        // Earliest credit_due_date from unpaid credit sales per customer
        $creditDueDates = DB::table('sales')
            ->whereIn('customer_id', $customerIds)
            ->where('balance', '>', 0)
            ->whereNotNull('credit_due_date')
            ->where('status', '!=', 'held')
            ->groupBy('customer_id')
            ->select(DB::raw('customer_id, MIN(credit_due_date) as credit_due'))
            ->pluck('credit_due', 'customer_id');

        $creditCustomers = $creditCustomers->map(fn ($c) => [
            'id'             => $c->id,
            'name'           => $c->name,
            'phone'          => $c->phone,
            'credit_balance' => $c->credit_balance,
            // next_due: from installment plan; credit_due: from credit sales
            'next_due'       => isset($nextDues[$c->id])      ? (string) $nextDues[$c->id]      : null,
            'credit_due'     => isset($creditDueDates[$c->id]) ? (string) $creditDueDates[$c->id] : null,
        ])->values();

        return Inertia::render('Dashboard', array_merge($data, [
            'overdueInstallments'  => $overdueInstallments,
            'upcomingInstallments' => $upcomingInstallments,
            'creditCustomers'      => $creditCustomers,
            'filters'              => ['date' => $selectedDate],
            'isToday'              => $isToday,
        ]))->with(['flash' => session('flash')]);
    }
}
