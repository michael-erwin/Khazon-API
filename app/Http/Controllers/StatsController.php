<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Libraries\Helpers;

class StatsController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function readMine()
    {
        $user = app('auth')->user();
        $referrals = $user->referral->count();
        $safes = \App\Chamber::where([['user_id','=',$user->id],['unlock_method','=','reg']])->count();
        $earnings = $user->balance;
        $data = [
            'total_safes' => $safes,
            'total_referrals' => $referrals,
            'total_rewards' => number_format($earnings, 6)
        ];

        // Response
        return response()->json($data, 200);
    }

    public function readDashboard()
    {
        // Restrict access.
        $restricted = Helpers::restrictAccess(['all','dashboard_r']);
        if($restricted) return $restricted;

        # Get stats data.
        $user_count = \App\User::count();
        $kta_payables = app('db')->table('transactions')->selectRaw('SUM(kta_amt) as total')
                        ->where([['complete','<',1], ['code','=','withdraw']])->first();
        $kta_paid = app('db')->table('transactions')->selectRaw('SUM(kta_amt) as total')
                        ->where([['complete','=',1], ['code','=','withdraw']])->first();

        # Stats data output format.
        $data = [
            'total_users' => $user_count,
            'total_payables' => $kta_payables->total,
            'total_paid' => $kta_paid->total
        ];

        // Response
        return response()->json($data, 200);
    }
}
