<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\Voucher;
use App\Services\Billing\VoucherService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class VoucherController extends Controller
{
    public function index(Request $request): Response
    {
        $query = Voucher::with(['plan:id,name', 'redeemer:id,name,client_code'])->latest('id');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                  ->orWhere('batch_name', 'like', "%{$search}%");
            });
        }

        return Inertia::render('Vouchers/Index', [
            'vouchers' => $query->paginate(25)->withQueryString(),
            'plans' => Plan::where('is_active', true)->orderBy('name')->get(['id', 'name', 'price']),
            'filters' => $request->only(['status', 'search']),
        ]);
    }

    public function store(Request $request, VoucherService $vouchers): RedirectResponse
    {
        $validated = $request->validate([
            'count' => 'required|integer|min:1|max:500',
            'value' => 'required|numeric|min:1|max:999999999',
            'plan_id' => 'nullable|exists:plans,id',
            'batch_name' => 'nullable|string|max:100',
        ]);

        try {
            $generated = $vouchers->generateBatch(
                (int) $validated['count'],
                (int) $validated['value'],
                !empty($validated['plan_id']) ? (int) $validated['plan_id'] : null,
                $validated['batch_name'] ?? null,
            );
        } catch (\InvalidArgumentException | \RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', sprintf(
            'Generated %d voucher(s) of %d XAF (batch %s). Codes: %s',
            count($generated),
            (int) $validated['value'],
            $generated[0]->batch_name,
            implode(', ', array_slice(array_column($generated, 'code'), 0, 10)) . (count($generated) > 10 ? '…' : '')
        ));
    }

    /**
     * Void an active voucher (cannot void redeemed ones — money already moved).
     */
    public function void(Voucher $voucher): RedirectResponse
    {
        if ($voucher->status !== Voucher::STATUS_ACTIVE) {
            return back()->with('error', "Voucher {$voucher->code} is {$voucher->status} and cannot be voided.");
        }

        $voucher->update(['status' => Voucher::STATUS_VOID]);

        return back()->with('success', "Voucher {$voucher->code} voided.");
    }
}
