<?php

namespace App\Commands;

use App\Models\UserModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class CreateUser extends BaseCommand
{
    protected $group = 'CMS';
    protected $name = 'user:create';
    protected $description = 'Creates an active CMS administrator, operator, or distributor with a generated password.';
    protected $usage = 'user:create <email> <name> <role>';
    protected $arguments = [
        'email' => 'Unique login email.',
        'name'  => 'Display name (quote names containing spaces).',
        'role'  => 'admin, operator, or distributor.',
    ];

    public function run(array $params): void
    {
        $email = mb_strtolower(trim((string) ($params[0] ?? '')));
        $name = trim((string) ($params[1] ?? ''));
        $role = trim((string) ($params[2] ?? 'operator'));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || $name === '' || ! in_array($role, ['admin', 'operator', 'distributor'], true)) {
            CLI::error('Usage: php spark user:create email@example.com "Display Name" admin|operator|distributor');
            return;
        }

        $users = new UserModel();
        if ($users->where('email', $email)->first() !== null) {
            CLI::error('A user with that email already exists.');
            return;
        }

        $password = $this->temporaryPassword();
        $id = $users->insert([
            'email'         => $email,
            'name'          => $name,
            'password_hash' => password_hash($password, PASSWORD_ARGON2ID),
            'role'          => $role,
            'status'        => 'active',
        ], true);
        if ($id === false) {
            CLI::error('The user could not be created.');
            return;
        }

        CLI::write('User created successfully.', 'green');
        CLI::write('User ID: ' . $id);
        CLI::write('Email: ' . $email);
        CLI::write('Role: ' . $role);
        CLI::write('Temporary password: ' . $password, 'yellow');
        CLI::write('Store this password now; it will not be shown again.');
    }

    private function temporaryPassword(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(18)), '+/', '!_'), '=');
    }
}
