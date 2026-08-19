<?php

use App\Libraries\LdgCryptoService;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Ldg;

/** @internal */
final class LdgCryptoServiceTest extends CIUnitTestCase
{
    public function testStreamingContainerAndDeviceLicenseRoundTrip(): void
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cms-ldg-' . bin2hex(random_bytes(6));
        mkdir($directory, 0775, true);
        $source = $directory . DIRECTORY_SEPARATOR . 'source.mp4';
        $destination = $directory . DIRECTORY_SEPARATOR . 'asset.ldg';
        $plaintext = random_bytes(1048576 + 12345);
        file_put_contents($source, $plaintext);
        $masterKey = random_bytes(32);
        $config = new Ldg();
        $config->masterKey = base64_encode($masterKey);
        $config->chunkSize = 1048576;
        $config->licenseHours = 24;
        $assetId = '12345678-1234-4234-8234-1234567890ab';
        $deviceId = '87654321-4321-4432-8432-ba0987654321';
        $token = 'phpunit-player-token';

        try {
            $service = new LdgCryptoService($config);
            $values = $service->encryptFile($source, $destination, $assetId, 3);
            $this->assertSame('ldg-v1', $values['encryption_format']);
            $this->assertSame(strlen($plaintext), $values['plaintext_size_bytes']);
            $this->assertSame(hash('sha256', $plaintext), $values['plaintext_sha256']);
            $this->assertSame(hash_file('sha256', $destination), $values['sha256']);
            $this->assertSame('LDG1', file_get_contents($destination, false, null, 0, 4));
            $this->assertStringNotContainsString(substr($plaintext, 0, 64), file_get_contents($destination));

            $dek = openssl_decrypt(
                base64_decode((string) $values['wrapped_dek']), 'aes-256-gcm', $masterKey,
                OPENSSL_RAW_DATA, base64_decode((string) $values['dek_nonce']),
                base64_decode((string) $values['dek_tag']), "ldg-master-v1|{$assetId}|3",
            );
            $this->assertIsString($dek);
            $this->assertSame(32, strlen($dek));
            $this->assertSame($plaintext, $this->decryptContainer($destination, $dek));

            $asset = (object) [
                'public_id' => $assetId, 'revision' => 3, 'encryption_revision' => 3,
                ...$values,
            ];
            $license = $service->deviceLicense($asset, $deviceId, $token);
            $deviceKey = hash_hkdf('sha256', $token, 32, 'ldg-device-kek-v1', $deviceId);
            $licensedDek = openssl_decrypt(
                base64_decode($license['wrapped_key']), 'aes-256-gcm', $deviceKey,
                OPENSSL_RAW_DATA, base64_decode($license['nonce']), base64_decode($license['tag']),
                "ldg-license-v1|{$deviceId}|{$assetId}|3|{$license['expires_at']}",
            );
            $this->assertSame($dek, $licensedDek);
        } finally {
            if (is_file($source)) unlink($source);
            if (is_file($destination)) unlink($destination);
            if (is_dir($directory)) rmdir($directory);
        }
    }

    private function decryptContainer(string $path, string $key): string
    {
        $bytes = file_get_contents($path);
        $this->assertIsString($bytes);
        $header = substr($bytes, 0, 128);
        $core = substr($header, 0, 80);
        $this->assertSame(hash('sha256', $core, true), substr($header, 80, 32));
        $chunkSize = unpack('Nvalue', substr($header, 8, 4))['value'];
        $parts = unpack('Nhigh/Nlow', substr($header, 12, 8));
        $plainSize = $parts['high'] * 4294967296 + $parts['low'];
        $noncePrefix = substr($header, 20, 8);
        $output = '';
        $offset = 128;
        $chunks = (int) ceil($plainSize / $chunkSize);
        for ($index = 0; $index < $chunks; $index++) {
            $plainLength = min($chunkSize, $plainSize - ($index * $chunkSize));
            $ciphertext = substr($bytes, $offset, $plainLength);
            $tag = substr($bytes, $offset + $plainLength, 16);
            $plain = openssl_decrypt(
                $ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA,
                $noncePrefix . pack('N', $index), $tag, $core . pack('N2', $index, $plainLength),
            );
            $this->assertIsString($plain);
            $output .= $plain;
            $offset += $plainLength + 16;
        }
        return $output;
    }
}
