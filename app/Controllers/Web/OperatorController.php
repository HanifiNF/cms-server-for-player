<?php

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use App\Models\DeviceModel;
use App\Models\UserModel;
use CodeIgniter\HTTP\RedirectResponse;
use Config\Database;

class OperatorController extends BaseController
{
    public function index(): string
    {
        $query = trim((string) $this->request->getGet('q'));
        $role = trim((string) $this->request->getGet('role'));
        $status = trim((string) $this->request->getGet('status'));
        if (! in_array($role, ['', 'admin', 'operator', 'distributor'], true)) $role = '';
        if (! in_array($status, ['', 'active', 'inactive'], true)) $status = '';
        $users = new UserModel();
        if ($query !== '') $users->groupStart()->like('name', $query, 'both', true, true)->orLike('email', $query, 'both', true, true)->groupEnd();
        if ($role !== '') $users->where('role', $role);
        if ($status !== '') $users->where('status', $status);
        $studioCounts = [];
        foreach ((new DeviceModel())->select('assigned_user_id, COUNT(*) AS studio_count')->where('assigned_user_id IS NOT NULL')->groupBy('assigned_user_id')->findAll() as $row) {
            $studioCounts[(int) $row->assigned_user_id] = (int) $row->studio_count;
        }
        return view('web/operators', [
            'title' => 'Accounts', 'active' => 'operators', 'admin' => $this->admin(),
            'users' => $users->orderBy('created_at', 'DESC')->findAll(),
            'studioCounts' => $studioCounts,
            'filters' => ['q' => $query, 'role' => $role, 'status' => $status],
        ]);
    }

    public function create(): RedirectResponse
    {
        $data = $this->accountInput();
        $password = (string) $this->request->getPost('password');
        $errors = $this->accountErrors($data);
        if (mb_strlen($password) < 12) $errors['password'] = 'Password must contain at least 12 characters.';
        if ((new UserModel())->where('email', $data['email'])->first() !== null) $errors['email'] = 'That email address is already registered.';
        if ($errors !== []) return redirect()->back()->withInput()->with('errors', $errors);

        $id = (new UserModel())->insert([
            ...$data, 'password_hash' => password_hash($password, PASSWORD_ARGON2ID), 'status' => 'active',
        ], true);
        if ($id === false) return redirect()->back()->withInput()->with('error', 'The account could not be created.');
        return redirect()->to('/control/operators')->with('success', 'Account created successfully.');
    }

    public function update(int $id): RedirectResponse
    {
        $users = new UserModel();
        $target = $users->find($id);
        if ($target === null) return redirect()->to('/control/operators')->with('error', 'Account was not found.');
        $data = $this->accountInput();
        $errors = $this->accountErrors($data);
        $duplicate = $users->where('email', $data['email'])->where('id !=', $id)->first();
        if ($duplicate !== null) $errors['email'] = 'That email address is already registered.';
        if ($id === (int) session()->get('cms_web_user_id') && $data['role'] !== 'admin') $errors['role'] = 'You cannot remove your own administrator role.';
        if ($target->role === 'admin' && $data['role'] !== 'admin' && $this->activeAdminCount() <= 1) $errors['role'] = 'At least one active administrator must remain.';
        if ($errors !== []) return redirect()->to('/control/operators')->with('errors', $errors);

        $users->update($id, $data);
        return redirect()->to('/control/operators')->with('success', 'Account details updated.');
    }

    public function status(int $id): RedirectResponse
    {
        $users = new UserModel();
        $target = $users->find($id);
        if ($target === null) return redirect()->to('/control/operators')->with('error', 'Account was not found.');
        $status = (string) $this->request->getPost('status');
        if (! in_array($status, ['active', 'inactive'], true)) return redirect()->to('/control/operators')->with('error', 'Invalid account status.');
        if ($id === (int) session()->get('cms_web_user_id') && $status !== 'active') return redirect()->to('/control/operators')->with('error', 'You cannot deactivate your own account.');
        if ($target->role === 'admin' && $target->status === 'active' && $status !== 'active' && $this->activeAdminCount() <= 1) {
            return redirect()->to('/control/operators')->with('error', 'At least one active administrator must remain.');
        }
        $users->update($id, ['status' => $status]);
        if ($status === 'inactive') $this->revokeApiSessions($id);
        return redirect()->to('/control/operators')->with('success', 'Account status updated.');
    }

    public function password(int $id): RedirectResponse
    {
        $users = new UserModel();
        if ($users->find($id) === null) return redirect()->to('/control/operators')->with('error', 'Account was not found.');
        $password = (string) $this->request->getPost('password');
        if (mb_strlen($password) < 12) return redirect()->to('/control/operators')->with('error', 'Password must contain at least 12 characters.');
        $users->update($id, ['password_hash' => password_hash($password, PASSWORD_ARGON2ID)]);
        $this->revokeApiSessions($id);
        return redirect()->to('/control/operators')->with('success', 'Password updated and active API sessions revoked.');
    }

    /** @return array{name:string,email:string,role:string} */
    private function accountInput(): array
    {
        return [
            'name' => trim((string) $this->request->getPost('name')),
            'email' => mb_strtolower(trim((string) $this->request->getPost('email'))),
            'role' => (string) $this->request->getPost('role'),
        ];
    }

    /** @param array{name:string,email:string,role:string} $data @return array<string,string> */
    private function accountErrors(array $data): array
    {
        $errors = [];
        if ($data['name'] === '' || mb_strlen($data['name']) > 120) $errors['name'] = 'Name is required and must not exceed 120 characters.';
        if (! filter_var($data['email'], FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Enter a valid email address.';
        if (! in_array($data['role'], ['admin', 'operator', 'distributor'], true)) $errors['role'] = 'Choose a valid role.';
        return $errors;
    }

    private function activeAdminCount(): int
    {
        return (new UserModel())->where('role', 'admin')->where('status', 'active')->countAllResults();
    }

    private function revokeApiSessions(int $userId): void
    {
        Database::connect()->table('auth_sessions')->where('user_id', $userId)->where('revoked_at', null)
            ->update(['revoked_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s')]);
    }

    private function admin(): object
    {
        return (new UserModel())->find((int) session()->get('cms_web_user_id'));
    }
}
