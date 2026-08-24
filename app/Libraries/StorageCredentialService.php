<?php

namespace App\Libraries;

use Config\Ldg;
use Config\Storage;
use RuntimeException;

final class StorageCredentialService
{
    private const PREFIX = 'storage-credentials-v1';

    public function __construct(private readonly ?Storage $config = null)
    {
    }

    /** @param array<string, string> $credentials */
    public function encrypt(array $credentials): string
    {
        $json = json_encode($credentials, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) throw new RuntimeException('Storage credentials could not be encoded.');
        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($json, 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, $nonce, $tag, self::PREFIX, 16);
        if ($ciphertext === false || strlen($tag) !== 16) throw new RuntimeException('Storage credentials could not be encrypted.');
        return self::PREFIX . ':' . base64_encode($nonce) . ':' . base64_encode($tag) . ':' . base64_encode($ciphertext);
    }

    /** @return array<string, string> */
    public function decrypt(?string $payload): array
    {
        if ($payload === null || trim($payload) === '') return [];
        $parts = explode(':', $payload, 4);
        if (count($parts) !== 4 || $parts[0] !== self::PREFIX) throw new RuntimeException('Storage credentials use an unsupported format.');
        $nonce = base64_decode($parts[1], true);
        $tag = base64_decode($parts[2], true);
        $ciphertext = base64_decode($parts[3], true);
        if ($nonce === false || strlen($nonce) !== 12 || $tag === false || strlen($tag) !== 16 || $ciphertext === false) {
            throw new RuntimeException('Storage credentials are malformed.');
        }
        $json = openssl_decrypt($ciphertext, 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, $nonce, $tag, self::PREFIX);
        if ($json === false) throw new RuntimeException('Storage credentials could not be decrypted. Check storage.credentialsKey.');
        $values = json_decode($json, true);
        if (! is_array($values)) throw new RuntimeException('Storage credentials contain invalid data.');
        $credentials = [];
        foreach ($values as $key => $value) {
            if (is_string($key) && is_scalar($value)) $credentials[$key] = (string) $value;
        }
        return $credentials;
    }

    private function key(): string
    {
        $configured = ($this->config ?? config(Storage::class))->credentialsKey;
        if ($configured !== '') {
            $decoded = base64_decode($configured, true);
            if ($decoded === false || strlen($decoded) !== 32) throw new RuntimeException('storage.credentialsKey must be a Base64-encoded 32-byte key.');
            return $decoded;
        }
        if (ENVIRONMENT === 'production') throw new RuntimeException('storage.credentialsKey must be configured before FTPS credentials can be used in production.');

        // Development compatibility only: derive an isolated key from the LDG key.
        $ldgKey = trim((string) config(Ldg::class)->masterKey);
        $decoded = base64_decode($ldgKey, true);
        if ($decoded === false || strlen($decoded) !== 32) throw new RuntimeException('Configure storage.credentialsKey before creating an FTPS profile.');
        return hash_hkdf('sha256', $decoded, 32, self::PREFIX, 'development-fallback');
    }
}
