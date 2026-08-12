<?php

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use App\Libraries\ScheduleService;
use App\Libraries\ScheduleValidationException;
use App\Models\UserModel;
use CodeIgniter\HTTP\RedirectResponse;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

class ScheduleController extends BaseController
{
    public function index(): string
    {
        $service = new ScheduleService();
        $editId = trim((string) $this->request->getGet('edit'));
        $editing = $editId !== '' ? $service->findForWeb($editId) : null;
        if ($editing !== null) {
            $timezone = new DateTimeZone((string) $editing['timezone']);
            $editing['start_local'] = (new DateTimeImmutable((string) $editing['start_at'], new DateTimeZone('UTC')))
                ->setTimezone($timezone)->format('Y-m-d\TH:i');
        }
        return view('web/schedules', [
            'title' => 'Schedules', 'active' => 'schedules', 'admin' => $this->admin(),
            'devices' => $service->readyMediaByDevice(), 'schedules' => $service->listForWeb(),
            'editing' => $editing,
        ]);
    }

    public function create(): RedirectResponse
    {
        try {
            (new ScheduleService())->create($this->payload(), (int) session()->get('cms_web_user_id'));
            return redirect()->to('/control/schedules')->with('success', 'Schedule created. Refresh the Player to synchronize it.');
        } catch (ScheduleValidationException $error) {
            return redirect()->back()->withInput()->with('errors', $error->errors);
        } catch (Throwable $error) {
            log_message('error', 'Schedule creation failed: {message}', ['message' => $error->getMessage()]);
            return redirect()->back()->withInput()->with('error', 'The schedule could not be created.');
        }
    }

    public function update(string $publicId): RedirectResponse
    {
        try {
            (new ScheduleService())->update($publicId, $this->payload(), (int) session()->get('cms_web_user_id'));
            return redirect()->to('/control/schedules')->with('success', 'Schedule updated. Refresh the Player to synchronize the revision.');
        } catch (ScheduleValidationException $error) {
            return redirect()->back()->withInput()->with('errors', $error->errors);
        } catch (Throwable $error) {
            log_message('error', 'Schedule update failed: {message}', ['message' => $error->getMessage()]);
            return redirect()->to('/control/schedules')->with('error', 'The schedule could not be updated.');
        }
    }

    public function status(string $publicId): RedirectResponse
    {
        try {
            (new ScheduleService())->setEnabled($publicId, (string) $this->request->getPost('enabled') === '1');
            return redirect()->to('/control/schedules')->with('success', 'Schedule status updated.');
        } catch (ScheduleValidationException $error) {
            return redirect()->to('/control/schedules')->with('errors', $error->errors);
        } catch (Throwable) {
            return redirect()->to('/control/schedules')->with('error', 'The schedule status could not be updated.');
        }
    }

    public function delete(string $publicId): RedirectResponse
    {
        try {
            (new ScheduleService())->delete($publicId);
            return redirect()->to('/control/schedules')->with('success', 'Schedule deleted. Refresh the Player to remove it from local cache.');
        } catch (Throwable) {
            return redirect()->to('/control/schedules')->with('error', 'The schedule could not be deleted.');
        }
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'title' => $this->request->getPost('title'), 'description' => $this->request->getPost('description'),
            'device_id' => $this->request->getPost('device_id'), 'start_at' => $this->request->getPost('start_at'),
            'priority' => $this->request->getPost('priority'), 'loop_enabled' => $this->request->getPost('loop_enabled'),
            'media_keys' => $this->request->getPost('media_keys'), 'duration_ms' => $this->request->getPost('duration_ms'),
        ];
    }

    private function admin(): object
    {
        return (new UserModel())->find((int) session()->get('cms_web_user_id'));
    }
}
