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
}
