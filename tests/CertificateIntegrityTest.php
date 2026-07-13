<?php

namespace AsadbekRahimov\EimzoIntegration\Tests;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use AsadbekRahimov\EimzoIntegration\Models\EimzoCertificate;

class CertificateIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_enforces_one_certificate_row_per_serial(): void
    {
        EimzoCertificate::create(['serial_number' => 'UNIQUE123']);

        $this->expectException(QueryException::class);
        EimzoCertificate::create(['serial_number' => 'UNIQUE123']);
    }
}
