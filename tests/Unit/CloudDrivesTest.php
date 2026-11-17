<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Backup\Tests\Unit;

use Atrium\Backup\Destinations\CloudDrives;
use Atrium\Backup\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Masbug\Flysystem\GoogleDriveAdapter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use RuntimeException;
use Spatie\FlysystemDropbox\DropboxAdapter;

final class CloudDrivesTest extends TestCase
{
    public function test_a_drive_without_its_adapter_names_the_package_to_install(): void
    {
        config(['filesystems.disks.drive' => ['driver' => 'dropbox', 'token' => 'x']]);

        if (class_exists('Spatie\\FlysystemDropbox\\DropboxAdapter')) {
            $this->markTestSkipped('The Dropbox adapter is installed.');
        }

        $this->expectExceptionObject(new RuntimeException('The "dropbox" disk needs spatie/flysystem-dropbox: composer require spatie/flysystem-dropbox'));

        Storage::disk('drive');
    }

    public function test_google_drive_without_its_adapter_names_the_package_to_install(): void
    {
        config(['filesystems.disks.drive' => ['driver' => 'google']]);

        $this->expectExceptionObject(new RuntimeException('The "google" disk needs masbug/flysystem-google-drive-ext: composer require masbug/flysystem-google-drive-ext'));

        Storage::disk('drive');
    }

    /**
     * Half an install (the adapter without Google's client, or the client
     * without its Drive service) is no install.
     *
     * @return array<string,array{0:string}>
     */
    public static function halfGoogleInstalls(): array
    {
        return [
            'no client' => ['namespace Masbug\\Flysystem; class GoogleDriveAdapter {} namespace Google\\Service; class Drive {}'],
            'no drive service' => ['namespace Masbug\\Flysystem; class GoogleDriveAdapter {} namespace Google; class Client {}'],
        ];
    }

    #[RunInSeparateProcess]
    #[DataProvider('halfGoogleInstalls')]
    public function test_google_drive_needs_every_piece_of_its_package(string $classes): void
    {
        eval($classes);

        $this->expectExceptionObject(new RuntimeException('The "google" disk needs masbug/flysystem-google-drive-ext: composer require masbug/flysystem-google-drive-ext'));

        CloudDrives::google([]);
    }

    #[RunInSeparateProcess]
    public function test_dropbox_needs_its_client_too(): void
    {
        eval('namespace Spatie\\FlysystemDropbox; class DropboxAdapter {}');

        $this->expectExceptionObject(new RuntimeException('The "dropbox" disk needs spatie/flysystem-dropbox: composer require spatie/flysystem-dropbox'));

        CloudDrives::dropbox(['token' => 'x']);
    }

    #[RunInSeparateProcess]
    public function test_google_drive_is_built_as_its_adapter_documents(): void
    {
        require __DIR__.'/../Fixtures/cloud-drive-adapters.php';
        config(['filesystems.disks.drive' => ['driver' => 'google', 'clientId' => 'id', 'clientSecret' => 'secret', 'refreshToken' => 'refresh', 'folder' => 'Backups']]);

        $adapter = Storage::disk('drive')->getAdapter();

        $this->assertInstanceOf(GoogleDriveAdapter::class, $adapter);
        $this->assertSame(['clientId' => 'id', 'clientSecret' => 'secret', 'accessType' => 'offline', 'refreshToken' => 'refresh'], $adapter->service->client->calls);
        $this->assertSame('Backups', $adapter->root);
        $this->assertSame(['useDisplayPaths' => true], $adapter->options);

        // Nothing set: empty credentials and the drive's root, for Google to refuse.
        config(['filesystems.disks.bare' => ['driver' => 'google']]);
        $bare = Storage::disk('bare')->getAdapter();
        $this->assertSame(['clientId' => '', 'clientSecret' => '', 'accessType' => 'offline', 'refreshToken' => ''], $bare->service->client->calls);
        $this->assertSame('/', $bare->root);
    }

    #[RunInSeparateProcess]
    public function test_dropbox_is_built_with_its_token_and_folder(): void
    {
        require __DIR__.'/../Fixtures/cloud-drive-adapters.php';
        config([
            'filesystems.disks.box' => ['driver' => 'dropbox', 'token' => 'fixed', 'root' => 'backups'],
            'filesystems.disks.bare' => ['driver' => 'dropbox'],
        ]);

        $adapter = Storage::disk('box')->getAdapter();

        $this->assertInstanceOf(DropboxAdapter::class, $adapter);
        $this->assertSame('fixed', $adapter->client->token);
        $this->assertSame('backups', $adapter->prefix);
        $this->assertSame('', Storage::disk('bare')->getAdapter()->prefix);
    }

    public function test_dropbox_trades_the_refresh_token_for_an_access_token(): void
    {
        Http::fake(['api.dropboxapi.com/*' => Http::response(['access_token' => 'short-lived', 'expires_in' => 14400])]);

        $token = CloudDrives::dropboxToken(['app_key' => 'key', 'app_secret' => 'secret', 'refresh_token' => 'long-lived']);

        $this->assertSame('short-lived', $token);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.dropboxapi.com/oauth2/token'
            && $request['grant_type'] === 'refresh_token'
            && $request['refresh_token'] === 'long-lived'
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('key:secret')));
    }

    public function test_a_fixed_token_is_used_as_it_is(): void
    {
        Http::fake();

        $this->assertSame('fixed', CloudDrives::dropboxToken(['token' => 'fixed']));
        Http::assertNothingSent();
    }

    public function test_a_refused_refresh_says_so(): void
    {
        Http::fake(['*' => Http::response(['error' => 'invalid_grant'], 400)]);

        $this->expectExceptionObject(new RuntimeException('Dropbox did not give an access token: 400 {"error":"invalid_grant"}'));

        CloudDrives::dropboxToken(['app_key' => 'k', 'app_secret' => 's', 'refresh_token' => 'r']);
    }

    /**
     * @return array<string,array{0:int,1:array<string,mixed>}>
     */
    public static function answersWithoutAToken(): array
    {
        return [
            'an error status, whatever the body' => [500, ['access_token' => 'stale']],
            'success without a token' => [200, ['expires_in' => 14400]],
        ];
    }

    /**
     * @param  array<string,mixed>  $body
     */
    #[DataProvider('answersWithoutAToken')]
    public function test_an_answer_without_a_token_is_refused(int $status, array $body): void
    {
        Http::fake(['*' => Http::response($body, $status)]);

        $this->expectExceptionObject(new RuntimeException('Dropbox did not give an access token: '.$status.' '.json_encode($body)));

        CloudDrives::dropboxToken(['refresh_token' => 'r']);
    }
}
