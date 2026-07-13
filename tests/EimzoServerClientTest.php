<?php

namespace AsadbekRahimov\EimzoIntegration\Tests;

use Illuminate\Http\Request;
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

    public function test_absolute_frontend_root_keeps_frontend_endpoint_prefix(): void
    {
        Config::set('eimzo.server_url', 'http://185.74.4.123:8080');
        Config::set('eimzo.frontend_url', 'http://127.0.0.1:8080');

        $urls = [];
        Http::fake(function ($request) use (&$urls) {
            $urls[] = $request->url();

            return Http::response(['challenge' => 'direct-java'], 200);
        });

        $response = (new EimzoServerClient(Http::getFacadeRoot()))->challenge('127.0.0.1');

        $this->assertSame('direct-java', $response['challenge']);
        $this->assertSame(['http://127.0.0.1:8080/frontend/challenge'], $urls);
    }

    public function test_default_backend_host_header_excludes_request_port(): void
    {
        Config::set('eimzo.server_url', 'http://127.0.0.1:8080');
        Config::set('eimzo.request_host', null);
        $this->app->instance('request', Request::create('https://crm.example.test:8443/eimzo/login'));

        $hosts = [];
        Http::fake(function ($request) use (&$hosts) {
            $hosts[] = $request->header('Host')[0] ?? null;

            return Http::response(['status' => 1], 200);
        });

        (new EimzoServerClient(Http::getFacadeRoot()))->authenticate('PKCS7', '127.0.0.1');

        $this->assertSame(['crm.example.test'], $hosts);
    }
}
