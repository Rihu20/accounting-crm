<?php

namespace Database\Factories;

use App\Models\PatentOffice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PatentOffice>
 */
class PatentOfficeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company().' IP Office',
            'country' => fake()->country(),
            'is_active' => true,
        ];
    }
}
