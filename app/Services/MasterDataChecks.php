<?php

namespace App\Services;

use App\Models\Client;

class MasterDataChecks
{
    /** Review saved details without changing them. Samples are capped; counts cover all active clients. */
    public function clients(): array
    {
        $checks = [];
        foreach ([
            'reference' => ['Missing client reference', 'Add the four-digit reference before uploading matter fees.'],
            'address' => ['Missing billing address', 'Add the billing address before issuing an invoice.'],
            'currency' => ['Review Indian client currency', 'This Indian client defaults to a foreign currency. Review the intended billing currency.'],
            'state' => ['Missing Indian place of supply', 'Add a state or state code so the GST split can be determined.'],
            'email' => ['Missing billing email', 'Add a billing email if invoices will be sent by email.'],
        ] as $key => [$title, $help]) {
            $checks[$key] = ['title' => $title, 'help' => $help, 'count' => 0, 'records' => []];
        }

        Client::query()->select(['id', 'name', 'ref_no', 'address', 'client_type', 'default_currency', 'state', 'state_code', 'gstin', 'email_1', 'email_2'])->chunkById(200, function ($clients) use (&$checks): void {
            foreach ($clients as $client) {
                $flags = [
                    'reference' => blank($client->ref_no),
                    'address' => blank($client->address),
                    'currency' => $client->client_type === 'Indian' && $client->default_currency !== 'INR',
                    'state' => $client->client_type === 'Indian' && ! in_array(Gst::stateCode($client), array_values(Gst::STATE_CODES), true),
                    'email' => blank($client->email_1) && blank($client->email_2),
                ];
                foreach ($flags as $key => $flag) {
                    if (! $flag) {
                        continue;
                    }
                    $checks[$key]['count']++;
                    if (count($checks[$key]['records']) < 20) {
                        $checks[$key]['records'][] = ['id' => $client->id, 'name' => $client->name, 'ref_no' => $client->ref_no];
                    }
                }
            }
        });

        return $checks;
    }
}
