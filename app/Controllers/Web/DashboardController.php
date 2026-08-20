<?php

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use App\Models\DeviceModel;
use App\Models\LocationModel;
use App\Models\UserModel;
use App\Libraries\DeviceEnrollmentService;

class DashboardController extends BaseController
{
    public function index(): string
    {
        $allDevices = (new DeviceModel())->orderBy('created_at', 'DESC')->findAll();
        $connection = new DeviceEnrollmentService();
        return view('web/dashboard', [
            'title' => 'Dashboard', 'active' => 'dashboard', 'admin' => $this->admin(),
            'operatorCount' => (new UserModel())->where('role', 'operator')->countAllResults(),
            'locationCount' => (new LocationModel())->where('status', 'active')->countAllResults(),
            'deviceCount' => count($allDevices),
            'pendingCount' => count(array_filter($allDevices, static fn ($device): bool => $device->status === 'pending')),
            'onlineCount' => count(array_filter($allDevices, static fn ($device): bool => $connection->connectionStatus($device) === 'online')),
            'offlineCount' => count(array_filter($allDevices, static fn ($device): bool => $device->status === 'active' && $connection->connectionStatus($device) === 'offline')),
            'playingCount' => count(array_filter($allDevices, static fn ($device): bool => $device->playback_state === 'playing')),
            'errorCount' => count(array_filter($allDevices, static fn ($device): bool => $device->playback_state === 'error')),
            'devices' => array_slice($allDevices, 0, 5),
        ]);
    }

    private function admin(): object
    {
        return (new UserModel())->find((int) session()->get('cms_web_user_id'));
    }
}
