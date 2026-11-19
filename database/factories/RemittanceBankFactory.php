<?php

namespace Database\Factories;

use App\Models\RemittanceBank;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RemittanceBank>
 */
class RemittanceBankFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'bank_name' => fake()->randomElement(['ICICI Bank', 'HDFC Bank', 'Deutsche Bank']),
            'branch' => fake()->city(),
            'account_no' => (string) fake()->numerify('############'),
            'currency' => 'USD',
            'account_type' => 'EEFC',
            'is_active' => true,
        ];
    }

    public function rupees(): static
    {
        return $this->state(['currency' => 'INR', 'account_type' => 'Current']);
    }
}
