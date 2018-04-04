<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateTableUsers extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password',60);
            $table->string('address',42)->unique();
            $table->string('balance')->default('0');
            $table->string('upl_address',42)->nullable();
            $table->string('upl_type',6)->default('static');
            $table->integer('regref_id')->nullable();
            $table->string('rand_key',32)->nullable();
            $table->boolean('has_2fa')->default(0);
            $table->boolean('has_cqa')->default(0);
            $table->boolean('active')->default(1);
            $table->string('txn_token',32)->nullable();
            $table->unsignedSmallInteger('role_id')->nullable();
            $table->timestamps();
            $table->index(['upl_address','regref_id','rand_key']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('users');
    }
}
