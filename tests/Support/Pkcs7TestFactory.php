<?php

namespace AsadbekRahimov\EimzoIntegration\Tests\Support;

class Pkcs7TestFactory
{
    public static function attached(array $subject, string $data): string
    {
        $openssl = self::opensslBinary();
        self::configureOpenSsl($openssl);

        $tmp = self::tempRoot() . '/eimzo_test_' . bin2hex(random_bytes(4));
        if (! @mkdir($tmp, 0777, true) && ! is_dir($tmp)) {
            throw new \RuntimeException('Could not create temporary directory: ' . $tmp);
        }

        try {
            $subj = '';
            foreach ($subject as $key => $value) {
                $subj .= '/' . $key . '=' . $value;
            }

            self::run(sprintf(
                '%s req -x509 -newkey rsa:2048 -nodes -keyout %s -out %s -subj %s -days 365',
                escapeshellarg($openssl),
                escapeshellarg($tmp . '/key.pem'),
                escapeshellarg($tmp . '/cert.pem'),
                escapeshellarg($subj)
            ));

            file_put_contents($tmp . '/data.bin', $data);

            self::run(sprintf(
                '%s smime -sign -in %s -signer %s -inkey %s -out %s -outform DER -nodetach',
                escapeshellarg($openssl),
                escapeshellarg($tmp . '/data.bin'),
                escapeshellarg($tmp . '/cert.pem'),
                escapeshellarg($tmp . '/key.pem'),
                escapeshellarg($tmp . '/signed.p7')
            ));

            $signed = file_get_contents($tmp . '/signed.p7');
            if ($signed === false) {
                throw new \RuntimeException('OpenSSL did not create signed.p7');
            }

            return base64_encode($signed);
        } finally {
            foreach (['key.pem', 'cert.pem', 'data.bin', 'signed.p7'] as $file) {
                @unlink($tmp . '/' . $file);
            }
            @rmdir($tmp);
        }
    }

    private static function opensslBinary(): string
    {
        $configured = getenv('OPENSSL_BINARY') ?: getenv('OPENSSL_PATH');
        if (is_string($configured) && $configured !== '' && is_file($configured)) {
            return $configured;
        }

        $candidates = ['openssl'];
        foreach ([
            'C:/OpenServer/modules/http/*/bin/openssl.exe',
            'C:/OpenServer/modules/php/*/extras/openssl/openssl.exe',
        ] as $pattern) {
            $matches = glob($pattern) ?: [];
            rsort($matches);
            $candidates = array_merge($candidates, $matches);
        }

        foreach ($candidates as $candidate) {
            $output = [];
            $code = 1;
            exec(escapeshellarg($candidate) . ' version 2>&1', $output, $code);
            if ($code === 0) {
                return $candidate;
            }
        }

        throw new \RuntimeException('OpenSSL CLI was not found. Set OPENSSL_BINARY to an openssl executable.');
    }

    private static function tempRoot(): string
    {
        $root = sys_get_temp_dir();
        if (is_dir($root) && is_writable($root)) {
            return rtrim(str_replace('\\', '/', $root), '/');
        }

        $root = dirname(__DIR__, 2) . '/.phpunit-tmp';
        if (! is_dir($root)) {
            @mkdir($root, 0777, true);
        }

        return rtrim(str_replace('\\', '/', $root), '/');
    }

    private static function configureOpenSsl(string $openssl): void
    {
        if (getenv('OPENSSL_CONF')) {
            return;
        }

        $root = dirname(dirname($openssl));
        foreach ([
            $root . '/conf/openssl.cnf',
            $root . '/extras/ssl/openssl.cnf',
            $root . '/extras/openssl.cnf',
            $root . '/extras/openssl/openssl.cnf',
            'C:/OpenServer/modules/php/PHP_8.3/extras/ssl/openssl.cnf',
        ] as $candidate) {
            if (is_file($candidate)) {
                putenv('OPENSSL_CONF=' . $candidate);
                $_ENV['OPENSSL_CONF'] = $candidate;
                $_SERVER['OPENSSL_CONF'] = $candidate;
                return;
            }
        }
    }

    private static function run(string $command): void
    {
        $output = [];
        $code = 0;
        exec($command . ' 2>&1', $output, $code);
        if ($code !== 0) {
            throw new \RuntimeException("OpenSSL command failed:\n" . implode("\n", $output));
        }
    }
}
