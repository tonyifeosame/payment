<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    protected $fillable = [
        'reference',
        'paystack_reference',
        'category_id',
        'subcategory_id',
        'category_name',
        'subcategory_name',
        'email',
        'name',
        'amount',
        'status',
        'paid_at',
        'payment_method',
        'meta_data',
        'school_id',
    ];

    protected $casts = [
        'meta_data' => 'array',
        'paid_at' => 'datetime',
    ];

    /**
     * The figures a receipt must show, derived from one authoritative source.
     *
     * `amount` is what the payer was actually charged, so it is always the total.
     * `meta_data` only ever *explains* that total by splitting it into the fee and
     * the service charge; it never overrides it. Receipts previously printed
     * base_amount as the total, so a payer charged ₦51,250 received a document
     * claiming ₦50,000 and the service fee was invisible.
     *
     * Both the emailed and the on-screen receipt read from here so they cannot drift.
     *
     * @return array{
     *     quantity:int, unit_price:float, fee_subtotal:float,
     *     service_fee:float, total:float, has_service_fee:bool, has_breakdown:bool
     * }
     */
    public function receiptBreakdown(): array
    {
        $meta = $this->decodedMetaData();

        $total = round((float) ($this->amount ?? 0), 2);
        $quantity = max((int) ($meta['quantity'] ?? 1), 1);

        $hasBreakdown = array_key_exists('base_amount', $meta) && is_numeric($meta['base_amount']);

        if (! $hasBreakdown) {
            // Nothing to split by, so the whole charge is the fee.
            return [
                'quantity' => $quantity,
                'unit_price' => round($total / $quantity, 2),
                'fee_subtotal' => $total,
                'service_fee' => 0.0,
                'total' => $total,
                'has_service_fee' => false,
                'has_breakdown' => false,
            ];
        }

        $feeSubtotal = round((float) $meta['base_amount'], 2);

        // A recorded markup is preferred, but the total always wins: if the parts do
        // not reconcile (legacy or hand-edited rows), re-derive from the real charge.
        $serviceFee = array_key_exists('markup_amount', $meta) && is_numeric($meta['markup_amount'])
            ? round((float) $meta['markup_amount'], 2)
            : round($total - $feeSubtotal, 2);

        if (round($feeSubtotal + $serviceFee, 2) !== $total) {
            $serviceFee = round($total - $feeSubtotal, 2);
        }

        // Never present a negative service fee or a subtotal above the amount charged.
        if ($serviceFee < 0) {
            $serviceFee = 0.0;
            $feeSubtotal = $total;
        }

        return [
            'quantity' => $quantity,
            'unit_price' => round($feeSubtotal / $quantity, 2),
            'fee_subtotal' => $feeSubtotal,
            'service_fee' => $serviceFee,
            'total' => $total,
            'has_service_fee' => $serviceFee > 0,
            'has_breakdown' => true,
        ];
    }

    /**
     * meta_data may be an array, a JSON string, or — on rows written by the old
     * un-scoped TransactionController::store() — a doubly encoded JSON string.
     */
    public function decodedMetaData(): array
    {
        $value = $this->meta_data;

        for ($depth = 0; $depth < 3 && is_string($value); $depth++) {
            $decoded = json_decode($value, true);

            if ($decoded === null) {
                break;
            }

            $value = $decoded;
        }

        return is_array($value) ? $value : [];
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function subcategory()
    {
        return $this->belongsTo(Subcategory::class);
    }

    public function school()
    {
        return $this->belongsTo(School::class);
    }

    /**
     * The payout obligation this settled payment created (Model A: at most one).
     */
    public function payout()
    {
        return $this->hasOne(Payout::class);
    }
}
