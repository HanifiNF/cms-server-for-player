<?php

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use App\Libraries\ScheduleService;
use App\Libraries\AssetExpiryService;
use App\Libraries\CollectionPage;
use App\Libraries\ScheduleDirectoryFilter;
use App\Libraries\ScheduleValidationException;
use App\Models\UserModel;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

class ScheduleController extends BaseController
{
    public function index(): string
    {
        (new AssetExpiryService())->expireDue();
        $service = new ScheduleService();
        $filters = (new ScheduleDirectoryFilter())->normalize((array) $this->request->getGet());
        $directory = [
            'rows' => [], 'all' => [], 'filters' => $filters,
            'options' => $service->directoryOptionsForWeb(),
            'total' => 0, 'total_all' => $service->scheduleCountForWeb(),
            'page' => 1, 'per_page' => 20, 'pages' => 1,
        ];
        $editId = trim((string) $this->request->getGet('edit'));
        $editing = $editId !== '' ? $service->findForWeb($editId) : null;
        if ($editing !== null) {
            $timezone = new DateTimeZone((string) $editing['timezone']);
            $editing['start_local'] = (new DateTimeImmutable((string) $editing['start_at'], new DateTimeZone('UTC')))
                ->setTimezone($timezone)->format('Y-m-d\TH:i:s');
            $config = is_array($editing['recurrence_config'])
                ? $editing['recurrence_config']
                : json_decode((string) ($editing['recurrence_config'] ?? ''), true);
            $editing['recurrence_values'] = is_array($config) ? $config : [];
        }
        return view('web/schedules', [
            'title' => 'Schedules', 'active' => 'schedules', 'admin' => $this->admin(),
            'devices' => $service->readyMediaByDevice(), 'schedules' => $directory['rows'],
            'scheduleDirectory' => $directory,
            'editing' => $editing,
        ]);
    }

    public function collection(): ResponseInterface
    {
        (new AssetExpiryService())->expireDue();
        $input = (array) $this->request->getGet();
        $directory = (new ScheduleService())->directory($input, 20, false);
        $page = CollectionPage::fromQuery($input, (int) $directory['total'], 20, 100);
        $formatDuration = static function (int $milliseconds): string {
            $seconds = max(0, intdiv($milliseconds, 1000));
            return sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
        };
        $items = [];
        foreach ($directory['rows'] as $schedule) {
            $bulkText = mb_strtolower($schedule['title'] . ' ' . implode(' ', array_column($schedule['targets'], 'name')) . ' ' . implode(' ', array_column($schedule['targets'], 'location')) . ' ' . implode(' ', array_column($schedule['items'], 'title_snapshot')) . ' ' . $schedule['display_status']);
            $items[] = [
                'id' => (string) $schedule['public_id'],
                'html' => view('web/_schedule_directory_card', compact('schedule', 'formatDuration')),
                'bulk' => [
                    'searchText' => $bulkText,
                    'title' => (string) $schedule['title'],
                    'summary' => implode(', ', array_column($schedule['targets'], 'name')) . ' · ' . strtoupper((string) $schedule['display_status']) . ' · ' . count($schedule['items']) . ' asset(s)',
                    'status' => (string) $schedule['status'],
                ],
            ];
        }

        return $this->response->setJSON([
            'data' => [
                ...$page->payload($items),
                'totalAll' => (int) $directory['total_all'],
            ],
        ]);
    }

    public function bulkCollection(): ResponseInterface
    {
        $input = (array) $this->request->getGet();
        $input['page'] = 1;
        $directory = (new ScheduleService())->directory($input, 100, false);
        $items = [];
        foreach ($directory['rows'] as $schedule) {
            $items[] = [
                'id' => (string) $schedule['public_id'],
                'searchText' => mb_strtolower($schedule['title'] . ' ' . implode(' ', array_column($schedule['targets'], 'name')) . ' ' . implode(' ', array_column($schedule['targets'], 'location')) . ' ' . implode(' ', array_column($schedule['items'], 'title_snapshot')) . ' ' . $schedule['display_status']),
                'title' => (string) $schedule['title'],
                'summary' => implode(', ', array_column($schedule['targets'], 'name')) . ' · ' . strtoupper((string) $schedule['display_status']) . ' · ' . count($schedule['items']) . ' asset(s)',
                'status' => (string) $schedule['status'],
            ];
        }

        return $this->response->setJSON([
            'data' => ['items' => $items, 'total' => (int) $directory['total'], 'limited' => (int) $directory['total'] > 100],
        ]);
    }

    public function create(): RedirectResponse
    {
        try {
            (new ScheduleService())->create($this->payload(), (int) session()->get('cms_web_user_id'));
            return redirect()->to('/control/schedules')->with('success', 'Schedule created. The Player will synchronize it automatically.');
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
            return redirect()->to('/control/schedules')->with('success', 'Schedule updated. The Player will synchronize the revision automatically.');
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
            return redirect()->to('/control/schedules')->with('success', 'Schedule deleted. The Player will remove it from local cache automatically.');
        } catch (Throwable) {
            return redirect()->to('/control/schedules')->with('error', 'The schedule could not be deleted.');
        }
    }

    public function bulkDisable(): RedirectResponse
    {
        try {
            $result = (new ScheduleService())->disableMany($this->selectedScheduleIds());
            return $this->directoryRedirect()->with('success', $result['changed'] . ' schedule(s) disabled. Target Players were notified through realtime sync.');
        } catch (ScheduleValidationException $error) {
            return $this->directoryRedirect()->with('errors', $error->errors);
        } catch (Throwable $error) {
            log_message('error', 'Bulk schedule disable failed: {message}', ['message' => $error->getMessage()]);
            return $this->directoryRedirect()->with('error', 'The selected schedules could not be disabled.');
        }
    }

    public function bulkDelete(): RedirectResponse
    {
        try {
            $result = (new ScheduleService())->deleteMany($this->selectedScheduleIds());
            return $this->directoryRedirect()->with('success', $result['changed'] . ' schedule(s) deleted. Target Players were notified through realtime sync.');
        } catch (ScheduleValidationException $error) {
            return $this->directoryRedirect()->with('errors', $error->errors);
        } catch (Throwable $error) {
            log_message('error', 'Bulk schedule deletion failed: {message}', ['message' => $error->getMessage()]);
            return $this->directoryRedirect()->with('error', 'The selected schedules could not be deleted.');
        }
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'title' => $this->request->getPost('title'), 'description' => $this->request->getPost('description'),
            'device_id' => $this->request->getPost('device_id'),
            'device_ids' => $this->request->getPost('device_ids'),
            'timezone' => $this->request->getPost('timezone'), 'start_at' => $this->request->getPost('start_at'),
            'recurrence' => $this->request->getPost('recurrence'),
            'days_of_week' => $this->request->getPost('days_of_week'),
            'recurrence_until' => $this->request->getPost('recurrence_until'),
            'priority' => $this->request->getPost('priority'), 'loop_enabled' => $this->request->getPost('loop_enabled'),
            'media_keys' => $this->request->getPost('media_keys'), 'duration_ms' => $this->request->getPost('duration_ms'),
            'playback_start_offset_ms' => $this->request->getPost('playback_start_offset_ms'),
            'gap_after_ms' => $this->request->getPost('gap_after_ms'),
            'volume_percent' => $this->request->getPost('volume_percent'),
        ];
    }

    private function admin(): object
    {
        return (new UserModel())->find((int) session()->get('cms_web_user_id'));
    }

    /** @return list<string> */
    private function selectedScheduleIds(): array
    {
        $ids = $this->request->getPost('schedule_ids');
        return is_array($ids) ? array_values(array_map('strval', $ids)) : [];
    }

    private function directoryRedirect(): RedirectResponse
    {
        $query = ltrim(trim((string) $this->request->getPost('return_query')), '?');
        parse_str($query, $values);
        $allowed = array_intersect_key((array) $values, array_flip([
            'q', 'location_ids', 'device_ids', 'asset_ids', 'date_from', 'date_to', 'period', 'status', 'page',
        ]));
        $suffix = $allowed === [] ? '' : '?' . http_build_query($allowed);
        return redirect()->to('/control/schedules' . $suffix);
    }
}
