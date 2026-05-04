<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class ChangeEimzoChallengeColumnToString extends Migration
{
    public function up()
    {
        $table = config('eimzo.tables.challenges', 'eimzo_challenges');
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE {$table} ALTER COLUMN challenge TYPE varchar(255) USING challenge::text");
            return;
        }

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE {$table} MODIFY challenge varchar(255) NOT NULL");
        }
    }

    public function down()
    {
        $table = config('eimzo.tables.challenges', 'eimzo_challenges');

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE {$table} ALTER COLUMN challenge TYPE uuid USING challenge::uuid");
        }
    }
}
