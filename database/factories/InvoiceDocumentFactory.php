<?php

namespace Database\Factories;

use App\Models\InvoiceDocument;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InvoiceDocument>
 */
class InvoiceDocumentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'doc_type' => 'tax',
            'file_name' => 'invoice.pdf',
            'path' => 'InvoiceUploads/1/'.fake()->uuid().'.pdf',
            'sha256' => hash('sha256', fake()->uuid()),
            'uploaded_by' => User::factory(),
        ];
    }
}
