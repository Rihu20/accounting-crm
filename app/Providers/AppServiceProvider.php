<?php

namespace App\Providers;

use Google\Client;
use Google\Service\Drive;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use League\Flysystem\Filesystem;
use Masbug\Flysystem\GoogleDriveAdapter;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {

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
