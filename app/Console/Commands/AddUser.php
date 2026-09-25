<?php

namespace App\Console\Commands;

use App\Console\Concerns\ReadsNewPassword;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class AddUser extends Command
{
    use ReadsNewPassword;

    protected $signature = 'soundboard:add-user
                            {--name= : Display name}
                            {--email= : Email address to sign in with}
                            {--role=* : Role to assign, e.g. --role=Admin (repeatable)}';

    protected $description = 'Create a user, e.g. the first admin on a new install';

    public function handle(): int
    {
        return Audit::fromCommandLine(fn () => $this->perform());
    }

    private function perform(): int
    {
        $name = $this->option('name') ?: $this->ask('Name');
        $email = $this->option('email') ?: $this->ask('Email');
        $roles = $this->option('role');
        $input = $this->readNewPassword();

        if ($input === null) {
            return self::FAILURE;
        }

        [$password, $generated] = $input;

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password, 'roles' => $roles],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255', 'unique:users,email'],
                'password' => ['required', 'string', Password::defaults()],
                'roles.*' => [Rule::exists('roles', 'name')],
            ],
            ['roles.*.exists' => 'There is no role named ":input".'],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
        ]);

        $user->syncRoles(Role::whereIn('name', $roles)->get());
        Audit::setChanged('user.roles_changed', 'Changed user roles', $user, [], $user->getRoleNames());

        $this->components->info("Created {$email}".($roles ? ' with role '.implode(', ', $roles) : '').'.');

        if ($generated) {
            $this->line("  Password: <comment>{$password}</comment>");
            $this->line('  Sign in and change it on the account page.');
        }

        return self::SUCCESS;
    }
}
