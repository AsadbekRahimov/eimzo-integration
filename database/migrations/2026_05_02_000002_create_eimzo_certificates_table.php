<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateEimzoCertificatesTable extends Migration
{
    public function up()
    {
        Schema::create(config('eimzo.tables.certificates', 'eimzo_certificates'), function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('user_id')->nullable()->index();

            $table->string('serial_number', 64)->index();
            $table->string('cn', 255)->nullable();
            $table->string('tin', 32)->nullable()->index();
            $table->string('pinfl', 32)->nullable()->index();
            $table->string('uid', 64)->nullable()->index();
            $table->string('o', 255)->nullable();
            $table->string('t', 255)->nullable();
            $table->string('country', 8)->nullable();
            $table->string('email', 255)->nullable();

            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_to')->nullable()->index();

            $table->text('subject_dn')->nullable();
            $table->text('issuer_dn')->nullable();

            $table->longText('certificate_pem')->nullable();

            $table->json('last_verify_payload')->nullable();
            $table->timestamp('last_verified_at')->nullable();

            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists(config('eimzo.tables.certificates', 'eimzo_certificates'));
    }
}
