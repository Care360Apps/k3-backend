<?php

namespace App\Services;

use App\Models\Medicine;
use App\Models\Pharmacy;
use App\Models\UserAddress;
use Illuminate\Validation\ValidationException;

/**
 * Prices a medicine order from the database so clients cannot choose what they pay.
 */
class MedicineOrderPricing
{
    /**
     * @param  iterable<array{id: mixed, qty: mixed}>  $items
     * @return array{lines: list<array{medicine: Medicine, price: float, qty: int}>, items_total: float, delivery_charge: float, address_id: ?int, shipping_at: string}
     */
    public function price(Pharmacy $pharmacy, iterable $items, ?string $shippingAt, $addressId, int $userId): array
    {
        $quantities = [];
        foreach ($items as $item) {
            $item = (array) $item;
            $id = (int) ($item['id'] ?? 0);
            $qty = (int) ($item['qty'] ?? 0);
            if ($id <= 0 || $qty <= 0) {
                throw ValidationException::withMessages(['medicines' => __('Invalid medicine in the order.')]);
            }
            $quantities[$id] = ($quantities[$id] ?? 0) + $qty;
        }
        if (! $quantities) {
            throw ValidationException::withMessages(['medicines' => __('The order has no medicines.')]);
        }

        $medicines = Medicine::where('pharmacy_id', $pharmacy->id)->whereIn('id', array_keys($quantities))->get()->keyBy('id');
        $lines = [];
        $itemsTotal = 0.0;
        foreach ($quantities as $id => $qty) {
            $medicine = $medicines->get($id);
            if (! $medicine) {
                throw ValidationException::withMessages(['medicines' => __('A medicine in the order is not sold by this pharmacy.')]);
            }
            if ((int) $medicine->total_stock - (int) $medicine->use_stock < $qty) {
                throw ValidationException::withMessages(['medicines' => __(':name is out of stock.', ['name' => $medicine->name])]);
            }
            $price = (float) $medicine->price_pr_strip;
            $lines[] = ['medicine' => $medicine, 'price' => $price, 'qty' => $qty];
            $itemsTotal += $price * $qty;
        }

        $home = strtolower((string) $shippingAt) === 'home';
        $address = null;
        if ($home) {
            $address = UserAddress::where('user_id', $userId)->find($addressId);
            if (! $address) {
                throw ValidationException::withMessages(['address_id' => __('Please select one of your saved addresses.')]);
            }
        }

        return [
            'lines' => $lines,
            'items_total' => round($itemsTotal, 2),
            'delivery_charge' => $home ? $this->deliveryCharge($pharmacy, $address) : 0.0,
            'address_id' => $address?->id,
            'shipping_at' => $home ? 'Home' : 'Pharmacy',
        ];
    }

    public function deliveryCharge(Pharmacy $pharmacy, UserAddress $address): float
    {
        $tiers = json_decode((string) $pharmacy->delivery_charges, true);
        if ((int) $pharmacy->is_shipping !== 1 || ! is_array($tiers) || ! $tiers) {
            return 0.0;
        }
        $distance = $this->distanceKm((float) $address->lat, (float) $address->lang, (float) $pharmacy->lat, (float) $pharmacy->lang);
        if ($distance < 1) {
            return 0.0;
        }
        foreach ($tiers as $tier) {
            if ($distance >= (float) ($tier['min_value'] ?? 0) && $distance <= (float) ($tier['max_value'] ?? 0)) {
                return (float) ($tier['charges'] ?? 0);
            }
        }

        return (float) max(array_map(fn ($tier) => (float) ($tier['charges'] ?? 0), $tiers));
    }

    /** Whole kilometres, matching how the apps and website bucket distances into delivery tiers. */
    private function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): int
    {
        $earthRadiusKm = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return (int) ($earthRadiusKm * 2 * atan2(sqrt($a), sqrt(1 - $a)));
    }
}
