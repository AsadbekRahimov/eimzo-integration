<?php

namespace AsadbekRahimov\EimzoIntegration\Tests;

class MobileDisabledRouteTest extends TestCase
{
    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);

        $app['config']->set('eimzo.mobile.enabled', false);
    }

    public function test_disabling_mobile_removes_all_mobile_routes_and_browser_config(): void
    {
        $this->postJson('/eimzo/mobile/auth/start')->assertNotFound();
        $this->postJson('/api/eimzo/mobile/auth/start')->assertNotFound();
        $this->post('/frontend/mobile/upload')->assertNotFound();

        $response = $this->get('/eimzo/login');
        $response->assertOk();
        $response->assertDontSee('window.EIMZO_MOBILE_CONFIG', false);
    }
}
