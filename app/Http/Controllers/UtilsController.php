<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Cache\RateLimiter;
use Symfony\Component\Yaml\Yaml;

class UtilsController extends Controller
{
    public function __construct()
    {
        $this->middleware('throttle', ['only' => 'RateLimit']);
    }

    public function index(Request $request)
    { return ''; }

    public function temp(Request $request)
    { }

    public function phpInfo()
    { echo phpinfo(); }

    public function chamberParent(Request $request, $location)
    {
        $format = $request->input('format', 'json');
        $result = \App\Libraries\Helpers::getLowerChamber($location);

        if($format == 'print_json')
        {
            header("Content-Type: application/json");
            echo json_encode($result, JSON_PRETTY_PRINT);
        }
        elseif($format == 'yaml')
        {
            header("Content-Type: text/plain");
            echo Yaml::dump($result);
        }
        else
        {
            return response()->json($result);
        }
    }

    public function safeCoords(Request $request, $location)
    {
        $result = \App\Libraries\Helpers::getSafeCoords($location);
        if($request->input('print'))
        {
            header("Content-Type: text/plain");
            print_r($result);
        }
        else
        {
            return response()->json($result);
        }
    }

    public function safeMap(Request $request, $location)
    {
        $format = $request->input('format', 'json');
        $result = \App\Libraries\Helpers::getSafeMap($location);

        if($format == 'print_json')
        {
            header("Content-Type: application/json");
            echo json_encode($result, JSON_PRETTY_PRINT);
        }
        elseif($format == 'yaml')
        {
            header("Content-Type: text/plain");
            echo Yaml::dump($result);
        }
        else
        {
            return response()->json($result);
        }
    }

    public function safeMapAll(Request $request, $location)
    {
        $format = $request->input('format', 'json');
        $result = \App\Libraries\Helpers::getSafeMapAll($location);

        if($format == 'print_json')
        {
            header("Content-Type: application/json");
            echo json_encode($result, JSON_PRETTY_PRINT);
        }
        elseif($format == 'yaml')
        {
            header("Content-Type: text/plain");
            echo Yaml::dump($result);
        }
        else
        {
            return response()->json($result);
        }
    }

    public function checkNodeBalance(Request $request)
    {
        $response = response()->json([
            'status' => 'OK',
            'message' => 'Request handled by node: '.env('HOSTNAME', 'unknown')
        ]);
        return $response;
    }

    public function checkRateLimit(Request $request, RateLimiter $limiter)
    {
        $attempts = $limiter->attempts($this->resolveRequestSignature($request));
        $response = response()->json([
            'status' => 'OK',
            'message' => 'Request handled by node: '.env('HOSTNAME', 'unknown'),
            'attempts' => $attempts . ' of 5'
        ]);
        return $response;
    }

    public function userBalance(Request $request, $identifier)
    {
        $field = null;
        $format = $request->input('format', 'json');
        $response = [];
        
        // Check identifier field.
        if(preg_match('/^\d+$/',$identifier))
        { $field = 'id'; }
        elseif(preg_match('/^0x[0-9a-f]{40}$/i',$identifier))
        { $field = 'address'; }
        elseif(filter_var($identifier, FILTER_VALIDATE_EMAIL))
        { $field = 'email'; }
        
        // Validate
        if($field)
        {
            $user = \App\User::where([[$field,'=',$identifier]])->get(['id','name','email','address'])->first();
            if($user)
            {
                $response['user'] = $user->toArray();
                $response['earnings'] = \App\Libraries\Helpers::getComputedBalance($user->id);
            }
            else
            {
                $response['error'] = 'User not found.';
            }
        }
        else
        {
            $response['error'] = 'Invalid value.';
        }

        // Response
        if($format == 'print_json')
        {
            header("Content-Type: application/json");
            echo json_encode($response, JSON_PRETTY_PRINT);
        }
        elseif($format == 'yaml')
        {
            header("Content-Type: text/plain");
            echo Yaml::dump($response);
        }
        else
        {
            return response()->json($response);
        }
    }

    public function userChambers(Request $request, $identifier)
    {
        $field = null;
        $format = $request->input('format', 'json');
        $response = [];
        
        // Check identifier field.
        if(preg_match('/^\d+$/',$identifier))
        { $field = 'id'; }
        elseif(preg_match('/^0x[0-9a-f]{40}$/i',$identifier))
        { $field = 'address'; }
        elseif(filter_var($identifier, FILTER_VALIDATE_EMAIL))
        { $field = 'email'; }

        // Validate
        if($field)
        {
            $user = \App\User::where([[$field,'=',$identifier]])->get(['id','name','email','address'])->first();
            if($user)
            {
                $response['user'] = $user->toArray();
                $response['chambers']['count'] = 0;
                $response['chambers']['list'] = \App\Chamber::where([['user_id','=',$user->id]])
                                        ->orderBy('level','asc')->get()->toArray();
                $response['chambers']['count'] = count($response['chambers']['list']);
            }
            else
            {
                $response['error'] = 'User not found.';
            }
        }
        else
        {
            $response['error'] = 'Invalid value.';
        }

        // Response
        if($format == 'print_json')
        {
            header("Content-Type: application/json");
            echo json_encode($response, JSON_PRETTY_PRINT);
        }
        elseif($format == 'yaml')
        {
            header("Content-Type: text/plain");
            echo Yaml::dump($response);
        }
        else
        {
            return response()->json($response);
        }
    }

    public function userTransactions(Request $request, $identifier)
    {
        $field = null;
        $format = $request->input('format', 'json');
        $response = [];
        
        // Check identifier field.
        if(preg_match('/^\d+$/',$identifier))
        { $field = 'id'; }
        elseif(preg_match('/^0x[0-9a-f]{40}$/i',$identifier))
        { $field = 'address'; }
        elseif(filter_var($identifier, FILTER_VALIDATE_EMAIL))
        { $field = 'email'; }

        // Validate
        if($field)
        {
            $user = \App\User::where([[$field,'=',$identifier]])->get(['id','name','email','address'])->first();
            if($user)
            {
                $items = \App\Transaction::where([['user_id','=',$user->id]])
                        ->orderBy('id','asc')->get()->toArray();
                $response = [
                    'user' => $user->toArray(),
                    'transactions' => [
                        'count' => count($items),
                        'list' => $items
                    ]
                ];
            }
            else
            {
                $response['error'] = 'User not found.';
            }
        }
        else
        {
            $response['error'] = 'Invalid value.';
        }

        // Response
        if($format == 'print_json')
        {
            header("Content-Type: application/json");
            echo json_encode($response, JSON_PRETTY_PRINT);
        }
        elseif($format == 'yaml')
        {
            header("Content-Type: text/plain");
            echo Yaml::dump($response);
        }
        else
        {
            return response()->json($response);
        }
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