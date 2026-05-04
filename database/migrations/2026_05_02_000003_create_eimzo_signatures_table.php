<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateEimzoSignaturesTable extends Migration
{
    public function up()
    {
        Schema::create(config('eimzo.tables.signatures', 'eimzo_signatures'), function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->nullableMorphs('signable');

            $table->unsignedBigInteger('user_id')->nullable()->index();

            $table->unsignedBigInteger('certificate_id')->nullable()->index();

            $table->string('document_type', 64)->nullable();
            $table->string('document_name', 255)->nullable();
            $table->unsignedBigInteger('document_size')->nullable();
            $table->string('document_hash', 128)->nullable()->index();

            $table->longText('pkcs7')->nullable();
            $table->string('pkcs7_path', 512)->nullable();
            $table->boolean('detached')->default(false);

            $table->longText('pkcs7_with_timestamp')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->timestamp('timestamp_at')->nullable();

            $table->string('verification_status', 32)->default('pending');
            $table->json('verification_payload')->nullable();
            $table->timestamp('verified_at')->nullable();

            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists(config('eimzo.tables.signatures', 'eimzo_signatures'));
    }
}
