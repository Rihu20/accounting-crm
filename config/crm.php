<?php

return [
    'disk' => env('CRM_DISK', 'local'),
    'reader_mode' => env('CRM_READER_MODE', 'local'),
    'pdf_python' => env('CRM_PDF_PYTHON', 'python'),
    'expense_currencies' => ['USD', 'INR', 'EUR', 'JPY', 'GBP', 'KRW', 'CNY', 'CAD', 'AUD', 'CHF', 'SGD', 'HKD'],
    'anthropic_key' => env('ANTHROPIC_API_KEY'),
    'anthropic_model' => env('ANTHROPIC_MODEL'),
    'mail_enabled' => env('CRM_MAIL_ENABLED', false),
];
