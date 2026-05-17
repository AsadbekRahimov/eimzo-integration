<?php

namespace AsadbekRahimov\EimzoIntegration\Tests;

use Illuminate\Support\Facades\Config;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use AsadbekRahimov\EimzoIntegration\Tests\TestCase;

class AssetRouteTest extends TestCase
{
    public function test_bundled_browser_assets_are_served_from_public_vendor_paths(): void
    {
        foreach ([
            '/vendor/eimzo/vendor/e-imzo.js',
            '/vendor/eimzo/vendor/e-imzo-client.js',
            '/vendor/eimzo/eimzo.js',
        ] as $path) {
            $response = $this->get($path);

            $response->assertOk();
            $this->assertStringContainsString('javascript', $response->headers->get('content-type', ''));
            $this->assertInstanceOf(BinaryFileResponse::class, $response->baseResponse);
            $this->assertTrue($response->baseResponse->getFile()->isFile());
        }
    }

    public function test_demo_layout_exposes_configured_api_keys_to_the_browser_bridge(): void
    {
        // Testbench requests come in as host=localhost, so register the key
        // under that host - the layout now filters to the request host.
        Config::set('eimzo.api_keys', ['localhost' => 'issued-key']);

        $response = $this->get('/eimzo/login');

        $response->assertOk();
        $response->assertSee('window.EIMZO_API_KEYS', false);
        $response->assertSee('"localhost"', false);
        $response->assertSee('"issued-key"', false);
    }

    public function test_demo_layout_hides_keys_for_other_domains(): void
    {
        Config::set('eimzo.api_keys', [
            'localhost' => 'KEY-FOR-LOCALHOST',
            'production.example.uz' => 'SECRET-PRODUCTION-KEY',
        ]);

        $response = $this->get('/eimzo/login');

        $response->assertOk();
        $response->assertSee('"KEY-FOR-LOCALHOST"', false);
        // Other domains' keys must NOT leak into a different host's page.
        $response->assertDontSee('SECRET-PRODUCTION-KEY', false);
        $response->assertDontSee('production.example.uz', false);
    }

    public function test_demo_layout_accepts_legacy_flat_pair_array(): void
    {
        // Backward-compat: pre-1.x docs shipped api_keys as flat [d, k, d, k].
        Config::set('eimzo.api_keys', ['localhost', 'legacy-flat-key']);

        $response = $this->get('/eimzo/login');

        $response->assertOk();
        $response->assertSee('"localhost"', false);
        $response->assertSee('"legacy-flat-key"', false);
    }
}
