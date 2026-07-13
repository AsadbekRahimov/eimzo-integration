<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class EnforceUniqueEimzoCertificateSerials extends Migration
{
    public function up()
    {
        $certificates = config('eimzo.tables.certificates', 'eimzo_certificates');
        $signatures = config('eimzo.tables.signatures', 'eimzo_signatures');

        // Earlier releases documented serial_number as unique but created a
        // normal index. Consolidate any race-created duplicates before adding
        // the database constraint that EimzoCertificate relies on.
        $duplicates = DB::table($certificates)
            ->select('serial_number')
            ->groupBy('serial_number')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('serial_number');

        foreach ($duplicates as $serial) {
            $ids = DB::table($certificates)
                ->where('serial_number', $serial)
                ->orderBy('id')
                ->pluck('id')
                ->all();

            $keep = array_shift($ids);
            if ($keep === null || $ids === []) {
                continue;
            }

            DB::table($signatures)->whereIn('certificate_id', $ids)->update(['certificate_id' => $keep]);
            DB::table($certificates)->whereIn('id', $ids)->delete();
        }

        $index = $this->indexName($certificates);
        Schema::table($certificates, function (Blueprint $table) use ($index) {
            $table->unique('serial_number', $index);
        });
    }

    public function down()
    {
        $certificates = config('eimzo.tables.certificates', 'eimzo_certificates');
        $index = $this->indexName($certificates);

        Schema::table($certificates, function (Blueprint $table) use ($index) {
            $table->dropUnique($index);
        });
    }

    private function indexName(string $table): string
    {
        return 'eimzo_cert_serial_unique_' . substr(md5($table), 0, 8);
    }
}
