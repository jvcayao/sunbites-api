<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Spatie\Permission\Models\Role;

#[Signature('sunbites:create-admin')]
#[Description('Create the first admin user account interactively')]
class CreateAdminUser extends Command
{
    public function handle(): int
    {
        if (! Role::where('name', 'admin')->exists()) {
            (new PermissionSeeder)->run();
        }

        $this->info('Create Sunbites Admin Account');
        $this->line('------------------------------');

        $firstName = $this->askValid('First name', 'first_name', ['required', 'string', 'max:255']);
        $lastName = $this->askValid('Last name', 'last_name', ['required', 'string', 'max:255']);
        $email = $this->askValid('Email address', 'email', ['required', 'email', 'unique:users,email']);
        $password = $this->askValidSecret('Password (min 8 chars, 1 uppercase, 1 number)', 'password', [
            'required', 'min:8', 'regex:/[A-Z]/', 'regex:/[0-9]/',
        ]);

        $branches = Branch::orderBy('name')->get(['id', 'name']);
        $branchId = null;

        if ($branches->isNotEmpty()) {
            $choice = $this->choice(
                'Assign to branch',
                $branches->pluck('name')->prepend('Skip (assign later)')->toArray(),
                0
            );

            if ($choice !== 'Skip (assign later)') {
                $branchId = $branches->firstWhere('name', $choice)?->id;
            }
        }

        $user = User::create([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'password' => Hash::make($password),
            'is_active' => true,
        ]);

        $user->assignRole('admin');

        if ($branchId) {
            $user->branches()->attach($branchId, [
                'assigned_at' => now(),
                'assigned_by' => null,
            ]);
        }

        $this->newLine();
        $this->info('Admin account created successfully.');
        $this->table(['Field', 'Value'], [
            ['Name', $user->full_name],
            ['Email', $user->email],
            ['Role', 'admin'],
            ['Branch', $branchId ? $branches->firstWhere('id', $branchId)?->name : 'None assigned'],
        ]);

        return self::SUCCESS;
    }

    private function askValid(string $question, string $field, array $rules): string
    {
        while (true) {
            $value = $this->ask($question);
            $validator = Validator::make([$field => $value], [$field => $rules]);

            if ($validator->passes()) {
                return $value;
            }

            foreach ($validator->errors()->get($field) as $error) {
                $this->error($error);
            }
        }
    }

    private function askValidSecret(string $question, string $field, array $rules): string
    {
        while (true) {
            $value = $this->secret($question);
            $validator = Validator::make([$field => $value], [$field => $rules]);

            if ($validator->passes()) {
                return $value;
            }

            foreach ($validator->errors()->get($field) as $error) {
                $this->error($error);
            }
        }
    }
}
