<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use \Firebase\JWT\JWT;
use \App\Libraries\PlainTea;

class JwtTestController extends Controller
{
    public function handle(Request $request)
    {
        $key = env('APP_KEY');
        $aud = $
        $token = [
            "iss" => env('APP_NAME'),  // issuer - use app name & api version
            "aud" => "http://example.com",  // audience - use hash of user agent
            "iat" => 1356999524,            // issued at (UNIX time)
            "nbf" => 1357000000,            // not before (UNIX time)
            // "exp" => 1386000000,            // expiration (UNIX time)
            "jti" => 12                     // JWT ID - use as user id
        ];

        $jwt = JWT::encode($token, $key);
        $jwt_decoded = JWT::decode($jwt,$key,['HS256']);

        header('Content-Type: text/plain');
        print_r($jwt);
        echo "\n";
        print_r($jwt_decoded);
    }

    public function rs256(Request $request)
    {
        $privateKey = <<<EOD
-----BEGIN RSA PRIVATE KEY-----
MIICXAIBAAKBgQC8kGa1pSjbSYZVebtTRBLxBz5H4i2p/llLCrEeQhta5kaQu/Rn
vuER4W8oDH3+3iuIYW4VQAzyqFpwuzjkDI+17t5t0tyazyZ8JXw+KgXTxldMPEL9
5+qVhgXvwtihXC1c5oGbRlEDvDF6Sa53rcFVsYJ4ehde/zUxo6UvS7UrBQIDAQAB
AoGAb/MXV46XxCFRxNuB8LyAtmLDgi/xRnTAlMHjSACddwkyKem8//8eZtw9fzxz
bWZ/1/doQOuHBGYZU8aDzzj59FZ78dyzNFoF91hbvZKkg+6wGyd/LrGVEB+Xre0J
Nil0GReM2AHDNZUYRv+HYJPIOrB0CRczLQsgFJ8K6aAD6F0CQQDzbpjYdx10qgK1
cP59UHiHjPZYC0loEsk7s+hUmT3QHerAQJMZWC11Qrn2N+ybwwNblDKv+s5qgMQ5
5tNoQ9IfAkEAxkyffU6ythpg/H0Ixe1I2rd0GbF05biIzO/i77Det3n4YsJVlDck
ZkcvY3SK2iRIL4c9yY6hlIhs+K9wXTtGWwJBAO9Dskl48mO7woPR9uD22jDpNSwe
k90OMepTjzSvlhjbfuPN1IdhqvSJTDychRwn1kIJ7LQZgQ8fVz9OCFZ/6qMCQGOb
qaGwHmUK6xzpUbbacnYrIM6nLSkXgOAwv7XXCojvY614ILTK3iXiLBOxPu5Eu13k
eUz9sHyD6vkgZzjtxXECQAkp4Xerf5TGfQXGXhxIX52yH+N2LtujCdkQZjXAsGdm
B2zNzvrlgRmgBrklMTrMYgm1NPcW+bRLGcwgW2PTvNM=
-----END RSA PRIVATE KEY-----
EOD;
        
        $publicKey = <<<EOD
-----BEGIN PUBLIC KEY-----
MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQKBgQC8kGa1pSjbSYZVebtTRBLxBz5H
4i2p/llLCrEeQhta5kaQu/RnvuER4W8oDH3+3iuIYW4VQAzyqFpwuzjkDI+17t5t
0tyazyZ8JXw+KgXTxldMPEL95+qVhgXvwtihXC1c5oGbRlEDvDF6Sa53rcFVsYJ4
ehde/zUxo6UvS7UrBQIDAQAB
-----END PUBLIC KEY-----
EOD;
        
        $token = [
            "iss" => "example.org",
            "aud" => "example.com",
            "iat" => 1356999524,
            "nbf" => 1357000000
        ];
        
        header('Content-Type: text/plain');

        $jwt = JWT::encode($token, $privateKey, 'RS256');
        echo "Encode:\n" . print_r($jwt, true) . "\n";
        
        $decoded = JWT::decode($jwt, $publicKey, ['RS256']);
        
        /*
         NOTE: This will now be an object instead of an associative array. To get
         an associative array, you will need to cast it as such:
        */
        
        $decoded_array = (array) $decoded;
        echo "Decode:\n" . print_r($decoded_array, true) . "\n";
    }

    public function tea()
    {
        $perm = ['post_add','post_edit','post_delete'];
        $perm_json = json_encode($perm);
        // return PlainTea::encrypt($perm_json,env('APP_KEY'));
        return PlainTea::decrypt('WK7tog93EKPUE1VKMW71DXuCL4C+Maotmbr6NJ7ZKiBJ7UtztmFtyVd9GVw=',env('APP_KEY'));
    }

    public function test()
    {
        return $request->header('User-Agent','Unknown');
    }
}
