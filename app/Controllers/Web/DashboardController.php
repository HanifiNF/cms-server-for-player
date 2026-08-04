<?php

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use App\Models\DeviceModel;
use App\Models\UserModel;

class DashboardController extends BaseController
{
    public function index(): string
    {
        $devices = new DeviceModel();
        return view('web/dashboard', [
            'title' => 'Dashboard', 'active' => 'dashboard', 'admin' => $this->admin(),
            'operatorCount' => (new UserModel())->where('role', 'operator')->countAllResults(),
            'deviceCount' => $devices->countAllResults(),
            'pendingCount' => (new DeviceModel())->where('status', 'pending')->countAllResults(),
            'activeCount' => (new DeviceModel())->where('status', 'active')->countAllResults(),
            'devices' => (new DeviceModel())->orderBy('created_at', 'DESC')->findAll(5),
        ]);
    }

    private function admin(): object
    {
        return (new UserModel())->find((int) session()->get('cms_web_user_id'));
    }
}
