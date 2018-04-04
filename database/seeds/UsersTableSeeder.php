<?php

use Illuminate\Database\Seeder;
use \App\User;

class UsersTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $default_pw = app('hash')->make(env('DEFAULT_PASS', 'default'));
        $users = [
            [
                'name'      => 'One User Admin',
                'email'     => 'admin_01@khazon.online',
                'password'  => $default_pw,
                'address'   => '0x'.sha1(microtime()),
                'role_id'   => 1
            ],
            [
                'name'      => 'Two User Admin',
                'email'     => 'admin_02@khazon.online',
                'password'  => $default_pw,
                'address'   => '0x'.sha1(microtime()),
                'role_id'   => 1
            ]
        ];
        User::insert($users);
    }
}
