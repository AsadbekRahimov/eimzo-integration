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
        Config::set('eimzo.api_keys', ['example.uz', 'issued-key']);

        $response = $this->get('/eimzo/login');

        $response->assertOk();
        $response->assertSee('window.EIMZO_API_KEYS', false);
        $response->assertSee('"example.uz"', false);
        $response->assertSee('"issued-key"', false);
    }
}
