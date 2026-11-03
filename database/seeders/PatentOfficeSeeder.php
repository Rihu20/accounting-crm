<?php

namespace Database\Seeders;

use App\Models\PatentOffice;
use Illuminate\Database\Seeder;

class PatentOfficeSeeder extends Seeder
{
    public function run(): void
    {
        foreach (json_decode(file_get_contents(resource_path('data/patent-offices.json')), true, 512, JSON_THROW_ON_ERROR) as $office) {
            PatentOffice::firstOrCreate(['country' => $office['country'], 'name' => $office['name']], $office);
        }
    }
}
