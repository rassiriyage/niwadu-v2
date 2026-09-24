<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Rules\PasswordBytes;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class CreatePlatformAdministrator extends Command
{
    protected $signature = 'niwadu:create-administrator {email} {--name=}';

    protected $description = 'Create a new platform administrator using a hidden password prompt';

    public function handle(): int
    {
        $email = Str::lower($this->argument('email'));
        if (User::where('email', $email)->exists()) {
            $this->error('An account with this email already exists. No changes were made.');

            return self::FAILURE;
        }
        $data = ['email' => $email, 'name' => $this->option('name') ?: $this->ask('Name'), 'password' => $this->secret('Password (at least 12 characters, at most 72 UTF-8 bytes)')];
        $validator = Validator::make($data, ['email' => ['required', 'email', 'max:255'], 'name' => ['required', 'string', 'max:255'], 'password' => ['required', 'string', 'min:12', new PasswordBytes]]);
        if ($validator->fails()) {
            $this->error('Provide a valid name, email and password of at least 12 characters and at most 72 UTF-8 bytes, without null characters.');

            return self::FAILURE;
        }
        $user = new User($data);
        $user->platform_role = 'administrator';
        $user->save();
        $this->info('Platform administrator created.');

        return self::SUCCESS;
    }
}
