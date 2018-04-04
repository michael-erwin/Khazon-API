<?php

use Illuminate\Database\Seeder;

class CuksTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        factory(App\Cuk::class, 15)->create();
    }
}
