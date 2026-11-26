<?php

namespace Tests\Feature;

use App\Services\WalletAccountService;
use App\Services\WalletPostingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class WalletLedgerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'care360_wallet.enabled' => true]);
        DB::purge('sqlite');
        (require database_path('migrations/2026_11_26_110000_create_care360_wallet_ledger.php'))->up();
        DB::table('care360_wallet_accounts')->insert([
            'account_key' => 'system:NGN:paystack', 'kind' => 'system', 'currency' => 'NGN',
        ]);
    }

    protected function tearDown(): void
    {
        DB::purge('sqlite');
        parent::tearDown();
    }

    public function test_funding_replay_and_owner_scoped_balance(): void
    {
        $accounts = app(WalletAccountService::class);
        $customer = $accounts->account(10);
        $system = (int) DB::table('care360_wallet_accounts')->where('kind', 'system')->value('id');
        $posting = app(WalletPostingService::class);
        $entries = [$customer => 20000, $system => -20000];
        $first = $posting->post('fund:test', 'funding', 'NGN', $entries);
        $this->assertSame($first, $posting->post('fund:test', 'funding', 'NGN', array_reverse($entries, true)));
        $this->assertSame('20000', $accounts->balance(10)['balance_minor']);
        $this->assertSame('0', $accounts->balance(11)['balance_minor']);
        $this->assertCount(1, $accounts->history(10)['entries']);
        $this->assertCount(0, $accounts->history(11)['entries']);
        $this->assertSame(2, DB::table('care360_wallet_entries')->count());
    }

    public function test_rejected_debit_and_unbalanced_post_leave_no_journal(): void
    {
        $customer = app(WalletAccountService::class)->account(10);
        $system = (int) DB::table('care360_wallet_accounts')->where('kind', 'system')->value('id');
        foreach ([[$customer => -100, $system => 100], [$customer => 100, $system => -99]] as $i => $entries) {
            try {
                app(WalletPostingService::class)->post('reject:'.$i, 'checkout', 'NGN', $entries);
                $this->fail('Invalid posting accepted');
            } catch (ValidationException $e) {
                $this->assertSame(0, DB::table('care360_wallet_journals')->count());
            }
        }
    }

    public function test_outer_transaction_rollback_removes_posting(): void
    {
        $customer = app(WalletAccountService::class)->account(10);
        $system = (int) DB::table('care360_wallet_accounts')->where('kind', 'system')->value('id');
        DB::beginTransaction();
        try {
            app(WalletPostingService::class)->post('fund:rollback', 'funding', 'NGN', [$customer => 100, $system => -100]);
        } finally {
            DB::rollBack();
        }
        $this->assertSame(0, DB::table('care360_wallet_journals')->count());
        $this->assertSame(0, DB::table('care360_wallet_entries')->count());
    }

    public function test_disabled_wallet_rejects_account_access(): void
    {
        config(['care360_wallet.enabled' => false]);
        $this->expectException(ValidationException::class);
        app(WalletAccountService::class)->account(10);
    }
}
