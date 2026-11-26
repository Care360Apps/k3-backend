<?php

namespace App\Console\Commands;

use App\Services\PaymentReceiptClaimService;
use App\Services\WalletFundingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class ReconcileWalletFunding extends Command
{
    protected $signature = 'wallet:reconcile-funding {--apply : Credit independently verified pending payments} {--after=0 : Funding record cursor}';
    protected $description = 'Inspect a bounded batch of pending wallet funding records';

    public function handle(WalletFundingService $funding): int
    {
        $after = filter_var($this->option('after'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($after === false) {
            return self::FAILURE;
        }
        $apply = (bool) $this->option('apply');
        if ($apply && (! config('care360_wallet.enabled', false) || ! config('care360_wallet.funding_enabled', false) || ! PaymentReceiptClaimService::enabled())) {
            $this->error('Wallet funding is disabled.');
            return self::FAILURE;
        }
        $rows = DB::table('care360_wallet_funding')->where('status', 'pending')
            ->where('id', '>', $after)->where('created_at', '<', now()->subMinutes(5))
            ->orderBy('id')->limit(25)->get();
        $credited = 0;
        $unresolved = 0;
        foreach ($rows as $row) {
            if (! $apply) {
                continue;
            }
            try {
                $funding->confirm((int) $row->user_id, $row->reference);
                $credited++;
            } catch (\Throwable $e) {
                $unresolved++;
            }
        }
        $this->line('Pending records examined: '.$rows->count());
        $this->line('Confirmed credited: '.$credited);
        $this->line('Unresolved: '.$unresolved);
        $this->line('Next cursor: '.($rows->last()->id ?? $after));
        return $unresolved === 0 ? self::SUCCESS : self::FAILURE;
    }
}
