<?php

namespace App\Http\Controllers;

use \App\Libraries\Helpers;
use \App\Account;
use \App\Cuk;
use \Firebase\JWT\JWT;
use Illuminate\Http\Request;
use \PragmaRX\Google2FA\Google2FA;
use \App\Libraries\PlainTea;

class AccountController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth',['only'=>['index','emailVerify']]);
        $this->middleware('throttle');
    }
    
    /**
     * Create a new JWT.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int $id
     * @return string
     *
     */
    private function makeJWT($request, $uid, $remember=false, $require_otp = false)
    {
        $useragent = $request->header('User-Agent','Unknown');
        $aud = md5(trim($useragent));
        $exp = $remember? time()+env('JWT_DURATION_LONG', 604800) : time()+env('JWT_DURATION', 7200);
        $payload = [
            "aud" => $aud,  // audience - hash of user agent
            "exp" => $exp,  // expiration (UNIX time)
            "jti" => $uid,  // JWT ID - used as user id
        ];
        if($remember) $payload['rem'] = true;
        if($require_otp) $payload['otp'] = true;
        return JWT::encode($payload, env('APP_KEY'), 'HS256');
    }

    /* Main */

    public function index(Request $request)
    {
        $user = app('auth')->user();
        return response()->json($user, 200);
    }

    public function auth(Request $request)
    {
        // Validate input.
        $inputs = $request->all();
        $validation = app('validator')->make($inputs,[
            'email' => 'required|email',
            'password' => 'required',
        ]);
        if($validation->fails()) return app('api_error')->invalidInput($validation->errors(),"Check validation data");

        $errors = [];

        // Verify account existence.
        $user = Account::where('email',$inputs['email'])->first();
        if(!$user) $errors['email'] = ['Does not exist'];

        if($user)
        {
            // Check credentials
            $authentic = app('hash')->check($inputs['password'], $user->password);
            if(!$authentic) 
            {
                $errors['password'] = ['Incorrect'];
            }
            else
            {
                // Require user to be active.
                if(!$user->active) $errors['email'] = ['Account is inactive'];
            }
        }

        // Check for errors
        if(count($errors) > 0) return app('api_error')->unauthorized($errors,"Authentication failed");

        // If no errors, issue the JWT.
        $jwt = $this->makeJWT($request, $user->id, isset($inputs['remember_me']), $user->rand_key);
        $response = response()->json(["access_token" => $jwt, "user" => $user], 200);
        $response->header('Access-Token', $jwt);
        return $response;
    }

    public function register(Request $request)
    {
        /**
        | 1 - Validate inputs
        +---------------------------------------------------------------------------------------------------------------*/
        
        // Basic input validation rules.
        $errors = [];
        $inputs = $request->only(['name', 'email', 'password', 'address', 'upl_address', 'cuk']);
        $inputs['address'] = strtolower($inputs['address']);
        $input_rules = [
            'name' => 'required|min:2',
            'email' => 'required|email|unique:users',
            'password' => 'required|min:6',
            'address' => 'required|regex:/^0x[a-z0-9]{40}$/|unique:users',
            'cuk' => 'required|size:29',
        ];

        #1.1 - Add upline address validation if present.
        if(isset($inputs['upl_address']) && !empty($inputs['upl_address']))
        {
            $inputs['upl_address'] = strtolower($inputs['upl_address']);
            $input_rules['upl_address'] = 'regex:/^0x[a-z0-9]{40}$/|exists:users,address';
        }

        #1.2 - Run validation basic.
        $validation = app('validator')->make($inputs, $input_rules, [
            'email.unique' => 'Not available',
            'email.email' => 'Format is invalid',
            'address.regex' => 'Format is invalid',
            'address.unique' => 'Not available',
            'upl_address.regex' => 'Format is invalid',
            'upl_address.exists' => 'Entry does not exist',
        ]);
        if($validation->fails()) $errors = $validation->errors()->messages();

        #1.3 - Add CUK validation.
        $cuk_hash = md5($request->input('cuk'));
        $cuk_record = Cuk::where('hash',$cuk_hash)->first();
        if(!$cuk_record)
        { $errors['cuk'][] = 'Incorrect'; }
        elseif($cuk_record->user_id !== null)
        { $errors['cuk'][] = 'Already used'; }

        #1.3 - Return validation errors if present.
        if(count($errors) > 0) return app('api_error')->invalidInput($errors);

        /**
        | 2 - Execute user's registration
        +---------------------------------------------------------------------------------------------------------------*/
        
        $success = false;
        $chamber_created = null;

        app('db')->transaction(function() use($request,&$inputs,&$cuk_record,&$success,&$chamber_created) {
            $type = 'reg'; // For applicable chamber unlock type and not 'cuk' type.
            $default_role = config('general.reg_role_id');

            #> Initialize input values.
            $inputs['upl_type'] = 'static'; // Default.
            $inputs['password'] = app('hash')->make($request->input('password'));
            $inputs['role_id'] = $default_role;

            #> Reference database objects for determining missing account inputs.
            $upline_account_record = null;
            $upline_chamber_record = null;
            $regusr_account_record = null;

            #> Reference for referral system.
            $guardian_account_record = null;

            /**
            | 2.1 - Create `users` table entry
            +-----------------------------------------------------------------------------------------------------------*/
            /*  Finalize table $inputs.
                +---------------------------------------------------------------------------------------------+
                | upl_address - Will be the field to tell the safe placement of registering user's chamber.   |
                | upl_type    - Can be 'auto' (no upl_address specified), 'static' (upl_address specified and |
                |               acquired) or 'adjust' (upl_address specified but changed because it's full).  |
                | regref_id   - Record ID of upl_address specified that will benefit in future referrals.     |
                +---------------------------------------------------------------------------------------------+
            */
            # 2.1.a - If the upl_address field was specified.
            if(isset($inputs['upl_address']) && !empty($inputs['upl_address']))
            {
                $upline_account_record = $guardian_account_record = 
                                         \App\User::where('address', $inputs['upl_address'])->first();
                $inputs['regref_id'] = $guardian_account_record->id;
                # Fetch upline's chamber record that is level 1.
                $upline_chamber_record = \App\Chamber::where([
                        ['level','=',1],
                        ['completed','<',7],
                        ['unlock_method','=','reg'],
                        ['user_id','=',$upline_account_record->id]
                    ])->first();
                # If upline's level 1 Chamber has Safe already full, get earliest level 1 Chamber instead.
                if(!$upline_chamber_record)
                {
                    $upline_chamber_record = \App\Chamber::where([
                            ['level','=',1],
                            ['completed','<',7],
                            ['unlock_method','=','reg'],
                        ])->orderBy('id','asc')->first();
                    $inputs['upl_type'] = 'adjust';
                }
            }
            # 2.1.b - If NO upl_address field specified.
            else
            {
                # Fetch the earliest incomplete chamber at level 1 that is NOT SELF UNLOCKED.
                $upline_chamber_record = \App\Chamber::where([
                        ['level','=',1],
                        ['completed','<',7],
                        ['unlock_method','=','reg']
                    ])->orderBy('id')->first();
                # If earlier chamber exist.
                if($upline_chamber_record)
                {
                    # Set the upline database object.
                    $upline_account_record = Account::find($upline_chamber_record->user_id);
                    $inputs['upl_address'] = $upline_account_record->address;
                    $inputs['upl_type'] = 'auto';
                }
            }
            #> Insert user's database record.
            $regusr_account_record = new Account($inputs);
            $regusr_account_record->save();

            /**
            | 2.2 - Update `cuks` table entry
            +-----------------------------------------------------------------------------------------------------------*/
            $cuk_record->user_id = $regusr_account_record->id;
            $cuk_record->save();

            /**
            | 2.3 - Create `referrals` table entries
            +-----------------------------------------------------------------------------------------------------------*/
            #> Reference database objects.
            $guardian_lvl_1_record = null;
            $guardian_lvl_2_record = null;
            $guardian_lvl_3_record = null;

            if($guardian_account_record)
            {
                $guardian_lvl_1_record = $guardian_account_record;

                # Level 1 - Referral entry
                $referral_1_entry = new \App\Referral;
                $referral_1_entry->user_id = $guardian_lvl_1_record->id;
                $referral_1_entry->user_reg_id = $regusr_account_record->id;
                $referral_1_entry->type = 'ref_1';
                $referral_1_entry->save();

                $guardian_lvl_2_record = \App\User::where('id', $guardian_lvl_1_record->regref_id)->first();
                if($guardian_lvl_2_record)
                {
                    // Level 2 - Referral entry.
                    $referral_2_entry = new \App\Referral;
                    $referral_2_entry->user_id = $guardian_lvl_2_record->id;
                    $referral_2_entry->user_reg_id = $regusr_account_record->id;
                    $referral_2_entry->type = 'ref_2';
                    $referral_2_entry->save();

                    $guardian_lvl_3_record = \App\User::where('id', $guardian_lvl_2_record->regref_id)->first();
                    if($guardian_lvl_3_record)
                    {
                        // Level 3 - Referral entry.
                        $referral_3_entry = new \App\Referral;
                        $referral_3_entry->user_id = $guardian_lvl_3_record->id;
                        $referral_3_entry->user_reg_id = $regusr_account_record->id;
                        $referral_3_entry->type = 'ref_3';
                        $referral_3_entry->save();
                    }
                }
            }

            /**
            | 2.4- Create `transactions` table entries & update `users` table earnings
            +-----------------------------------------------------------------------------------------------------------*/
            /* Transactions entries for referral earnings.
                +---------------------------------------------------------------------------------------------+
                | ref_1 - Earn from level 1 (direct) referrals.                                               |
                | ref_2 - Earn from level 2 (direct of downline) referrals.                                   |
                | ref_3 - Earn from level 3 (downline of downline's direct) referrals.                        |
                +---------------------------------------------------------------------------------------------+
            */
            if($guardian_lvl_1_record)
            {
                # Earnings reference.
                $earning_ref = config('general.earning_ref');

                # Level 1 - Referral earnings transaction
                $ref_1_transaction_entry = new \App\Transaction;
                $ref_1_transaction_entry->user_id = $guardian_lvl_1_record->id;
                $ref_1_transaction_entry->kta_amt = $earning_ref[1];
                $ref_1_transaction_entry->code = 'ref_1';
                $ref_1_transaction_entry->ref = $referral_1_entry->id;
                $ref_1_transaction_entry->type = 'cr';
                $ref_1_transaction_entry->complete = 1;
                $ref_1_transaction_entry->save();

                # Level 1 - Update Guardian's balance
                $guardian_lvl_1_record->balance += $earning_ref[1];
                $guardian_lvl_1_record->save();

                if($guardian_lvl_2_record)
                {
                    // Level 2 - Referral earnings transaction
                    $ref_3_transaction_entry = new \App\Transaction;
                    $ref_3_transaction_entry->user_id = $guardian_lvl_2_record->id;
                    $ref_3_transaction_entry->kta_amt = $earning_ref[2];
                    $ref_3_transaction_entry->code = 'ref_2';
                    $ref_3_transaction_entry->ref = $referral_1_entry->id;
                    $ref_3_transaction_entry->type = 'cr';
                    $ref_3_transaction_entry->complete = 1;
                    $ref_3_transaction_entry->save();

                    # Level 2 - Update Guardian's balance
                    $guardian_lvl_2_record->balance += $earning_ref[2];
                    $guardian_lvl_2_record->save();

                    $guardian_lvl_3_record = \App\User::where('id', $guardian_lvl_2_record->regref_id)->first();
                    if($guardian_lvl_3_record)
                    {
                        # Level 3 - Referral earnings transaction
                        $ref_3_transaction_entry = new \App\Transaction;
                        $ref_3_transaction_entry->user_id = $guardian_lvl_3_record->id;
                        $ref_3_transaction_entry->kta_amt = $earning_ref[3];
                        $ref_3_transaction_entry->code = 'ref_3';
                        $ref_3_transaction_entry->ref = $referral_1_entry->id;
                        $ref_3_transaction_entry->type = 'cr';
                        $ref_3_transaction_entry->complete = 1;
                        $ref_3_transaction_entry->save();

                        # Level 3 - Update Guardian's balance
                        $guardian_lvl_3_record->balance += $earning_ref[3];
                        $guardian_lvl_3_record->save();
                    }
                }
            }

            /**
            | 2.5 - Create `chambers` table entry
            +-----------------------------------------------------------------------------------------------------------*/
            # 2.5a - Make registering user's chamber entry based on upline's chamber record.
            if($upline_chamber_record)
            {
                # Extract a mapped data of upline's Safe based on its chamber location.
                /* Mapped data format.
                    +------------------------------------------------+
                    |   [                                            |
                    |       'bse' => [                               |
                    |           'location' => '2.3.2',               |
                    |           'data' => [                          |
                    |               'id' => 58,                      |
                    |               'level' => 2,                    |
                    |               'user_id' => 34,                 |
                    |               'completed' => 1,                |
                    |               'unlock_method' => reg,          |
                    |               '...' => ...                     |
                    |           ]                                    |
                    |       ],                                       |
                    |       'lft' => [                               |
                    |           'location' => '2.4.3',               |
                    |           'data' => null                       |
                    |       ],                                       |
                    |       '...' => ...                             |
                    |   ]                                            |
                    +------------------------------------------------+
                */
                $upline_safe_map = Helpers::getSafeMap($upline_chamber_record->location);

                # Create chamber placement for the newly registered user.
                #> Reference variables.
                $chamber_unlocked_location = null;
                $safe_position = null;

                # Get the location and position of first empty chamber in upline safe.
                foreach($upline_safe_map as $position => $chamber)
                {
                    if($chamber['data'] === null)
                    {
                        if($chamber_unlocked_location === null)
                        {
                            $chamber_unlocked_location = $chamber['location'];
                            $safe_position = $position;
                            break;
                        }
                    }
                }

                # Create new chamber entry for the user based on location found.
                $reg_user_chamber = new \App\Chamber;
                $reg_user_chamber->level = 1;
                $reg_user_chamber->user_id = $regusr_account_record->id;
                $reg_user_chamber->completed = 1;
                $reg_user_chamber->location = $chamber_unlocked_location;
                $reg_user_chamber->unlock_method = 'reg';
                $reg_user_chamber->save();

                # Set a chamber creation event to be emited.
                $chamber_created = [ 'location'=>$chamber_unlocked_location, 'user_id'=>$regusr_account_record->id ];
            }
            # 2.5b - Make chamber entry for genesis account (no existing upline record found).
            else
            {
                $reg_user_chamber = new \App\Chamber;
                $reg_user_chamber->level = 1;
                $reg_user_chamber->user_id = $regusr_account_record->id;
                $reg_user_chamber->completed = 1;
                $reg_user_chamber->location = '1.1.1';
                $reg_user_chamber->unlock_method = 'reg';
                $reg_user_chamber->save();

                # Set a chamber creation event to be emited.
                $chamber_created = [ 'location'=>'1.1.1', 'user_id'=>$regusr_account_record->id ];
            }
            $success = ["message" => "New account created.", "data" => $regusr_account_record];
        }, 5);

        if($success)
        {
            if($chamber_created)
            {
                event(new \App\Events\ChamberCreatedEvent($chamber_created['location'],$chamber_created['user_id']));
            }
            return response()->json($success, 200);
        }
        else
        {
            return app('api_error')->serverError(null,'Failed to register user.');
        }
    }

    public function authVerify(Request $request, Google2FA $google2fa, PlainTea $PlainTea)
    {
        // Validate OTP format.
        $otp_code = $request->input('otp');
        $valid_otp = preg_match('/^\d{6}$/', $otp_code);
        if(!$valid_otp) return app('api_error')->invalidInput();

        // Validate OTP value.
        $user = app('auth')->user();
        $secret = $PlainTea->decrypt($user->rand_key, env('APP_KEY'));
        $verified = $google2fa->verifyKey($secret, (string) $otp_code);
        if(!$verified) return app('api_error')->invalidInput(null, 'Incorrect');

        // Issue new token.
        $payload = (array) config('jwt.claims');
        unset($payload['otp']);
        $jwt = \Firebase\JWT\JWT::encode($payload, env('APP_KEY'), 'HS256');
        return response()->json(["access_token" => $jwt, "user" => $user], 200, ['Access-Token' => $jwt]);
    }

    public function emailVerify(Request $request, Google2FA $google2fa)
    {
        
        /**
         Validate Input
        */

        #1 - Whitelist for allowed types.
        $allowed_types = ['primary','secondary'];
        
        #2 - Require 'type' field & validate.

        #2.1 - Require.
        $type = $request->input('type');
        if(!$type) return app('api_error')->badRequest();

        #2.2 - Validate value.
        if(!in_array($type, $allowed_types)) return app('api_error')->badRequest();
        
        #3 - Require 'value' field & validate.

        #3.1 - Require.
        $value = $request->input('value');
        if(!$value) return app('api_error')->badRequest();

        #3.2 - Validate format.
        if(!filter_var($value, FILTER_VALIDATE_EMAIL)) return app('api_error')->invalidInput(['value' => ['Invalid']]);

        #3.3 - Check if available.
        $user = app('auth')->user();
        $primary_taken = \App\User::where([['email', '=', $value], ['id', '!=', $user->id]])->count();
        if($primary_taken > 0) return app('api_error')->invalidInput(['value' => ['Not available']]);
        if($type == 'primary')
        {
            $current_secondary = $user->secondary_email;
            if($current_secondary) if($current_secondary->email == $value) return app('api_error')->invalidInput(['value' => ['Not available']]);
        }
        if($type == 'secondary')
        {
            if($user->email == $value) return app('api_error')->invalidInput(['value' => ['Cannot be the same as primary']]);
            $secondary_taken = \App\SecondaryEmail::where([['email', '=', $value], ['user_id', '!=', $user->id]])->count();
            if($secondary_taken > 0) return app('api_error')->invalidInput(['value' => ['Not available']]);
        }

        /**
         Generate and Send OTP
        */

        #4.1 - Generate OTP
        $google2fa->setOneTimePasswordLength(7);
        $otp = $google2fa->getCurrentOtp(env('TWO_FACTOR_KEY'));
        $otp_exp = env('OTP7_EXPIRY');

        #4.2 - Emit email verify event for listener to process.
        $env = env('APP_ENV');
        if($env == 'staging' || $env == 'production') event(new \App\Events\EmailVerifyEvent($value, $otp, $otp_exp));

        /**
         Response
        */
        $response = [
            'status'=>'SUCCESS',
            'message'=>'OTP has been sent to email.',
            'data' => [
                'email' => $value,
                'primary' => $user->email,
                'expiry' => $otp_exp
            ]
        ];
        return response()->json($response, 200);
    }

    public function recover(Request $request)
    {
        $user_id  = $request->input('jti');
        $txn_ref  = $request->input('ref');
        $password = $request->input('password');

        if($user_id && $txn_ref && $password)
        {
            $user = \App\User::where([['id','=',$user_id],['txn_token','=',$txn_ref]])->first();
            if(!$user) return app('api_error')->badRequest();
            if(strlen($password) < 6) return app('api_error')->invalidInput(null, ['password'=>['Too short']]);
            $user->password = app('hash')->make($password);
            $user->txn_token = '';
            $user->save();

            // Response.
            $success = [
                "status" => "SUCCESS",
                "message" => "New password saved."
            ];
            return response()->json($success);
        } else return app('api_error')->badRequest();
    }

    public function recoverNewRequest(Request $request)
    {
        $email = $request->input('email');
        $method = $request->input('method');
        $allowed_methods = ['link','cqa'];

        // Validate input.
        if(!filter_var($email, FILTER_VALIDATE_EMAIL)) return app('api_error')->invalidInput(['email' => ['Invalid']]);
        if(!$method) return app('api_error')->invalidInput(['method' => ['Required']]);
        if(!in_array($method, $allowed_methods)) return app('api_error')->invalidInput(['method' => ['Unknown']]);

        // Validate value.
        $user = \App\User::where('email', $email)->first();
        if(!$user)
        { // Check for secondary email.
            $secondary = \App\SecondaryEmail::where('email', $email)->first();
            if(!$secondary) return app('api_error')->invalidInput(['email' => ['Unrecognized']]);
            $user = $secondary->user;
            if(!$user) return app('api_error')->invalidInput(['email' => ['No user']]);
        }
        if($user->active == 0) return app('api_error')->invalidInput(['email' => ['Account is inactive']]);

        // Process request.
        if($method === $allowed_methods[0])
        {
            return $this->recoverViaEmail($user, $email);
        }
        elseif($method == $allowed_methods[1])
        {
            return $this->recoverViaQuestion($user, $email);
        }
        else
        {
            return app('api_error')->badRequest();
        }
    }

    public function verifyResetToken($token)
    {
        # Verify token.
        $claims = JWT::decode($token, env('APP_KEY'), ['HS256']);

        # Verify data.
        if(isset($claims->jti) && isset($claims->ref))
        {
            $existing = \App\User::where([['id','=',$claims->jti],['txn_token','=',$claims->ref]])->count();
            if($existing)
            {
                return response()->json(['status'=>'SUCCESS', 'data'=>$claims]);
            } else return app('api_error')->badRequest();
        } else return app('api_error')->badRequest();
    }

    public function checkAnswer(Request $request)
    {
        $req_qid = $request->input('qid');
        $req_answer = $request->input('answer');

        // Require fields.
        if(!is_numeric($req_qid) && !$req_answer) return app('api_error')->invalidInput();

        // Validate values.
        $question = \App\ChallengeQuestion::find($req_qid);
        if(!$question) return app('api_error')->badRequest(null, 'Question not found. '.$req_qid);
        $user = $question->user;
        if(!$user) return app('api_error')->badRequest(null, 'User not found.');

        $app_key = env('APP_KEY');
        $index = 'q'.$question->series;
        $answer_sig = strtolower(trim($req_answer).'_'.$index.'_'.$user->id.'_').$app_key;
        if($question->hash != md5($answer_sig)) return app('api_error')->invalidInput(['answer' => ['Incorrect']]);

        // Issue JWT
        // Construct JWT.
        $now = time();
        $ref = dechex($now);
        $payload = [
            "exp" => ($now + (60 * 5)), // To expire in 5 minutes.
            "jti" => $user->id,
            "ref" => $ref
        ];
        $jwt = \Firebase\JWT\JWT::encode($payload, $app_key, 'HS256');

        // Update transaction reference.
        $user->txn_token = $ref;
        $user->save();

        // Response.
        $success = [
            "status" => "SUCCESS",
            "message" => "Answer is correct.",
            "data" => [
                "token" => $jwt
            ]
        ];
        return response()->json($success);

    }

    private function recoverViaEmail($user, $email)
    {
        // Construct JWT.
        $now = time();
        $ref = dechex($now);
        $payload = [
            "exp" => ($now + env('JWT_LINK_EXPIRY')),
            "jti" => $user->id,
            "ref" => $ref
        ];
        $jwt = \Firebase\JWT\JWT::encode($payload, env('APP_KEY'), 'HS256');

        // Update transaction reference.
        $user->txn_token = $ref;
        $user->save();

        // Emit reset password request event.
        $env = env('APP_ENV');
        if($env == 'staging' || $env == 'production') event(new \App\Events\PwResetReqEvent($email, $jwt));

        // Response.
        $success = [
            "status" => "SUCCESS",
            "message" => "Reset link sent to email."
        ];
        return response()->json($success);
    }

    private function recoverViaQuestion($user, $email)
    {
        $questions = $user->challenge_questions;

        // Verify
        $items = count($questions);
        if($items == 0) return app('api_error')->invalidInput(['email' => ['Questions disabled']]);

        $plaintea = new PlainTea;
        $index = rand(0, $items-1);
        $question = $questions[$index];

        // Response
        $response = [
            'status' => 'SUCCESS',
            'data' => [
                'qid' => $question->id,
                'question' => $plaintea->decrypt($question->question, env('APP_KEY'))
            ]
        ];
        return response()->json($response);
    }
}
