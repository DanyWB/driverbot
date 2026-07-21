<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class CreateAdminUser extends Command
{
    /** @var string */
    protected $signature = 'admin:create {email} {--name=Administrator}';

    /** @var string */
    protected $description = 'Create or update an administrator account';

    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $name = (string) $this->option('name');
        $password = (string) $this->secret('Password (minimum 12 characters)');

        $validator = Validator::make([
            'email' => $email,
            'name' => $name,
            'password' => $password,
        ], [
            'email' => ['required', 'email:rfc,dns'],
            'name' => ['required', 'string', 'max:255'],
            'password' => [
                'required',
                Password::min(12)->letters()->mixedCase()->numbers()->symbols(),
            ],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = User::query()->firstOrNew(['email' => $email]);
        $user->forceFill([
            'name' => $name,
            'email' => $email,
            'email_verified_at' => now(),
            'password' => $password,
        ])->save();

        $this->info("Administrator {$email} is ready.");

        return self::SUCCESS;
    }
}
