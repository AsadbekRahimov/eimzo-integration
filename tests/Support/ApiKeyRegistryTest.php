<?php

namespace AsadbekRahimov\EimzoIntegration\Tests\Support;

use AsadbekRahimov\EimzoIntegration\Support\ApiKeyRegistry;
use PHPUnit\Framework\TestCase;

class ApiKeyRegistryTest extends TestCase
{
    public function test_parses_recommended_map_form_with_semicolons(): void
    {
        $map = ApiKeyRegistry::normalize(
            'localhost=AAA;127.0.0.1=BBB;eimzo.test=CCC',
            []
        );

        $this->assertSame([
            'localhost' => 'AAA',
            '127.0.0.1' => 'BBB',
            'eimzo.test' => 'CCC',
        ], $map);
    }

    public function test_parses_map_form_split_by_whitespace_or_newlines(): void
    {
        $value = "localhost=AAA\n127.0.0.1=BBB    eimzo.test=CCC";

        $map = ApiKeyRegistry::normalize($value, []);

        $this->assertSame([
            'localhost' => 'AAA',
            '127.0.0.1' => 'BBB',
            'eimzo.test' => 'CCC',
        ], $map);
    }

    public function test_parses_legacy_comma_pair_form(): void
    {
        $map = ApiKeyRegistry::normalize('localhost,AAA,127.0.0.1,BBB', []);

        $this->assertSame([
            'localhost' => 'AAA',
            '127.0.0.1' => 'BBB',
        ], $map);
    }

    public function test_parses_associative_array_value(): void
    {
        $map = ApiKeyRegistry::normalize([
            'EIMZO.TEST' => 'CCC',
            'localhost' => 'AAA',
        ], []);

        // Hosts are lower-cased.
        $this->assertSame([
            'eimzo.test' => 'CCC',
            'localhost' => 'AAA',
        ], $map);
    }

    public function test_parses_flat_pair_array_value(): void
    {
        $map = ApiKeyRegistry::normalize(['localhost', 'AAA', '127.0.0.1', 'BBB'], []);

        $this->assertSame([
            'localhost' => 'AAA',
            '127.0.0.1' => 'BBB',
        ], $map);
    }

    public function test_per_host_env_vars_override_string_form(): void
    {
        $map = ApiKeyRegistry::normalize(
            'localhost=OLD-VALUE',
            [
                'EIMZO_API_KEY_LOCALHOST' => 'NEW-VALUE',
                'EIMZO_API_KEY_EIMZO_TEST' => 'CCC',
            ]
        );

        $this->assertSame([
            'localhost' => 'NEW-VALUE',
            'eimzo.test' => 'CCC',
        ], $map);
    }

    public function test_per_host_env_var_supports_explicit_host_override(): void
    {
        $map = ApiKeyRegistry::normalize('', [
            'EIMZO_API_KEY_PROD' => 'KEY',
            'EIMZO_API_KEY_PROD_HOST' => 'crm.example.uz',
        ]);

        $this->assertSame(['crm.example.uz' => 'KEY'], $map);
    }

    public function test_filter_for_host_returns_only_matching_pair(): void
    {
        $map = [
            'localhost' => 'A',
            'eimzo.test' => 'B',
            'crm.example.uz' => 'C',
        ];

        $this->assertSame(['eimzo.test', 'B'], ApiKeyRegistry::filterForHost($map, 'eimzo.test'));
        $this->assertSame(['eimzo.test', 'B'], ApiKeyRegistry::filterForHost($map, 'EIMZO.TEST'));
        $this->assertSame(['eimzo.test', 'B'], ApiKeyRegistry::filterForHost($map, 'eimzo.test:8443'));
    }

    public function test_filter_for_host_pairs_localhost_and_loopback_ip(): void
    {
        $map = [
            'localhost' => 'A',
            '127.0.0.1' => 'B',
            'crm.example.uz' => 'C',
        ];

        $this->assertSame(
            ['localhost', 'A', '127.0.0.1', 'B'],
            ApiKeyRegistry::filterForHost($map, 'localhost')
        );
        $this->assertSame(
            ['127.0.0.1', 'B', 'localhost', 'A'],
            ApiKeyRegistry::filterForHost($map, '127.0.0.1')
        );
    }

    public function test_filter_for_host_returns_empty_when_unknown(): void
    {
        $map = ['localhost' => 'A'];

        $this->assertSame([], ApiKeyRegistry::filterForHost($map, 'unknown.example.uz'));
    }

    public function test_filter_for_host_uses_wildcard_fallback(): void
    {
        $map = ['*' => 'WILDCARD-KEY'];

        $this->assertSame(
            ['eimzo.test', 'WILDCARD-KEY'],
            ApiKeyRegistry::filterForHost($map, 'eimzo.test')
        );
    }

    public function test_resolve_for_host_accepts_assoc_map(): void
    {
        $this->assertSame(
            ['localhost', 'KEY'],
            ApiKeyRegistry::resolveForHost(['localhost' => 'KEY'], 'localhost')
        );
    }

    public function test_resolve_for_host_accepts_flat_pair_array(): void
    {
        $this->assertSame(
            ['localhost', 'KEY'],
            ApiKeyRegistry::resolveForHost(['localhost', 'KEY'], 'localhost')
        );
    }

    public function test_resolve_for_host_accepts_string(): void
    {
        $this->assertSame(
            ['localhost', 'KEY'],
            ApiKeyRegistry::resolveForHost('localhost=KEY', 'localhost')
        );
    }
}
