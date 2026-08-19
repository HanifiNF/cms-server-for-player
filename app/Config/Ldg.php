<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

class Ldg extends BaseConfig
{
    public string $masterKey = '';
    public int $chunkSize = 4194304;
    public int $licenseHours = 24;

    public function __construct()
    {
        parent::__construct();
        $this->masterKey = trim((string) env('ldg.masterKey', ''));
        $this->chunkSize = max(1048576, min(16777216, (int) env('ldg.chunkSize', 4194304)));
        $this->licenseHours = max(1, min(168, (int) env('ldg.licenseHours', 24)));
    }
}
