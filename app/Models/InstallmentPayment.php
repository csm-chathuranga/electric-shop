<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InstallmentPayment extends Model
{
    protected $fillable = [
        'plan_id', 'installment_no', 'due_date',
        'amount_due', 'amount_paid', 'last_payment_amount', 'paid_at',
        'payment_method', 'reference', 'notes',
        'status', 'collected_by',
    ];

    protected $casts = [
        'due_date'    => 'date',
        'paid_at'     => 'datetime',
        'amount_due'  => 'float',
        'amount_paid' => 'float',
    ];

    public function plan()      { return $this->belongsTo(InstallmentPlan::class, 'plan_id'); }
    public function collector() { return $this->belongsTo(User::class, 'collected_by'); }

    public function isOverdue(): bool
    {
        return $this->status !== 'paid' && $this->due_date->isPast();
    }

    public function isDueSoon(int $days = 2): bool
    {
        return $this->status !== 'paid'
            && !$this->due_date->isPast()
            && $this->due_date->diffInDays(now()) <= $days;
    }

    // Scopes
    public function scopeUnpaid($q)   { return $q->whereIn('status', ['pending', 'partial', 'overdue']); }
    public function scopeOverdue($q)  { return $q->unpaid()->where('due_date', '<', today()); }
    public function scopeDueSoon($q, int $days = 2)
    {
        return $q->unpaid()->whereBetween('due_date', [today(), today()->addDays($days)]);
    }
}
