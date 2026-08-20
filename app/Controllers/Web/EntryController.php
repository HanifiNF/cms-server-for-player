<?php

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use App\Models\UserModel;
use CodeIgniter\HTTP\RedirectResponse;

class EntryController extends BaseController
{
    public function index(): RedirectResponse
    {
        $hasAdmin = (new UserModel())->where('role', 'admin')->countAllResults() > 0;
        if (! $hasAdmin) return redirect()->to('/setup');
        $userId = (int) session()->get('cms_web_user_id');
        $user = $userId > 0 ? (new UserModel())->find($userId) : null;
        if ($user !== null && $user->status === 'active') {
            if ($user->role === 'distributor') return redirect()->to('/control/library');
            if ($user->role === 'admin') return redirect()->to('/control');
        }
        return redirect()->to('/login');
    }
}
