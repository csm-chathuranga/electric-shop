<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class StockTransferController extends Controller
{
    public function index(Request $request)
    {
        $query = StockTransfer::with('user')->latest();

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('transfer_no', 'like', '%' . $request->search . '%')
                  ->orWhere('destination', 'like', '%' . $request->search . '%');
            });
        }

        $transfers = $query->paginate(20)->withQueryString();

        return Inertia::render('StockTransfers/Index', [
            'transfers' => $transfers,
            'filters'   => $request->only(['search']),
        ]);
    }

    public function create()
    {
        return Inertia::render('StockTransfers/Create');
    }

    public function store(Request $request)
    {
        $request->validate([
            'destination'   => 'required|string|max:200',
            'transfer_date' => 'required|date',
            'note'          => 'nullable|string|max:1000',
            'items'         => 'required|array|min:1',
            'items.*.product_id'   => 'required|integer|exists:products,id',
            'items.*.product_name' => 'required|string',
            'items.*.qty'          => 'required|numeric|min:0.001',
        ]);

        $transfer = DB::transaction(function () use ($request) {
            $date = Carbon::parse($request->transfer_date)->format('Ymd');

            $last     = StockTransfer::whereDate('created_at', Carbon::today())
                ->lockForUpdate()->orderByDesc('id')->first();
            $sequence = $last ? (intval(substr($last->transfer_no, -4)) + 1) : 1;
            $transferNo = 'TRF-' . $date . '-' . str_pad($sequence, 4, '0', STR_PAD_LEFT);

            $transfer = StockTransfer::create([
                'transfer_no'   => $transferNo,
                'user_id'       => Auth::id(),
                'destination'   => $request->destination,
                'transfer_date' => $request->transfer_date,
                'total_items'   => count($request->items),
                'note'          => $request->note,
            ]);

            foreach ($request->items as $item) {
                $product = Product::lockForUpdate()->findOrFail($item['product_id']);

                if ($product->stock_qty < $item['qty']) {
                    abort(422, "Insufficient stock for \"{$product->name}\". Available: {$product->stock_qty}");
                }

                StockTransferItem::create([
                    'stock_transfer_id' => $transfer->id,
                    'product_id'        => $product->id,
                    'product_name'      => $item['product_name'],
                    'qty'               => $item['qty'],
                ]);

                $stockBefore = $product->stock_qty;
                $product->decrement('stock_qty', $item['qty']);

                StockMovement::create([
                    'product_id'   => $product->id,
                    'user_id'      => Auth::id(),
                    'type'         => 'out',
                    'qty'          => $item['qty'],
                    'stock_before' => $stockBefore,
                    'stock_after'  => $stockBefore - $item['qty'],
                    'reference'    => $transferNo,
                    'note'         => 'Transfer to: ' . $request->destination . ' (' . $transferNo . ')',
                ]);
            }

            return $transfer;
        });

        return redirect()->route('stock-transfers.show', $transfer->id)
            ->with('success', 'Stock transfer recorded. ' . $transfer->transfer_no);
    }

    public function show(string $id)
    {
        $transfer = StockTransfer::with(['items.product', 'user'])->findOrFail($id);

        return Inertia::render('StockTransfers/Show', [
            'transfer' => $transfer,
        ]);
    }

    public function destroy(string $id)
    {
        $transfer = StockTransfer::with('items')->findOrFail($id);

        DB::transaction(function () use ($transfer) {
            foreach ($transfer->items as $item) {
                $product = Product::lockForUpdate()->findOrFail($item->product_id);

                $stockBefore = $product->stock_qty;
                $product->increment('stock_qty', $item->qty);

                StockMovement::create([
                    'product_id'   => $product->id,
                    'user_id'      => Auth::id(),
                    'type'         => 'in',
                    'qty'          => $item->qty,
                    'stock_before' => $stockBefore,
                    'stock_after'  => $stockBefore + $item->qty,
                    'reference'    => $transfer->transfer_no,
                    'note'         => 'Transfer reversal: ' . $transfer->transfer_no,
                ]);
            }

            $transfer->items()->delete();
            $transfer->delete();
        });

        return redirect()->route('stock-transfers.index')
            ->with('success', 'Transfer ' . $transfer->transfer_no . ' cancelled and stock restored.');
    }
}
