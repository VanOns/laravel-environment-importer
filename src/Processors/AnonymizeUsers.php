<?php

namespace VanOns\LaravelEnvironmentImporter\Processors;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AnonymizeUsers extends DataProcessor
{
    public function tables(): array
    {
        return ['users'];
    }

    public function process(): void
    {
        /** @var Authenticatable|Model $user */
        foreach ($this->getUserModel()->query()->cursor() as $user) {
            $data = [];

            /** @phpstan-ignore-next-line */
            $preserveUser = !empty($this->getPreserveEmails()) && Str::contains($user->email, $this->getPreserveEmails());
            if (!$preserveUser) {
                $name = $this->generateUniqueValue($this->table, 'first_name', 'User');

                $data['first_name'] = $name;
                $data['last_name'] = $name;
                $data['email'] = strtolower($name) . '@' . $this->getEmailDomain();
            }

            if ($password = $this->getPasswordOverride()) {
                $data['password'] = $password;
            }

            // Only update if any data changed.
            if (!empty($data)) {
                /** @phpstan-ignore-next-line */
                DB::table($this->table)->where('id', $user->id)->update($data);
            }

            $this->maybeHandleStatamicTwoFactor($user);
        }
    }

    /**
     * Get the user model.
     */
    protected function getUserModel(): Authenticatable|Model
    {
        $model = config('auth.providers.users.model');

        return app($model);
    }

    /**
     * Get the emails that define the users that should be preserved.
     */
    protected function getPreserveEmails(): array
    {
        return (array) ($this->options['preserve_emails'] ?? []);
    }

    /**
     * Get the email domain to use for anonymized users.
     */
    protected function getEmailDomain(): string
    {
        return (string) ($this->options['email_domain'] ?? '') ?: 'example.com';
    }

    /**
     * Get the password override for users.
     * If this setting is empty, the password will not be overridden.
     */
    protected function getPasswordOverride(): ?string
    {
        $password = $this->options['password_override'] ?? null;
        return !empty($password) ? Hash::make($password) : null;
    }

    /**
     * Generate a unique value for a column in a table.
     */
    protected function generateUniqueValue(string $table, string $column, string $value): string
    {
        $uniqueValue = uniqid($value . '_');

        while (DB::table($table)->where($column, $uniqueValue)->exists()) {
            $uniqueValue = uniqid($value . '_');
        }

        return $uniqueValue;
    }

    /**
     * If the project is using Statamic and the Two Factor plugin from MityDigital, disable two-factor authentication.
     */
    protected function maybeHandleStatamicTwoFactor(Authenticatable|Model $user): void
    {
        if (!class_exists('Statamic\Facades\User') || !class_exists('MityDigital\StatamicTwoFactor\Actions\DisableTwoFactorAuthentication')) {
            return;
        }

        // Disable two-factor authentication so you log in as the user.
        app(\MityDigital\StatamicTwoFactor\Actions\DisableTwoFactorAuthentication::class)(\Statamic\Facades\User::fromUser($user));
    }
}
