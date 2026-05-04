<?php

namespace AsadbekRahimov\EimzoIntegration\Tests;

use AsadbekRahimov\EimzoIntegration\Tests\TestCase;

class ExamplePagesTest extends TestCase
{
    public function test_example_index_links_to_all_eimzo_usage_examples(): void
    {
        $response = $this->get('/eimzo/examples');

        $response->assertOk();
        $response->assertSee('/eimzo/examples/login', false);
        $response->assertSee('/eimzo/examples/document-sign', false);
        $response->assertSee('/eimzo/examples/action-sign', false);
    }

    public function test_login_example_explains_challenge_storage(): void
    {
        $response = $this->get('/eimzo/examples/login');

        $response->assertOk();
        $response->assertSee('eimzo_challenges', false);
        $response->assertSee('eimzo_certificates', false);
        $response->assertSee('eimzo_signatures', false);
    }

    public function test_document_sign_example_explains_signature_columns(): void
    {
        $response = $this->get('/eimzo/examples/document-sign');

        $response->assertOk();
        $response->assertSee('document_hash', false);
        $response->assertSee('pkcs7_with_timestamp', false);
        $response->assertSee('verification_payload', false);
    }

    public function test_action_sign_example_shows_canonical_crm_payload(): void
    {
        $response = $this->get('/eimzo/examples/action-sign');

        $response->assertOk();
        $response->assertSee('approve_invoice', false);
        $response->assertSee('canonical JSON', false);
        $response->assertSee('signature_id', false);
    }
}
