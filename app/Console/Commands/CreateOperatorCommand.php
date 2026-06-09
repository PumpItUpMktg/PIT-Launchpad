<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * Non-interactive bootstrap of the first operator — `make:filament-user` needs a
 * TTY (no good in Laravel Cloud's Commands tab) and creates a roleless user that
 * FAILS the operator panel's access gate. This sets role=operator so the user can
 * actually reach /admin (User::canAccessPanel → role === Operator).
 *
 * Password may be passed as the argument or, to keep it out of shell history,
 * supplied via the LAUNCHPAD_OPERATOR_PASSWORD env var.
 */
class CreateOperatorCommand extends Command
{
    protected $signature = 'launchpad:create-operator
        {email : The operator login email}
        {password? : The password (falls back to LAUNCHPAD_OPERATOR_PASSWORD)}
        {--name= : Display name (defaults to the email local-part)}
        {--force : Promote/reset an existing user with this email}';

    protected $description = 'Create (or promote) an operator user that can access the operator panel. Non-interactive.';

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->argument('email')));
        // getenv (not env): this is a real deployment env var, read directly so it
        // works even with a cached config, and never sits in shell history.
        $password = (string) ($this->argument('password') ?? (getenv('LAUNCHPAD_OPERATOR_PASSWORD') ?: ''));
        $name = (string) ($this->option('name') ?: Str::headline(Str::before($email, '@')));

        if ($password === '') {
            $this->error('No password supplied. Pass it as the second argument or set LAUNCHPAD_OPERATOR_PASSWORD.');

            return self::FAILURE;
        }

        $validator = Validator::make(
            ['email' => $email, 'password' => $password],
            ['email' => ['required', 'email'], 'password' => ['required', Password::min(8)]],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $existing = User::query()->where('email', $email)->first();

        if ($existing !== null && ! $this->option('force')) {
            $this->error("A user with {$email} already exists. Re-run with --force to reset its password and ensure the operator role.");

            return self::FAILURE;
        }

        // 'password' is cast 'hashed', so the plaintext is hashed once on save.
        $user = User::query()->updateOrCreate(
            ['email' => $email],
            ['name' => $name, 'role' => UserRole::Operator, 'password' => $password],
        );

        $this->info(sprintf(
            '%s operator %s (role=%s). Sign in at /admin.',
            $existing !== null ? 'Updated' : 'Created',
            $user->email,
            $user->role->value,
        ));

        return self::SUCCESS;
    }
}
