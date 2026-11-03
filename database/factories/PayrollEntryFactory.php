<?php

namespace Database\Factories;

use App\Models\PayrollEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PayrollEntry>
 */
class PayrollEntryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['employee' => fake()->name(), 'period' => today()->startOfMonth(), 'gross' => '20000.00', 'deductions' => '1000.00', 'created_by' => User::factory()];
    }
}
