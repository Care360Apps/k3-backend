<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PayoutAccount extends Model
{
    use HasFactory;

    protected $table = 'payout_accounts';

    protected $fillable = [
        'holder_type',
        'holder_id',
        'account_name',
        'account_number',
        'bank_name',
    ];

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

        return null;
    }
}

