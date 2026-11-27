<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Transaction extends Model
{
    protected $fillable = [
        'invoice_number',
        'user_id',
        'customer_name',
        'sales_channel',
        'order_status',
        'payment_status',
        'subtotal',
        'discount',
        'total',
        'notes',
        'completed_at',
        'cancelled_at',

        // GrabFood payout
        'platform_deduction',
        'net_payout',
        'payout_status',
        'payout_date',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'integer',
            'discount' => 'integer',
            'total' => 'integer',
            'platform_deduction' => 'integer',
            'net_payout' => 'integer',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'payout_date' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(TransactionItem::class);
    }

    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class);
    }

    public function refund(): HasOne
    {
        return $this->hasOne(Refund::class);
    }
}