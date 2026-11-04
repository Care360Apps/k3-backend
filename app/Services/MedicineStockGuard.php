<?php

namespace App\Services;

use App\Models\Medicine;
use Illuminate\Validation\ValidationException;

/**
 * Atomically reserve stock during checkout. Unlike a check-then-increment,
 * this conditional update rejects overlapping purchases of the last units.
 * Payments that settle before this method runs still need compensation/refund
 * handling, so this is necessary but not sufficient for production checkout.
 */
final class MedicineStockGuard
{
    public static function consume(int $medicineId, int $pharmacyId, int $quantity): void
    {
        if ($medicineId < 1 || $pharmacyId < 1 || $quantity < 1) {
            throw ValidationException::withMessages([
                'medicines' => 'Invalid inventory request.',
            ]);
        }
        $count = Medicine::whereKey($medicineId)
            ->where('pharmacy_id', $pharmacyId)
            ->where('status', 1)
            ->whereRaw('(total_stock - use_stock) >= ?', [$quantity])
            ->increment('use_stock', $quantity);
        if ($count !== 1) {
            throw ValidationException::withMessages([
                'medicines' => 'Medicine availability has changed. Please review the order before retrying.',
            ]);
        }
    }
}
