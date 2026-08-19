<?php

namespace App\Libraries;

use Config\Ldg;
use Config\Player;
use RuntimeException;
use Throwable;

class LdgCryptoService
{
    public const FORMAT = 'ldg-v1';
    public const MIME_TYPE = 'application/vnd.wirgroup.ldg';
    public const HEADER_SIZE = 128;
    private const HEADER_CORE_SIZE = 80;
    private const TAG_SIZE = 16;

    private Ldg $config;

    public function __construct(?Ldg $config = null)
    {
        $this->config = $config ?? config(Ldg::class);
    }

    /** @return array<string, int|string> */
    public function encryptFile(string $sourcePath, string $destinationPath, string $assetPublicId, int $revision): array
    {
        if (! is_file($sourcePath)) throw new RuntimeException('The plaintext media file was not found.');
        $plaintextSize = filesize($sourcePath);
        $plaintextSha = hash_file('sha256', $sourcePath);
        if ($plaintextSize === false || $plaintextSize <= 0 || $plaintextSha === false) {
            throw new RuntimeException('The plaintext media file could not be inspected.');
        }

        $dek = random_bytes(32);
        $noncePrefix = random_bytes(8);
        $chunkSize = $this->config->chunkSize;
        $core = 'LDG1'
            . chr(1)
            . chr(1)
            . "\0\0"
            . pack('N', $chunkSize)
            . $this->packUint64($plaintextSize)
            . $noncePrefix
            . $this->uuidBytes($assetPublicId)
            . pack('N', $revision)
            . hex2bin($plaintextSha);
        if (strlen($core) !== self::HEADER_CORE_SIZE) throw new RuntimeException('LDG header construction failed.');
        $header = $core . hash('sha256', $core, true) . str_repeat("\0", 16);

        $input = fopen($sourcePath, 'rb');
        $output = fopen($destinationPath, 'wb');
        if ($input === false || $output === false) {
            if (is_resource($input)) fclose($input);
            if (is_resource($output)) fclose($output);
            throw new RuntimeException('LDG file streams could not be opened.');
        }

        $cipherHash = hash_init('sha256');
        try {
            $this->writeAll($output, $header);
            hash_update($cipherHash, $header);
            $index = 0;
            while (! feof($input)) {
                $plain = fread($input, $chunkSize);
                if ($plain === false) throw new RuntimeException('Plaintext media read failed.');
                if ($plain === '') break;
                if ($index > 0xffffffff) throw new RuntimeException('The media file exceeds the LDG v1 chunk limit.');
                $nonce = $noncePrefix . pack('N', $index);
                $aad = $core . pack('N2', $index, strlen($plain));
                $tag = '';
                $ciphertext = openssl_encrypt($plain, 'aes-256-gcm', $dek, OPENSSL_RAW_DATA, $nonce, $tag, $aad, self::TAG_SIZE);
                if ($ciphertext === false || strlen($tag) !== self::TAG_SIZE) throw new RuntimeException('Media encryption failed.');
                $record = $ciphertext . $tag;
                $this->writeAll($output, $record);
                hash_update($cipherHash, $record);
                $index++;
            }
            if (! fflush($output)) throw new RuntimeException('Encrypted media flush failed.');
        } catch (Throwable $error) {
            fclose($input);
            fclose($output);
            if (is_file($destinationPath)) @unlink($destinationPath);
            throw $error;
        }
        fclose($input);
        fclose($output);

        $storedSize = filesize($destinationPath);
        if ($storedSize === false || $storedSize <= self::HEADER_SIZE) {
            @unlink($destinationPath);
            throw new RuntimeException('Encrypted media output is invalid.');
        }
        $wrapped = $this->wrapMasterKey($dek, $assetPublicId, $revision);
        $dek = str_repeat("\0", strlen($dek));
        unset($dek);

        return [
            'encryption_format' => self::FORMAT,
            'plaintext_size_bytes' => $plaintextSize,
            'plaintext_sha256' => $plaintextSha,
            'ldg_chunk_size' => $chunkSize,
            'size_bytes' => $storedSize,
            'sha256' => hash_final($cipherHash),
            'wrapped_dek' => $wrapped['ciphertext'],
            'dek_nonce' => $wrapped['nonce'],
            'dek_tag' => $wrapped['tag'],
            'key_version' => 1,
            'encryption_revision' => $revision,
        ];
    }

    /** @return array<string, string> */
    public function deviceLicense(object $asset, string $devicePublicId, string $playerToken): array
    {
        if ((string) ($asset->encryption_format ?? '') !== self::FORMAT) throw new RuntimeException('The asset is not encrypted as LDG v1.');
        $encryptionRevision = max(1, (int) ($asset->encryption_revision ?? $asset->revision ?? 1));
        $dek = $this->unwrapMasterKey(
            (string) $asset->wrapped_dek,
            (string) $asset->dek_nonce,
            (string) $asset->dek_tag,
            (string) $asset->public_id,
            $encryptionRevision,
        );
        $expiresAt = gmdate(DATE_ATOM, time() + ($this->config->licenseHours * 3600));
        $aad = $this->licenseAad($devicePublicId, (string) $asset->public_id, (int) $asset->revision, $expiresAt);
        $deviceKey = hash_hkdf('sha256', $playerToken, 32, 'ldg-device-kek-v1', $devicePublicId);
        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($dek, 'aes-256-gcm', $deviceKey, OPENSSL_RAW_DATA, $nonce, $tag, $aad, self::TAG_SIZE);
        $dek = str_repeat("\0", strlen($dek));
        $deviceKey = str_repeat("\0", strlen($deviceKey));
        unset($dek, $deviceKey);
        if ($ciphertext === false || strlen($tag) !== self::TAG_SIZE) throw new RuntimeException('The Player media license could not be created.');
        return [
            'algorithm' => 'A256GCM',
            'wrapped_key' => base64_encode($ciphertext),
            'nonce' => base64_encode($nonce),
            'tag' => base64_encode($tag),
            'expires_at' => $expiresAt,
        ];
    }

    public function downloadFilename(object $asset): string
    {
        $base = trim((string) pathinfo((string) $asset->filename, PATHINFO_FILENAME));
        $base = preg_replace('/[^A-Za-z0-9._-]+/', '-', $base) ?: (string) $asset->public_id;
        return trim($base, '-.') . '.ldg';
    }

    private function masterKey(): string
    {
        if ($this->config->masterKey !== '') {
            $decoded = base64_decode($this->config->masterKey, true);
            if ($decoded === false || strlen($decoded) !== 32) throw new RuntimeException('ldg.masterKey must be a Base64-encoded 32-byte key.');
            return $decoded;
        }
        if (ENVIRONMENT === 'production') throw new RuntimeException('ldg.masterKey must be configured in production.');
        $pepper = (string) config(Player::class)->enrollmentPepper;
        return hash('sha256', $pepper . '|ldg-development-v1', true);
    }

    /** @return array{ciphertext:string, nonce:string, tag:string} */
    private function wrapMasterKey(string $dek, string $assetPublicId, int $revision): array
    {
        $masterKey = $this->masterKey();
        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($dek, 'aes-256-gcm', $masterKey, OPENSSL_RAW_DATA, $nonce, $tag, "ldg-master-v1|{$assetPublicId}|{$revision}", self::TAG_SIZE);
        $masterKey = str_repeat("\0", strlen($masterKey));
        unset($masterKey);
        if ($ciphertext === false || strlen($tag) !== self::TAG_SIZE) throw new RuntimeException('The asset key could not be wrapped.');
        return ['ciphertext' => base64_encode($ciphertext), 'nonce' => base64_encode($nonce), 'tag' => base64_encode($tag)];
    }

    private function unwrapMasterKey(string $ciphertext64, string $nonce64, string $tag64, string $assetPublicId, int $revision): string
    {
        $ciphertext = base64_decode($ciphertext64, true);
        $nonce = base64_decode($nonce64, true);
        $tag = base64_decode($tag64, true);
        if ($ciphertext === false || $nonce === false || $tag === false || strlen($nonce) !== 12 || strlen($tag) !== self::TAG_SIZE) {
            throw new RuntimeException('The stored asset key envelope is invalid.');
        }
        $masterKey = $this->masterKey();
        $plain = openssl_decrypt($ciphertext, 'aes-256-gcm', $masterKey, OPENSSL_RAW_DATA, $nonce, $tag, "ldg-master-v1|{$assetPublicId}|{$revision}");
        $masterKey = str_repeat("\0", strlen($masterKey));
        unset($masterKey);
        if ($plain === false || strlen($plain) !== 32) throw new RuntimeException('The stored asset key could not be authenticated.');
        return $plain;
    }

    private function licenseAad(string $deviceId, string $assetId, int $revision, string $expiresAt): string
    {
        return "ldg-license-v1|{$deviceId}|{$assetId}|{$revision}|{$expiresAt}";
    }

    private function uuidBytes(string $uuid): string
    {
        $hex = str_replace('-', '', strtolower($uuid));
        if (! preg_match('/^[a-f0-9]{32}$/', $hex)) throw new RuntimeException('Asset public ID is not a valid UUID.');
        $bytes = hex2bin($hex);
        if ($bytes === false) throw new RuntimeException('Asset public ID could not be encoded.');
        return $bytes;
    }

    private function packUint64(int $value): string
    {
        if ($value < 0) throw new RuntimeException('LDG size cannot be negative.');
        return pack('N2', intdiv($value, 4294967296), $value % 4294967296);
    }

    /** @param resource $stream */
    private function writeAll($stream, string $bytes): void
    {
        $offset = 0;
        $length = strlen($bytes);
        while ($offset < $length) {
            $written = fwrite($stream, substr($bytes, $offset));
            if ($written === false || $written === 0) throw new RuntimeException('Encrypted media write failed.');
            $offset += $written;
        }
    }
}
