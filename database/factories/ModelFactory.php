<?php

/*
|--------------------------------------------------------------------------
| Model Factories
|--------------------------------------------------------------------------
|
| Here you may define all of your model factories. Model factories give
| you a convenient way to create models for testing and seeding your
| database. Just tell the factory how a default model should look.
|
*/

$factory->define(App\User::class, function(Faker\Generator $faker) {
    $fname = $faker->firstName;
    $lname = $faker->lastName;
    $domain = $faker->freeEmailDomain;
    return [
        'name' => "{$fname} {$lname}",
        'email' => strtolower("{$fname}.{$lname}@{$domain}"),
        'password' => app('hash')->make('password123'),
        'address' => '0x'.sha1(microtime()),
        'role_id' => 2
    ];
});

$factory->define(App\Cuk::class, function() {
    $cuk = \App\Libraries\Helpers::genCUK();
    return [
        'code' => \App\Libraries\PlainTea::encrypt($cuk,env('APP_KEY')),
        'hash' => md5($cuk)
    ];
});
