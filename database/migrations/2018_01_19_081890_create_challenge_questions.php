<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateChallengeQuestions extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('challenge_questions', function (Blueprint $table) {
            $table->increments('id');
            $table->text('question');
            $table->string('hash',32);
            $table->enum('series',['1','2','3']);
            $table->integer('user_id');
            $table->timestamps();
            $table->index(['user_id','hash']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('challenge_questions');
    }
}
