<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateTableChambers extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('chambers', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('level');
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('completed')->default(0);
            $table->string('location');
            $table->enum('unlock_method',['reg','cuk']);
            $table->timestamps();
            $table->index(['user_id','location','completed']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('chambers');
    }
}
