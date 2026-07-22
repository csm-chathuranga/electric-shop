<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\InstallmentDocument;
use App\Models\InstallmentPayment;
use App\Models\InstallmentPlan;
use App\Models\Product;
use App\Models\StockMovement;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;

class InstallmentController extends Controller
{
    public function index(Request $request)
    {
        // Auto-mark overdue
        InstallmentPayment::unpaid()->where('due_date', '<', today())->update(['status' => 'overdue']);

        $query = InstallmentPlan::with(['customer', 'user', 'payments'])
            ->withCount('payments');

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('plan_no', 'like', "%{$s}%")
                  ->orWhereHas('customer', fn ($cq) => $cq->where('name', 'like', "%{$s}%"));
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $plans = $query->latest()->paginate(20)->withQueryString();

        return Inertia::render('Installments/Index', [
            'plans'   => $plans,
            'filters' => $request->only(['search', 'status']),
        ])->with(['flash' => session('flash')]);
    }

    public function customerSummary(string $customerId)
    {
        // Auto-mark overdue first
        InstallmentPayment::unpaid()->where('due_date', '<', today())->update(['status' => 'overdue']);

        $plans = InstallmentPlan::with('payments')
            ->where('customer_id', $customerId)
            ->latest()
            ->get()
            ->map(function ($plan) {
                $paid    = $plan->payments->sum('amount_paid');
                $overdue = $plan->payments->where('status', 'overdue');

                // Payments settled after their due date (historically late, now paid)
                $paidLate = $plan->payments->filter(
                    fn ($p) => $p->status === 'paid' && $p->paid_at && $p->due_date
                               && $p->paid_at->gt($p->due_date)
                );

                // Payments settled on or before due date
                $paidOnTime = $plan->payments->filter(
                    fn ($p) => $p->status === 'paid' && $p->paid_at && $p->due_date
                               && !$p->paid_at->gt($p->due_date)
                );

                return [
                    'id'             => $plan->id,
                    'plan_no'        => $plan->plan_no,
                    'total'          => $plan->total,
                    'paid'           => $paid,
                    'balance'        => $plan->total - $paid,
                    'status'         => $plan->status,
                    'created_at'     => $plan->created_at->toDateString(),
                    'overdue_count'  => $overdue->count(),
                    'overdue_amount' => $overdue->sum(fn ($p) => $p->amount_due - $p->amount_paid),
                    'late_count'     => $paidLate->count() + $overdue->count(), // ever-late + currently overdue
                    'on_time_count'  => $paidOnTime->count(),
                    'total_payments' => $plan->payments->count(),
                ];
            });

        // Flat list of all overdue payments for this customer
        $overduePayments = InstallmentPayment::with('plan')
            ->whereHas('plan', fn ($q) => $q->where('customer_id', $customerId)->where('status', 'active'))
            ->where('status', 'overdue')
            ->orderBy('due_date')
            ->get()
            ->map(fn ($p) => [
                'id'          => $p->id,
                'plan_id'     => $p->plan_id,
                'plan_no'     => $p->plan->plan_no,
                'installment' => $p->installment_no === 0 ? 'Down Payment' : 'Installment ' . $p->installment_no,
                'due_date'    => $p->due_date->toDateString(),
                'days_overdue'=> $p->due_date->diffInDays(today()),
                'amount_due'  => $p->amount_due,
                'amount_paid' => $p->amount_paid,
                'balance'     => $p->amount_due - $p->amount_paid,
            ]);

        return response()->json([
            'total_plans'      => $plans->count(),
            'active_plans'     => $plans->where('status', 'active')->count(),
            'completed_plans'  => $plans->where('status', 'completed')->count(),
            'total_given'      => $plans->sum('total'),
            'total_paid'       => $plans->sum('paid'),
            'total_balance'    => $plans->sum('balance'),
            'overdue_count'    => $overduePayments->count(),
            'overdue_amount'   => $overduePayments->sum('balance'),
            'overdue_payments' => $overduePayments,
            'plans'            => $plans,
        ]);
    }

    public function create()
    {
        $customers = Customer::where('active', true)->orderBy('name')->get();
        $settings  = \App\Models\Setting::all()->pluck('value', 'key')->toArray();

        return Inertia::render('Installments/Create', [
            'customers'        => $customers,
            'defaultInterestRate' => (float) ($settings['installment_interest_rate'] ?? 10),
            'defaultGraceDays'    => (int)   ($settings['installment_dp_grace_days'] ?? 7),
        ])->with(['flash' => session('flash')]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'customer_id'              => 'required|exists:customers,id',
            'items'                    => 'required|array|min:1',
            'items.*.product_id'       => 'nullable|exists:products,id',
            'items.*.product_name'     => 'required|string',
            'items.*.qty'              => 'required|numeric|min:0.01',
            'items.*.unit_price'       => 'required|numeric|min:0',
            'items.*.discount'         => 'nullable|numeric|min:0',
            'items.*.total'            => 'required|numeric|min:0',
            'subtotal'                 => 'required|numeric|min:0',
            'discount'                 => 'nullable|numeric|min:0',
            'total'                    => 'required|numeric|min:0',
            'down_payment_amount'      => 'nullable|numeric|min:0',
            'down_payment_percent'     => 'required|integer|min:1|max:100',
            'installments_count'       => 'required|integer|min:1|max:12',
            'interest_rate'            => 'nullable|numeric|min:0|max:100',
            'dp_grace_days'            => 'nullable|integer|min:0|max:365',
            'initial_paid'             => 'nullable|numeric|min:0',
            'plan_date'                => 'nullable|date',
            'notes'                    => 'nullable|string',
        ]);

        $plan = DB::transaction(function () use ($request) {
            $settings         = \App\Models\Setting::all()->pluck('value', 'key');
            $interestRate     = (float) ($request->interest_rate ?? $settings->get('installment_interest_rate', 10));
            $dpGraceDays      = (int)   ($request->dp_grace_days ?? $settings->get('installment_dp_grace_days', 7));

            // Use provided plan_date (for backdating) or today
            $planDate = $request->filled('plan_date')
                ? Carbon::parse($request->plan_date)
                : Carbon::today();

            $subtotal         = (float) $request->subtotal;
            $interestAmount   = round($subtotal * $interestRate / 100, 2);
            $total            = round($subtotal + $interestAmount - ($request->discount ?? 0), 2);

            $count = (int) $request->installments_count;

            // Use the exact amount if provided by the frontend; otherwise derive from percentage
            if ($request->filled('down_payment_amount')) {
                $downPayment = round(min((float) $request->down_payment_amount, $total), 2);
                $dpPct       = $total > 0 ? (int) round($downPayment / $total * 100) : (int) $request->down_payment_percent;
            } else {
                $dpPct       = (int) $request->down_payment_percent;
                $downPayment = round($total * $dpPct / 100, 2);
            }

            $balance           = round($total - $downPayment, 2);
            $installmentAmount = $count > 0 ? round($balance / $count, 2) : 0;

            // Plan number uses plan_date so backdated plans get the correct date prefix
            $dateStr = $planDate->format('Ymd');
            $last    = InstallmentPlan::whereDate('plan_date', $planDate->toDateString())
                ->lockForUpdate()->orderByDesc('id')->first();
            if (!$last) {
                // Fallback: check by plan_no prefix for that date
                $last = InstallmentPlan::where('plan_no', 'like', "IP-{$dateStr}-%")
                    ->lockForUpdate()->orderByDesc('id')->first();
            }
            $seq    = $last ? (intval(substr($last->plan_no, -4)) + 1) : 1;
            $planNo = 'IP-' . $dateStr . '-' . str_pad($seq, 4, '0', STR_PAD_LEFT);

            $plan = InstallmentPlan::create([
                'plan_no'              => $planNo,
                'plan_date'            => $planDate->toDateString(),
                'customer_id'          => $request->customer_id,
                'user_id'              => Auth::id(),
                'subtotal'             => $subtotal,
                'discount'             => $request->discount ?? 0,
                'total'                => $total,
                'down_payment'         => $downPayment,
                'balance'              => $balance,
                'installment_amount'   => $installmentAmount,
                'installments_count'   => $count,
                'down_payment_percent' => $dpPct,
                'interest_rate'        => $interestRate,
                'interest_amount'      => $interestAmount,
                'dp_grace_days'        => $dpGraceDays,
                'notes'                => $request->notes,
                'status'               => 'active',
            ]);

            // Items + stock deduction
            foreach ($request->items as $item) {
                $costPrice = 0;
                if (!empty($item['product_id'])) {
                    $product = Product::lockForUpdate()->findOrFail($item['product_id']);
                    $qty     = (float) $item['qty'];
                    $costPrice = (float) $product->cost_price;
                    $stockBefore = $product->stock_qty;
                    $product->decrement('stock_qty', $qty);
                    StockMovement::create([
                        'product_id'   => $product->id,
                        'user_id'      => Auth::id(),
                        'type'         => 'out',
                        'qty'          => $qty,
                        'stock_before' => $stockBefore,
                        'stock_after'  => $stockBefore - $qty,
                        'reference'    => $planNo,
                        'note'         => 'Installment Plan: ' . $planNo,
                    ]);
                }

                $plan->items()->create([
                    'product_id'   => $item['product_id'] ?? null,
                    'product_name' => $item['product_name'],
                    'qty'          => $item['qty'],
                    'unit_price'   => $item['unit_price'],
                    'cost_price'   => $costPrice,
                    'discount'     => $item['discount'] ?? 0,
                    'total'        => $item['total'],
                ]);
            }

            // Initial payment actually received today (can be less than required down payment)
            $initialPaid = isset($request->initial_paid)
                ? round(min(max(0, (float) $request->initial_paid), $downPayment), 2)
                : $downPayment; // default: full down payment received

            $dpStatus = $initialPaid >= $downPayment ? 'paid'
                : ($initialPaid > 0 ? 'partial' : 'pending');

            // Payment schedule rows
            // #0 = down payment — grace period to settle the remaining balance
            InstallmentPayment::create([
                'plan_id'        => $plan->id,
                'installment_no' => 0,
                'due_date'       => $dpGraceDays > 0
                    ? $planDate->copy()->addDays($dpGraceDays)
                    : $planDate->copy(),
                'amount_due'     => $downPayment,
                'amount_paid'    => $initialPaid,
                'paid_at'        => $initialPaid > 0 ? $planDate->copy() : null,
                'payment_method' => $initialPaid > 0 ? 'cash' : null,
                'status'         => $dpStatus,
                'collected_by'   => $initialPaid > 0 ? \Illuminate\Support\Facades\Auth::id() : null,
            ]);

            // #1, #2, … = monthly installments from plan_date
            for ($i = 1; $i <= $count; $i++) {
                $due = $i === $count
                    ? $balance - ($installmentAmount * ($count - 1))
                    : $installmentAmount;

                InstallmentPayment::create([
                    'plan_id'        => $plan->id,
                    'installment_no' => $i,
                    'due_date'       => $planDate->copy()->addMonths($i),
                    'amount_due'     => round($due, 2),
                    'amount_paid'    => 0,
                    'status'         => 'pending',
                ]);
            }

            return $plan;
        });

        return redirect()->route('installments.show', $plan->id)
            ->with('success', "Installment plan {$plan->plan_no} created.");
    }

    public function show(string $id)
    {
        // Auto-mark overdue
        InstallmentPayment::unpaid()->where('due_date', '<', today())->update(['status' => 'overdue']);

        $plan = InstallmentPlan::with([
            'customer',
            'user',
            'items.product',
            'payments.collector',
            'documents',
        ])->findOrFail($id);

        $settings = \App\Models\Setting::all()->pluck('value', 'key')->toArray();

        return Inertia::render('Installments/Show', [
            'plan'     => $plan,
            'settings' => $settings,
        ]);
    }

    public function pay(Request $request, string $planId, string $paymentId)
    {
        $request->validate([
            'amount_paid'    => 'required|numeric|min:0.01',
            'payment_method' => 'required|string|in:cash,card,qr',
            'reference'      => 'nullable|string|max:100',
            'notes'          => 'nullable|string',
        ]);

        $payment = InstallmentPayment::where('plan_id', $planId)->findOrFail($paymentId);

        if ($payment->status === 'paid') {
            return back()->withErrors(['error' => 'This installment is already paid.']);
        }

        $amountPaid = (float) $request->amount_paid;
        $newPaid    = $payment->amount_paid + $amountPaid;
        $excess     = max(0, $newPaid - $payment->amount_due);

        $payment->update([
            'amount_paid'    => min($newPaid, $payment->amount_due),
            'paid_at'        => now(),
            'payment_method' => $request->payment_method,
            'reference'      => $request->reference,
            'notes'          => $request->notes,
            'status'         => $newPaid >= $payment->amount_due ? 'paid' : 'partial',
            'collected_by'   => Auth::id(),
        ]);

        // Apply excess to the next unpaid installment
        if ($excess > 0) {
            $next = InstallmentPayment::where('plan_id', $planId)
                ->whereIn('status', ['pending', 'partial', 'overdue'])
                ->where('installment_no', '>', $payment->installment_no)
                ->orderBy('installment_no')
                ->first();

            if ($next) {
                $nextNewPaid = $next->amount_paid + $excess;
                $next->update([
                    'amount_paid'    => min($nextNewPaid, $next->amount_due),
                    'paid_at'        => $nextNewPaid >= $next->amount_due ? now() : $next->paid_at,
                    'payment_method' => $request->payment_method,
                    'reference'      => $request->reference,
                    'notes'          => 'Carry-over from ' . ($payment->installment_no === 0 ? 'down payment' : 'installment ' . $payment->installment_no),
                    'status'         => $nextNewPaid >= $next->amount_due ? 'paid' : 'partial',
                    'collected_by'   => Auth::id(),
                ]);
            }
        }

        // Check if all payments are done → complete the plan
        $plan    = InstallmentPlan::with('payments')->findOrFail($planId);
        $allPaid = $plan->payments->every(fn ($p) => $p->status === 'paid');
        if ($allPaid) {
            $plan->update(['status' => 'completed']);
        }

        $msg = 'Payment recorded successfully.';
        if ($excess > 0) {
            $msg .= " Excess Rs. " . number_format($excess, 2) . " applied to next installment.";
        }

        return back()->with('success', $msg);
    }

    public function settleAll(Request $request, string $planId)
    {
        $request->validate([
            'payment_method' => 'required|string|in:cash,card,qr',
            'reference'      => 'nullable|string|max:100',
            'notes'          => 'nullable|string',
        ]);

        $plan = InstallmentPlan::with('payments')->findOrFail($planId);

        if ($plan->status === 'completed') {
            return back()->withErrors(['error' => 'This plan is already completed.']);
        }

        $totalSettled = 0;

        DB::transaction(function () use ($plan, $request, &$totalSettled) {
            foreach ($plan->payments as $payment) {
                if ($payment->status === 'paid') continue;

                $remaining = $payment->amount_due - $payment->amount_paid;
                if ($remaining <= 0) continue;

                $totalSettled += $remaining;

                $payment->update([
                    'amount_paid'    => $payment->amount_due,
                    'paid_at'        => now(),
                    'payment_method' => $request->payment_method,
                    'reference'      => $request->reference,
                    'notes'          => $request->notes ?: 'Full settlement',
                    'status'         => 'paid',
                    'collected_by'   => Auth::id(),
                ]);
            }

            $plan->update(['status' => 'completed']);
        });

        return back()->with('success', 'Plan fully settled. Rs. ' . number_format($totalSettled, 2) . ' recorded.');
    }

    public function uploadDocument(Request $request, string $id)
    {
        $request->validate([
            'type'  => 'required|in:nic_front,nic_back,photo,address_proof,guarantor_nic,agreement,other',
            'label' => 'nullable|string|max:100',
            'file'  => 'required|file|mimes:jpg,jpeg,png,pdf|max:5120',
        ]);

        $plan = InstallmentPlan::findOrFail($id);
        $file = $request->file('file');

        // Upload to ImageKit via REST API
        $privateKey = config('services.imagekit.private_key');
        $folderName = 'installment-docs/' . $plan->plan_no;
        $fileName    = $request->type . '_' . time() . '.' . $file->getClientOriginalExtension();

        $response = Http::withoutVerifying()
            ->withBasicAuth($privateKey, '')
            ->attach('file', file_get_contents($file->getRealPath()), $fileName)
            ->post('https://upload.imagekit.io/api/v1/files/upload', [
                'fileName'          => $fileName,
                'folder'            => $folderName,
                'useUniqueFileName' => 'false',
            ]);

        if (!$response->successful()) {
            return back()->withErrors(['file' => 'Upload to ImageKit failed: ' . $response->body()]);
        }

        $data = $response->json();

        InstallmentDocument::create([
            'plan_id'       => $plan->id,
            'type'          => $request->type,
            'label'         => $request->label,
            'file_path'     => $data['url'],           // store the full CDN URL
            'original_name' => $file->getClientOriginalName(),
            'uploaded_by'   => Auth::id(),
        ]);

        return back()->with('success', 'Document uploaded.');
    }

    public function serveDocument(string $planId, string $docId)
    {
        if (!Auth::user()->isAdmin() && !Auth::user()->isManager()) {
            abort(403);
        }

        $doc = InstallmentDocument::where('plan_id', $planId)->findOrFail($docId);

        // file_path now holds the full ImageKit URL
        return redirect($doc->file_path);
    }

    public function destroy(string $id)
    {
        if (!Auth::user()->isAdmin()) {
            abort(403);
        }

        $plan = InstallmentPlan::with('items')->findOrFail($id);

        DB::transaction(function () use ($plan) {
            // Restore stock
            foreach ($plan->items as $item) {
                if ($item->product_id) {
                    $product = Product::find($item->product_id);
                    if ($product) $product->increment('stock_qty', $item->qty);
                }
            }
            // ImageKit CDN files can be cleaned up from the ImageKit dashboard.
            // DB records are removed via cascade when the plan is deleted.
            $plan->delete();
        });

        return redirect()->route('installments.index')->with('success', 'Plan cancelled and stock restored.');
    }
}
