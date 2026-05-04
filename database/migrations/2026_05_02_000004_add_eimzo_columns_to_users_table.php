<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddEimzoColumnsToUsersTable extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'tin')) {
                $table->string('tin', 32)->nullable()->unique()->after('email');
            }
            if (! Schema::hasColumn('users', 'pinfl')) {
                $table->string('pinfl', 32)->nullable()->unique()->after('tin');
            }
            if (! Schema::hasColumn('users', 'eimzo_serial_number')) {
                $table->string('eimzo_serial_number', 64)->nullable()->index()->after('pinfl');
            }
            if (! Schema::hasColumn('users', 'eimzo_full_name')) {
                $table->string('eimzo_full_name', 255)->nullable()->after('eimzo_serial_number');
            }
            if (! Schema::hasColumn('users', 'eimzo_authenticated_at')) {
                $table->timestamp('eimzo_authenticated_at')->nullable()->after('eimzo_full_name');
            }
        });
    }

    public function down()
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            foreach (['eimzo_authenticated_at', 'eimzo_full_name', 'eimzo_serial_number', 'pinfl', 'tin'] as $col) {
                if (Schema::hasColumn('users', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
}
