<?php

namespace App\Filters;

use App\Models\UserModel;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class WebAdminFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $userId = (int) session()->get('cms_web_user_id');
        $user = $userId > 0 ? (new UserModel())->find($userId) : null;
        if ($user === null || $user->status !== 'active') {
            session()->remove('cms_web_user_id');
            return redirect()->to('/login')->with('error', 'Sign in with an active administrator account.');
        }
        if ($user->role !== 'admin') {
            return redirect()->to($user->role === 'distributor' ? '/control/library' : '/login')
                ->with('error', 'Administrator access is required for that page.');
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
