<?php

namespace App\Http\Controllers;

use App\Services\WalletAccountService;
use Illuminate\Http\Request;

final class WalletController extends Controller
{
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
