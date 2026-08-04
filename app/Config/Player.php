<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

class Player extends BaseConfig
{
    public string $adminApiKey = '';

    public string $enrollmentPepper = '';

    public int $enrollmentTtlMinutes = 15;

    public int $offlineAfterSeconds = 30;

    public bool $enablePairingCode = false;

    public function __construct()
    {
        parent::__construct();

        $this->adminApiKey = (string) env('cms.adminApiKey', '');
        $this->enrollmentPepper = (string) env('cms.enrollmentPepper', '');
        $this->enrollmentTtlMinutes = max(1, (int) env('cms.enrollmentTtlMinutes', 15));
        $this->offlineAfterSeconds = max(5, (int) env('cms.offlineAfterSeconds', 30));
        $this->enablePairingCode = filter_var(env('cms.enablePairingCode', false), FILTER_VALIDATE_BOOL);
    }
}
