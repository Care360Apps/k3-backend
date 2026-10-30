<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WithdrawalRequest extends Model
{
    use HasFactory;

    protected $table = 'withdrawal_requests';

    protected $fillable = [
        'holder_type',
        'holder_id',
        'amount',
        'payout_account_name',
        'payout_account_number',
        'payout_bank_name',
        'status',
        'payment_reference',
        'notes',
        'rejected_reason',
        'paid_at',
        'approved_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_PAID = 'paid';

    public const HOLDER_DOCTOR = 'doctor';
    public const HOLDER_PROVIDER = 'provider';
    public const HOLDER_PHARMACY = 'pharmacy';
    public const HOLDER_LAB = 'lab';

    public function holder()
    {
        if ($this->holder_type === self::HOLDER_DOCTOR || $this->holder_type === self::HOLDER_PROVIDER) {
            return $this->belongsTo(Doctor::class, 'holder_id');
        }
        if ($this->holder_type === self::HOLDER_PHARMACY) {
            return $this->belongsTo(Pharmacy::class, 'holder_id');
        }
        if ($this->holder_type === self::HOLDER_LAB) {
            return $this->belongsTo(Lab::class, 'holder_id');
        }
        // Fallback relation to avoid null relation errors on bad data.
        return $this->belongsTo(Doctor::class, 'holder_id');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function getHolderNameAttribute(): string
    {
        $holder = $this->holder;
        if (! $holder) {
            return '—';
        }
        return $holder->name ?? (string) $holder->id;
    }
}
