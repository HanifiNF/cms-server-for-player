<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

class Realtime extends BaseConfig
{
    public bool $enabled = false;

    public string $notificationChannel = 'player_realtime_outbox';

    public function __construct()
    {
        parent::__construct();

        $this->enabled = filter_var(env('realtime.enabled', false), FILTER_VALIDATE_BOOL);
        $channel = trim((string) env('realtime.notificationChannel', 'player_realtime_outbox'));
        if (preg_match('/^[a-z][a-z0-9_]{0,62}$/', $channel) === 1) {
            $this->notificationChannel = $channel;
        }
    }
}
