<?php

namespace App\Services;

use Symfony\Component\Process\Process;

class LocalInvoiceReader
{
    public function read(string $path): array
    {
        $process = new Process([
            config('crm.pdf_python'), '-X', 'utf8', resource_path('reader/read_invoice.py'), $path,
        ]);
        $process->setTimeout(120);
        $process->run();
        $result = json_decode($process->getOutput(), true);
        if (! $process->isSuccessful() || ! is_array($result) || ! isset($result['data'])) {
            throw new \RuntimeException($result['error'] ?? 'The local PDF reader could not start. Check the reader installation.');
        }

        return $result;
    }
}
