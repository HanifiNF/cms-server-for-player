<?php

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use App\Models\UserModel;
use CodeIgniter\HTTP\RedirectResponse;

class AuthController extends BaseController
{
    public function index(): string|RedirectResponse
    {
        if ((new UserModel())->where('role', 'admin')->countAllResults() === 0) return redirect()->to('/setup');
        $current = $this->currentUser();
        if ($current !== null) return redirect()->to($current->role === 'distributor' ? '/control/assets' : '/control');
        return view('web/login', ['title' => 'CMS login']);
    }

    public function login(): RedirectResponse
    {
        $email = mb_strtolower(trim((string) $this->request->getPost('email')));
        $password = (string) $this->request->getPost('password');
        $rateKey = 'web-cms-login-' . hash('sha256', $this->request->getIPAddress() . '|' . $email);
        if (! service('throttler')->check($rateKey, 5, 60)) {
            return redirect()->back()->withInput()->with('error', 'Too many login attempts. Try again in one minute.');
        }

        $user = (new UserModel())->where('email', $email)->first();
        if ($user === null || ! in_array($user->role, ['admin', 'distributor'], true) || $user->status !== 'active' || ! password_verify($password, $user->password_hash)) {
            return redirect()->back()->withInput()->with('error', 'Email or password is incorrect.');
        }

        session()->regenerate(true);
        session()->set('cms_web_user_id', $user->id);
        (new UserModel())->update($user->id, ['last_login_at' => gmdate('Y-m-d H:i:s')]);
        return redirect()->to($user->role === 'distributor' ? '/control/assets' : '/control');
    }

    public function logout(): RedirectResponse
    {
        session()->destroy();
        return redirect()->to('/login');
    }

    private function currentUser(): ?object
    {
        $userId = (int) session()->get('cms_web_user_id');
        if ($userId <= 0) return null;
        $user = (new UserModel())->find($userId);
        if ($user === null || $user->status !== 'active' || ! in_array($user->role, ['admin', 'distributor'], true)) return null;
        return $user;
    }
}
