<?php

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use App\Models\UserModel;
use CodeIgniter\HTTP\RedirectResponse;

class SetupController extends BaseController
{
    public function index(): string|RedirectResponse
    {
        if ($this->hasAdmin()) return redirect()->to('/login');
        return view('web/setup', ['title' => 'Create first administrator']);
    }

    public function create(): RedirectResponse
    {
        if ($this->hasAdmin()) return redirect()->to('/login');
        $name = trim((string) $this->request->getPost('name'));
        $email = mb_strtolower(trim((string) $this->request->getPost('email')));
        $password = (string) $this->request->getPost('password');
        $confirmation = (string) $this->request->getPost('password_confirmation');
        $errors = [];
        if ($name === '' || mb_strlen($name) > 120) $errors['name'] = 'Name is required and must not exceed 120 characters.';
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Enter a valid email address.';
        if (mb_strlen($password) < 12) $errors['password'] = 'Password must contain at least 12 characters.';
        if ($password !== $confirmation) $errors['password_confirmation'] = 'Password confirmation does not match.';
        if ($errors !== []) return redirect()->back()->withInput()->with('errors', $errors);

        $users = new UserModel();
        $id = $users->insert([
            'email' => $email, 'name' => $name,
            'password_hash' => password_hash($password, PASSWORD_ARGON2ID),
            'role' => 'admin', 'status' => 'active', 'last_login_at' => gmdate('Y-m-d H:i:s'),
        ], true);
        if ($id === false) return redirect()->back()->withInput()->with('error', 'The administrator account could not be created.');

        session()->regenerate(true);
        session()->set('cms_web_user_id', $id);
        return redirect()->to('/control')->with('success', 'Administrator account created.');
    }

    private function hasAdmin(): bool
    {
        return (new UserModel())->where('role', 'admin')->countAllResults() > 0;
    }
}
