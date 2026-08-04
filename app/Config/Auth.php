<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

class Auth extends BaseConfig
{
    public int $operatorSessionMinutes = 30;

    public int $loginAttemptsPerMinute = 5;

    public function __construct()
    {
        parent::__construct();
        $this->operatorSessionMinutes = max(5, (int) env('auth.operatorSessionMinutes', 30));
        $this->loginAttemptsPerMinute = max(1, (int) env('auth.loginAttemptsPerMinute', 5));
    }
}
