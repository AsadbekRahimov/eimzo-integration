<?php

namespace AsadbekRahimov\EimzoIntegration\Tests;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use AsadbekRahimov\EimzoIntegration\Services\EimzoServerClient;
use AsadbekRahimov\EimzoIntegration\Tests\TestCase;

class EimzoServerClientTest extends TestCase
{
    public function test_frontend_endpoints_can_use_same_origin_nginx_proxy(): void
    {
        Config::set('eimzo.server_url', 'http://185.74.4.123:8080');
        Config::set('eimzo.frontend_url', '/frontend');

        $urls = [];
        Http::fake(function ($request) use (&$urls) {
            $urls[] = $request->url();

            return Http::response(['challenge' => 'proxied'], 200);
        });

        $response = (new EimzoServerClient(Http::getFacadeRoot()))->challenge('127.0.0.1');

        $this->assertSame('proxied', $response['challenge']);
        $this->assertSame([rtrim(config('app.url'), '/') . '/frontend/challenge'], $urls);
    }

    public function test_backend_endpoints_keep_using_eimzo_server_url(): void
    {
        Config::set('eimzo.server_url', 'http://185.74.4.123:8080');
        Config::set('eimzo.frontend_url', '/frontend');

        $urls = [];
        Http::fake(function ($request) use (&$urls) {
            $urls[] = $request->url();

            return Http::response(['status' => 1], 200);
        });

        $response = (new EimzoServerClient(Http::getFacadeRoot()))->authenticate('PKCS7', '127.0.0.1');

        $this->assertSame(1, $response['status']);
        $this->assertSame(['http://185.74.4.123:8080/backend/auth'], $urls);
    }
}
