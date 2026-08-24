<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

class Storage extends BaseConfig
{
    /** Base64-encoded 32-byte key used only for storage credentials. */
    public string $credentialsKey = '';
    public int $defaultCacheTtlSeconds = 86400;
    public int $defaultCacheMaxBytes = 53687091200;

    public function __construct()
    {
        parent::__construct();
        $this->credentialsKey = trim((string) env('storage.credentialsKey', $this->credentialsKey));
        $this->defaultCacheTtlSeconds = max(60, (int) env('storage.cacheTtlSeconds', $this->defaultCacheTtlSeconds));
        $this->defaultCacheMaxBytes = max(1073741824, (int) env('storage.cacheMaxBytes', $this->defaultCacheMaxBytes));
    }
}
