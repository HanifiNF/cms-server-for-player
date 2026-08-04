<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Player;

class AdminApiKeyFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null): ?ResponseInterface
    {
        $config = config(Player::class);

        if ($config->adminApiKey === '') {
            return service('response')->setStatusCode(503)->setJSON([
                'error' => [
                    'code'    => 'admin_api_not_configured',
                    'message' => 'The CMS admin API key has not been configured.',
                ],
            ]);
        }

        $providedKey = $request->getHeaderLine('X-CMS-Admin-Key');

        if ($providedKey === '' || ! hash_equals($config->adminApiKey, $providedKey)) {
            return service('response')->setStatusCode(401)->setJSON([
                'error' => [
                    'code'    => 'invalid_admin_key',
                    'message' => 'A valid CMS admin API key is required.',
                ],
            ]);
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): void
    {
    }
}
