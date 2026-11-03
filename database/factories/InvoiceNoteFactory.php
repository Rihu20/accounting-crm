<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceNote;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InvoiceNote>
 */
class InvoiceNoteFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['created_by' => User::factory(), 'invoice_id' => fn (array $attributes) => Invoice::create(['doc_type' => 'tax', 'status' => 'Issued', 'client_id' => Client::create(['name' => fake()->unique()->company(), 'country' => 'India', 'client_type' => 'Indian'])->id, 'invoice_date' => today(), 'due_date' => today(), 'currency' => 'INR', 'created_by' => $attributes['created_by']])->id, 'number' => fake()->unique()->bothify('CN-####'), 'type' => 'credit', 'note_date' => today(), 'amount' => '10.00', 'reason' => 'Billing adjustment'];
    }
}
