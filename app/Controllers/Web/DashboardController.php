<?php

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use App\Models\DeviceModel;
use App\Models\LocationModel;
use App\Models\UserModel;
use Config\Player;

class DashboardController extends BaseController
{
    public function index(): string
    {
        $devices = new DeviceModel();
        $offlineAfter = config(Player::class)->offlineAfterSeconds;
        $onlineCutoff = gmdate('Y-m-d H:i:s', time() - $offlineAfter);
        return view('web/dashboard', [
            'title' => 'Dashboard', 'active' => 'dashboard', 'admin' => $this->admin(),
            'operatorCount' => (new UserModel())->where('role', 'operator')->countAllResults(),
            'locationCount' => (new LocationModel())->where('status', 'active')->countAllResults(),
            'deviceCount' => (new DeviceModel())->countAllResults(),
            'pendingCount' => (new DeviceModel())->where('status', 'pending')->countAllResults(),
            'onlineCount' => (new DeviceModel())->where('status', 'active')->where('last_seen_at >=', $onlineCutoff)->countAllResults(),
            'offlineCount' => (new DeviceModel())->where('status', 'active')->groupStart()->where('last_seen_at', null)->orWhere('last_seen_at <', $onlineCutoff)->groupEnd()->countAllResults(),
            'playingCount' => (new DeviceModel())->where('playback_state', 'playing')->countAllResults(),
            'errorCount' => (new DeviceModel())->where('playback_state', 'error')->countAllResults(),
            'devices' => $devices->orderBy('created_at', 'DESC')->findAll(5),
        ]);
    }

    private function admin(): object
    {
        return (new UserModel())->find((int) session()->get('cms_web_user_id'));
    }
}
