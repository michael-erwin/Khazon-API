<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Libraries\Helpers;

class SettingsController extends Controller
{
    private $otp_window = 10; // 

    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware('throttle',['only'=>['auth']]);
    }

    public function index()
    {
        $user = app('auth')->user();
        $secondary_email = $user->secondary_email;
        $cqas = $user->challenge_questions;
        $data = [
            'profile' => $user,
            'security' => [
                'has_2fa' => $user->has_2fa == 1? '1' : '0'
            ],
            'recovery' => [
                'secondary_email' => 'disabled',
                'cqa' => [
                    'status' => $user->has_cqa == 1? '1' : '0',
                    'data' => []
                ]
            ]
        ];
        if($secondary_email) $data['recovery']['secondary_email'] = $secondary_email->email;
        if($cqas)
        {
            $plaintea = new \App\Libraries\PlainTea;
            foreach($cqas as $item)
            {
                $series = 'q'.$item->series;
                $app_key = env('APP_KEY');
                $data['recovery']['cqa']['data'][$series] = [
                    'q' => $plaintea->decrypt($item->question,$app_key),
                    'a' => ''
                ];
            }
        }

        // Response
        return response()->json($data, 200);
    }

    public function auth(Request $request)
    {
        // Verify password field exist.  
        $password = $request->input('password');
        if(!$password) return app('api_error')->badRequest();

        // Authenticate password.
        $user = app('auth')->user();
        $authentic = app('hash')->check($password, $user->password);
        if(!$authentic) return app('api_error')->invalidInput(['password'=>['Incorrect']],'Authentication failed.');

        // Issue JWT token
        $jwt = $this->makeJWT($user->id);

        // Response
        return response()->json(['token'=>$jwt], 200);
    }

    public function makePrivateKey(Request $request, \PragmaRX\Google2FA\Google2FA $google2fa)
    {
        $user = app('auth')->user();
        $key = $google2fa->generateSecretKey();
        $url = $google2fa->getQRCodeUrl(
            'khazon.online',
            $user->email,
            $key
        );
        $output = ['url' => $url, 'key' => $key];
        return response()->json($output, 200);
    }

    public function updateField(Request $request, $field) {
        /**
          Validation
        */
        // Require 'value' field input.
        $value = $request->input('value');
        if(!$value) return app('api_error')->badRequest(null, 'Bad value.');

        # Impose allowed fields.
        $allowed_fields = ['address','email','has_2fa','name','password','secondary_email','cqas'];
        if(!in_array($field, $allowed_fields)) return app('api_error')->badRequest(null, 'Bad route.');

        # Validate token signature.
        $token = $request->input('token');
        $token_sig = '/[a-zA-Z0-9_-]+\.[a-zA-Z0-9_-]+\.[a-zA-Z0-9_-]/';
        if(!preg_match($token_sig, $token)) return app('api_error')->badRequest(null, 'Bad token.');

        # Validate token value.
        try
        {
            $claims = \Firebase\JWT\JWT::decode($token, env('APP_KEY'), ['HS256']);
            $user = app('auth')->user();
            if($user->id != $claims->jti) return app('api_error')->badRequest(null, 'Invalid token.');
        }
        catch(\Firebase\JWT\ExpiredException $e)
        {
            // return app('api_error')->unauthorized(null,"Token has expired.");
            return app('api_error')->unauthorized(['token' => ['Token has expired']]);
        }

        /**
          Process Input
        */

        # Process field update.
        $function_name = "put_{$field}";
        return $this->$function_name($request, $user);
    }

    private function put_address($request, $user)
    {
        // Value field.
        $value = $request->input('value');

        // Validate address format.
        $value = strtolower($value);
        $is_value_valid = preg_match('/^0x[0-9a-f]{40}$/i', $value);
        if(!$is_value_valid) return app('api_error')->invalidInput(['value' => ['Invalid format']]);

        // Check if address is available.
        $already_taken = \App\User::where([['address','=',$value],['id','!=',$user->id]])->count();
        if($already_taken > 0) return app('api_error')->invalidInput(['value' => ['Not available']]);

        // Update database.
        # Associated users with same address as upline.
        \App\User::where('upl_address', $user->address)->update(['upl_address'=>$value]);
        $user->address = $value;
        $user->save();

        return response()->json(['status'=>'SUCCESS', 'message'=>'Field updated.', 'user' => $user]);
    }

    private function put_email($request, $user)
    {
        // Variables
        $google2fa = new \PragmaRX\Google2FA\Google2FA;
        $plaintea = new \App\Libraries\PlainTea;
        $value = $request->input('value');
        $otp = $request->input('otp');

        # Require email & otp fields.
        if(!$value) return app('api_error')->badRequest();
        if(!$otp) return app('api_error')->badRequest();

        # Validate email format.
        if(!filter_var($value, FILTER_VALIDATE_EMAIL)) return app('api_error')->invalidInput(['value' => ['Invalid']]);

        # Validate OTP.
        $google2fa->setOneTimePasswordLength(7);
        $google2fa->setWindow($this->otp_window);
        $otp_valid = $google2fa->verifyKey(env('TWO_FACTOR_KEY'), $otp);
        if(!$otp_valid) return app('api_error')->invalidInput(['otp' => ['Incorrect']]);

        # Check if email is available.
        $already_taken = \App\User::where([['email','=',$value],['id','!=',$user->id]])->count();
        if($already_taken > 0) return app('api_error')->invalidInput(['value' => ['Not available']]);

        // Update database.
        $user->email = $value;
        $user->save();

        // Response.
        return response()->json(['status'=>'SUCCESS', 'message'=>'Field updated.', 'user' => $user]);
    }

    private function put_name($request, $user)
    {
        // Value field.
        $value = $request->input('value');

        // Validate value
        $fullname_pattern = "/^[a-z]([-']?[a-z]+\.?)*( ([a-z]\. )?[a-z]{2}([-']?[a-z]+)*)+\.?$/i";
        $is_value_valid = preg_match($fullname_pattern, $value);
        if(!$is_value_valid) return app('api_error')->invalidInput(['value' => ['Not a full name']]);

        // Update database.
        $user->name = $value;
        $user->save();

        return response()->json(['status'=>'SUCCESS', 'message'=>'Field updated.', 'user' => $user]);
    }

    private function put_password($request, $user)
    {
        // Value field.
        $value = $request->input('value');

        // Validate value
        if(strlen($value) < 6) return app('api_error')->invalidInput(['value' => ['Must be at least 6']]);

        // Update database.
        $user->password = app('hash')->make($value);
        $user->save();

        // Response.
        return response()->json(['status'=>'SUCCESS', 'message'=>'Field updated.', 'user' => $user]);
    }

    private function put_has_2fa($request, $user)
    {
        // Variables
        $google2fa = new \PragmaRX\Google2FA\Google2FA;
        $plaintea = new \App\Libraries\PlainTea;
        $value = $request->input('value');

        // Require 'status' field.
        if(!isset($value['status'])) return app('api_error')->invalidInput(['status' => ['required']]);
        $status = $value['status'];

        if(isset($value['key']) && strlen($value['key']) > 0)
        {
            $secret = $value['key'];
            if(!isset($value['otp'])) return app('api_error')->invalidInput(['otp' => ['required']]);

            $valid = $google2fa->verifyKey($secret, $value['otp']);
            if (!$valid) return app('api_error')->invalidInput(['otp' => ['incorrect']]);
        } else {
            $secret = '';
        }

        // Update database.
        $user->rand_key = (strlen($secret) > 0) ? $plaintea->encrypt($secret, env('APP_KEY')) : '';
        $user->has_2fa = ($status > 0)? 1 : 0;
        $user->save();

        // Response.
        return response()->json(['status'=>'SUCCESS', 'message'=>'Field updated.', 'user' => $user]);
    }

    private function put_secondary_email($request, $user)
    {
        // Variables
        $google2fa = new \PragmaRX\Google2FA\Google2FA;
        $plaintea = new \App\Libraries\PlainTea;
        $value = $request->input('value');
        $otp = $request->input('otp');

        # Require email & otp fields.
        if(!$value) return app('api_error')->badRequest();
        if(!$otp) return app('api_error')->badRequest();

        # Validate email format.
        if(!filter_var($value, FILTER_VALIDATE_EMAIL)) return app('api_error')->invalidInput(['value' => ['Invalid']]);

        # Validate OTP.
        $google2fa->setOneTimePasswordLength(7);
        $google2fa->setWindow($this->otp_window);
        $otp_valid = $google2fa->verifyKey(env('TWO_FACTOR_KEY'), $otp);
        if(!$otp_valid) return app('api_error')->invalidInput(['otp' => ['Incorrect']]);

        # Check if email is available.
        $already_taken = \App\User::where([['email','=',$value],['id','!=',$user->id]])->count();
        if($already_taken > 0) return app('api_error')->invalidInput(['value' => ['Not available']]);
        
        // Update database.
        $secondary_email = $user->secondary_email;
        if(!$secondary_email) $secondary_email = new \App\SecondaryEmail;

        $secondary_email->email = $value;
        $secondary_email->user_id = $user->id;
        $secondary_email->save();

        // Response.
        return response()->json(['status'=>'SUCCESS', 'message'=>'Field updated.', 'user' => $user]);
    }

    private function put_cqas($request, $user)
    {
        $value = $request->input('value');
        $errors = [];
        $has_errors = 0;
        $series_names = ['q1','q2','q3'];
        $series_checked = [];

        // Require 'value' field.
        if(!is_array($value)) return app('api_error')->invalidInput(null,'Not array.');

        // Require 'status' field.
        if(!isset($value['status'])) app('api_error')->invalidInput(null,'Missing field: \'status\'.');

        // Update.
        $status = $value['status'];
        if($status == 0)
        {
            // Update user's has_cqa status.
            $user->has_cqa = 0;
            $user->save();
            // Clear associated questions.
            \App\ChallengeQuestion::where('user_id', $user->id)->delete();
        }
        elseif($status == 1)
        {
            // Require 'data' field.
            if(!isset($value['data'])) app('api_error')->invalidInput(null,'Missing field: \'data\'. ');

            // Validate data structure.
            $data = $value['data'];
            if(count($data) != 3) return app('api_error')->invalidInput(null,'Question count is wrong: '.count($data));
            foreach($data as $item => $entry)
            {
                if(!in_array($item, $series_names)) 
                {
                    return app('api_error')->badRequest(null, 'Series name mismatch.');
                    break;
                }
                else
                {
                    if(strlen(trim($entry['q'])) < 3)
                    {
                        $errors[$item][0] = "Too short";
                        $has_errors++;
                    }
                    else {$errors[$item][0] = "";}
                    if(strlen(trim($entry['a'])) < 6)
                    {
                        $errors[$item][1] = "Too short";
                        $has_errors++;
                    } else { $errors[$item][1] = ""; }
                    $series_checked[$item] = $entry;
                }
            }
            if(count($series_checked) != 3) return app('api_error')->badRequest(['checked'=>$series_checked],'Item name duplicate.');
            if($has_errors > 0) return app('api_error')->invalidInput($errors);

            // Build data.
            $q1 = $this->makeQuestionEntry($user, 'q1', $data);
            $q2 = $this->makeQuestionEntry($user, 'q2', $data);
            $q3 = $this->makeQuestionEntry($user, 'q3', $data);

            // Update user's has_cqa status.
            $user->has_cqa = 1;
            $user->save();

            // Update or create QAs.
            \App\ChallengeQuestion::updateOrCreate(['series' => 1, 'user_id' => $user->id], $q1);
            \App\ChallengeQuestion::updateOrCreate(['series' => 2, 'user_id' => $user->id], $q2);
            \App\ChallengeQuestion::updateOrCreate(['series' => 3, 'user_id' => $user->id], $q3);
        }
        else { return app('api_error')->badRequest(null, 'Unknown field value \'status\'.'); }
        
        // Response
        return response()->json(['status'=>'SUCCESS']);
    }

    private function makeQuestionEntry($user, $index, $data)
    {
        $plaintea = new \App\Libraries\PlainTea;
        $app_key = env('APP_KEY');
        $answer_sig = strtolower(trim($data[$index]['a']).'_'.$index.'_'.$user->id.'_').$app_key;
        return [
            'question' => $plaintea->encrypt(trim($data[$index]['q']), $app_key),
            'hash' => md5($answer_sig),
            'series' => ltrim($index,'q'),
            'user_id' => $user->id
        ];
    }

    /**
     * For settings page only.
     */
    private function makeJWT($uid)
    {
        $payload = [
            "exp" => time() + (60 * 3), // 3 minutes idle
            "jti" => $uid
        ];
        return \Firebase\JWT\JWT::encode($payload, env('APP_KEY'), 'HS256');
    }
}
