<?php

namespace App\Http\Controllers;

use App\Services\WalletAccountService;
use App\Services\WalletFundingService;
use Illuminate\Http\Request;

final class WalletController extends Controller
{
    public function initialize(Request $request, WalletFundingService $funding)
    {
        abort_unless(config('care360_wallet.enabled', false), 503);
        $data = $request->validate(['amount_minor' => 'required|integer|min:10000|max:10000000']);
        $user = $request->user('api');
        return response()->json(['success' => true, 'data' => $funding->initialize(
            (int) $user->id, (string) $user->email, (int) $data['amount_minor']
        )])->header('Cache-Control', 'private, no-store');
    }

    public function confirm(Request $request, WalletFundingService $funding)
    {
        abort_unless(config('care360_wallet.enabled', false), 503);
        $data = $request->validate(['reference' => 'required|string|max:100']);
        $journal = $funding->confirm((int) $request->user('api')->id, $data['reference']);
        return response()->json(['success' => true, 'data' => ['journal_id' => $journal]])
            ->header('Cache-Control', 'private, no-store');
    }

    public function balance(Request $request, WalletAccountService $wallet)
    {
        abort_unless(config('care360_wallet.enabled', false), 503);
        return response()->json(['success' => true, 'data' => $wallet->balance((int) $request->user('api')->id)])
            ->header('Cache-Control', 'private, no-store');
    }

    public function history(Request $request, WalletAccountService $wallet)
    {
        abort_unless(config('care360_wallet.enabled', false), 503);
        $data = $request->validate(['before' => 'nullable|integer|min:1']);
        return response()->json(['success' => true, 'data' => $wallet->history(
            (int) $request->user('api')->id, isset($data['before']) ? (int) $data['before'] : null
        )])->header('Cache-Control', 'private, no-store');
    }
}
