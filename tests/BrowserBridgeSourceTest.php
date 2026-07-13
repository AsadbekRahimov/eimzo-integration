<?php

namespace AsadbekRahimov\EimzoIntegration\Tests;

use AsadbekRahimov\EimzoIntegration\Tests\TestCase;

class BrowserBridgeSourceTest extends TestCase
{
    public function test_bridge_resolves_list_all_user_keys_items_argument(): void
    {
        $source = file_get_contents(__DIR__.'/../resources/js/eimzo.js');

        $this->assertStringContainsString('(items, firstId) => resolve(items || [])', $source);
        $this->assertStringContainsString('(keyId) => resolve(keyId)', $source);
    }

    public function test_bridge_uses_server_rendered_routes_and_configured_default_mode(): void
    {
        $source = file_get_contents(__DIR__.'/../resources/js/eimzo.js');

        $this->assertStringContainsString('global.EIMZO_ROUTES || {}', $source);
        $this->assertStringContainsString('global.EIMZO_SIGN_DEFAULT_DETACHED', $source);
        $this->assertStringContainsString('options.dataIsBase64', $source);
        $this->assertStringContainsString('options.data_is_base64', $source);
    }

    public function test_mobile_bridge_applies_server_poll_settings_and_fails_unknown_statuses(): void
    {
        $source = file_get_contents(__DIR__.'/../resources/js/eimzo-mobile.js');

        $this->assertStringContainsString('global.EIMZO_MOBILE_CONFIG || {}', $source);
        $this->assertStringContainsString('response.poll_timeout', $source);
        $this->assertStringContainsString('response.poll_interval_ms', $source);
        $this->assertStringContainsString('if (r && r.status !== 2)', $source);
        $this->assertStringContainsString('document = decodeBase64(documentBase64)', $source);
        $this->assertStringContainsString('makeQrPayload(res.site_id, res.document_id, document)', $source);
        $this->assertStringNotContainsString('CryptoJS.SHA256', $source);
    }

    public function test_vendor_client_scans_pfx_and_token_keys_for_new_api_versions(): void
    {
        $source = file_get_contents(__DIR__.'/../resources/js/vendor/e-imzo-client.js');
        $start = strpos($source, 'listAllUserKeys: function');
        $end = strpos($source, 'idCardIsPLuggedIn: function');

        $this->assertIsInt($start);
        $this->assertIsInt($end);

        $listAllUserKeys = substr($source, $start, $end - $start);

        $this->assertStringContainsString('_findPfxs2(itemIdGen, itemUiGen, items, errors, function (firstItmId2)', $listAllUserKeys);
        $this->assertStringContainsString('_findTokens2(itemIdGen, itemUiGen, items, errors, function (firstItmId3)', $listAllUserKeys);
        $this->assertStringNotContainsString('if(EIMZOClient.NEW_API2)', $listAllUserKeys);
    }

    public function test_vendor_e_imzo_js_exposes_capiws_websocket_bridge_and_base64(): void
    {
        // Regression: the upstream e-imzo.js MUST contain both the Base64
        // helper and the CAPIWS WebSocket bridge. If this file ever gets
        // truncated again, EIMZOClient.checkVersion() / .createPkcs7() throw
        // "CAPIWS is not loaded" / "Base64 is not defined" in the browser
        // and the entire integration silently breaks.
        $path = __DIR__.'/../resources/js/vendor/e-imzo.js';
        $this->assertFileExists($path);

        $source = file_get_contents($path);
        $this->assertGreaterThan(2000, strlen($source), 'vendor/e-imzo.js looks truncated');

        $this->assertStringContainsString('global.Base64 = {', $source);
        $this->assertStringContainsString('CAPIWS', $source);
        $this->assertStringContainsString('wss://127.0.0.1:64443', $source);
        $this->assertStringContainsString('ws://127.0.0.1:64646', $source);
        $this->assertStringContainsString("name: 'apikey'", $source);
        $this->assertStringContainsString("name: 'version'", $source);
    }
}
