<?php

namespace Database\Factories;

use App\Enums\AccountType;
use App\Models\Account;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Account>
 */
class AccountFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'type' => fake()->randomElement(AccountType::cases()),
            'billing_reference' => fake()->optional()->bothify('CUST-#####'),
            'billing_email' => fake()->optional()->companyEmail(),
        ];
    }

    public function agency(): static
    {
        return $this->state(fn () => ['type' => AccountType::Agency]);
    }

    public function direct(): static
    {
        return $this->state(fn () => ['type' => AccountType::Direct]);
    }
}
