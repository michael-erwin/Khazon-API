<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Cache\RateLimiter;

class TestsController extends Controller
{
    public function __construct()
    {
        $this->middleware('throttle', ['only' => 'RateLimit']);
    }

    public function Index(Request $request)
    {
        return '';
    }

    public function NodeBalance(Request $request)
    {
        $response = response()->json([
            'status' => 'OK',
            'message' => 'Request handled by node: '.env('HOSTNAME', 'unknown')
        ]);
        return $response;
    }

    public function RateLimit(Request $request, RateLimiter $limiter)
    {
        $attempts = $limiter->attempts($this->resolveRequestSignature($request));
        $response = response()->json([
            'status' => 'OK',
            'message' => 'Request handled by node: '.env('HOSTNAME', 'unknown'),
            'attempts' => $attempts . ' of 5'
        ]);
        return $response;
    }

    protected function resolveRequestSignature($request)
    {
        return sha1(
            $request->method() .
            '|' . $request->getHost() .
            '|' . $request->ip()
        );
    }
}