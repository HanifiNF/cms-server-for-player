<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

class Realtime extends BaseConfig
{
    public bool $enabled = false;

    public string $publicUrl = '';

    public string $notificationChannel = 'player_realtime_outbox';

    public function __construct()
    {
        parent::__construct();

        $this->enabled = filter_var(env('realtime.enabled', false), FILTER_VALIDATE_BOOL);
        $publicUrl = rtrim(trim((string) env('realtime.publicUrl', '')), '/');
        if ($publicUrl !== '' && filter_var($publicUrl, FILTER_VALIDATE_URL) !== false) {
            $scheme = strtolower((string) parse_url($publicUrl, PHP_URL_SCHEME));
            $user = parse_url($publicUrl, PHP_URL_USER);
            $pass = parse_url($publicUrl, PHP_URL_PASS);
            if (in_array($scheme, ['http', 'https'], true) && $user === null && $pass === null) {
                $this->publicUrl = $publicUrl;
            }
        }
        $channel = trim((string) env('realtime.notificationChannel', 'player_realtime_outbox'));
        if (preg_match('/^[a-z][a-z0-9_]{0,62}$/', $channel) === 1) {
            $this->notificationChannel = $channel;
        }
    }

    public function playerUrl(): ?string
    {
        return $this->enabled && $this->publicUrl !== '' ? $this->publicUrl : null;
    }
}
