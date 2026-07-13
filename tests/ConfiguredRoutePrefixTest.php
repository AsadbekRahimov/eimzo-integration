<?php

namespace AsadbekRahimov\EimzoIntegration\Tests;

class ConfiguredRoutePrefixTest extends TestCase
{
    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);

        $app['config']->set('eimzo.routes.prefix', 'custom-eimzo');
    }

    public function test_bundled_pages_and_browser_routes_follow_configured_prefix(): void
    {
        $response = $this->get('/custom-eimzo/login');

        $response->assertOk();
        $body = str_replace('\\/', '/', $response->getContent());
        $this->assertStringContainsString('/custom-eimzo/auth/challenge', $body);
        $this->assertStringContainsString('/custom-eimzo/sign', $body);
        $this->assertStringNotContainsString('"/eimzo/auth/challenge"', $body);
        $this->get('/eimzo/login')->assertNotFound();
    }
}
