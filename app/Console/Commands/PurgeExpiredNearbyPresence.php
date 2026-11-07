<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Minimize retention of voluntary public service-area coordinates.
 * Does NOT read actual device GPS, patient locations or private records.
 */
class PurgeExpiredNearbyPresence extends Command
{
    protected $signature = 'nearby:purge-expired-presence';

    protected $description = 'Clear public service locations after a shift ends or is revoked';

    public function handle(): int
    {
        if (! Schema::hasTable('professional_service_presence')) {
            // Don't fail unrelated schedules before the opt-in migration.
            return self::SUCCESS;
        }

        $now = now();
        $removed = DB::table('professional_service_presence')
            ->where(function ($query) use ($now) {
                $query->where('accepting_requests', false)
                    ->orWhereNull('on_duty_until')
                    ->orWhere('on_duty_until', '<=', $now);
            })
            ->where(function ($query) {
                $query->whereNotNull('public_lat')
                    ->orWhereNotNull('public_lng')
                    ->orWhereNotNull('public_location_consent_at')
                    ->orWhere('accepting_requests', true);
            })
            ->update([
                'accepting_requests' => false,
                'public_lat' => null,
                'public_lng' => null,
                'public_location_consent_at' => null,
                'on_duty_until' => null,
                'updated_at' => $now,
            ]);

        // No professional IDs or locations are written to CLI logs.
        $this->info('Cleared expired/revoked service-area publications: '.(int) $removed);

        return self::SUCCESS;
    }
}
