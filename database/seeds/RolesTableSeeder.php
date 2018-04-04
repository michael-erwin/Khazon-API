<?php

use Illuminate\Database\Seeder;
use \App\Role;

class RolesTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $roles = [
            [
                'id'            => 1,
                'name'          => 'admin',
                'permissions'   => 'all',
                'description'   => 'System administrator.'
            ],
            [
                'id'            => 2,
                'name'          => 'member',
                'permissions'   => 'account_r,account_u',
                'description'   => 'Registered user.'
            ],
        ];
        Role::insert($roles);
    }
}
