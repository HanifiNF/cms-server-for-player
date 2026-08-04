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
        if ((int) session()->get('cms_web_user_id') > 0) return redirect()->to('/control');
        return redirect()->to('/login');
    }
}
