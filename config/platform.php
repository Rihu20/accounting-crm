<?php

return ['modules' => ['accounting' => env('MODULE_ACCOUNTING', true)], 'force_https' => env('PLATFORM_FORCE_HTTPS', false), 'trusted_proxies' => env('TRUSTED_PROXIES', '127.0.0.1,::1')];
