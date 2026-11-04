<?php

namespace App\Providers;

use Google\Client;
use Google\Service\Drive;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use League\Flysystem\Filesystem;
use Masbug\Flysystem\GoogleDriveAdapter;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        // A platform that terminates TLS at its edge is reached through its own proxy,
        // never localhost. Until that proxy is trusted, isSecure() stays false and
        // PlatformSecurity redirects to https forever.
        $proxies = (string) config('platform.trusted_proxies');
        TrustProxies::at($proxies === '*' ? '*' : array_filter(array_map('trim', explode(',', $proxies))));

        Storage::extend('google', function ($app, $config) {
            $client = new Client;
            $client->setClientId($config['client_id']);
            $client->setClientSecret($config['client_secret']);
            $client->refreshToken($config['refresh_token']);
            $adapter = new GoogleDriveAdapter(new Drive($client), $config['folder'] ?? '/');

            return new FilesystemAdapter(new Filesystem($adapter), $adapter, $config);
        });
    }
}
