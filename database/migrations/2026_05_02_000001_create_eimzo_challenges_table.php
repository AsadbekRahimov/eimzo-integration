<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateEimzoChallengesTable extends Migration
{
    public function up()
    {
        Schema::create(config('eimzo.tables.challenges', 'eimzo_challenges'), function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('challenge', 255)->unique();
            $table->string('purpose', 32)->default('auth')->index();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists(config('eimzo.tables.challenges', 'eimzo_challenges'));
    }
}
