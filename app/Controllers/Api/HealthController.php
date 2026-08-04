<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\ResponseInterface;

class HealthController extends BaseController
{
    public function index(): ResponseInterface
    {
        return $this->response
            ->setHeader('Cache-Control', 'no-store')
            ->setJSON([
                'status'      => 'ok',
                'service'     => 'wir-player-cms',
                'environment' => ENVIRONMENT,
                'timestamp'   => gmdate('c'),
            ]);
    }
}
