<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Offer extends Model
{
    use HasFactory;

    protected $table = 'offer';

    protected $fillable = ['name', 'offer_code', 'image', 'max_use', 'use_count', 'min_discount', 'start_end_date', 'user_id', 'doctor_id', 'desc', 'discount', 'discount_type', 'is_flat', 'flatDiscount', 'status'];

    protected $appends = ['fullImage'];

    protected function getFullImageAttribute()
    {
        return url('images/upload').'/'.$this->image;
    }

    /** An active, unexhausted offer with this code that applies to the user and doctor on $date (Y-m-d). */
    public static function findApplicable(?string $code, int $userId, int $doctorId, string $date): ?self
    {
        if (! $code) {
            return null;
        }
        $offer = static::where([['offer_code', $code], ['status', 1]])->whereColumn('max_use', '>', 'use_count')->first();
        if (! $offer
            || ! in_array((string) $userId, explode(',', (string) $offer->user_id), true)
            || ! in_array((string) $doctorId, explode(',', (string) $offer->doctor_id), true)) {
            return null;
        }
        [$start, $end] = array_pad(explode(' - ', (string) $offer->start_end_date), 2, null);
        $day = date('Y-m-d', strtotime($date));

        return $start && $end && $day >= $start && $day <= $end ? $offer : null;
    }

    /** Same rules the patient app shows: min_discount is the cap on the discount. */
    public function discountFor(float $fee): float
    {
        $cap = $this->min_discount !== null ? (float) $this->min_discount : INF;
        if (strtolower((string) $this->discount_type) === 'percentage') {
            $discount = min($fee * (float) $this->discount / 100, $cap);
        } else {
            $base = (int) $this->is_flat === 1 ? (float) $this->flatDiscount : (float) $this->discount;
            $discount = $fee > $base ? min($base, $cap) : 0.0;
        }

        return round(max(0.0, min($discount, $fee)), 2);
    }
}
