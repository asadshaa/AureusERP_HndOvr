<?php

namespace Webkul\Accounting\Console\Commands;

use Google\Client;
use Illuminate\Console\Command;

/**
 * One-time, interactive, run-locally-by-a-human setup step -- NOT
 * something the application runs itself. This account is a personal
 * Google Drive (no Workspace, no domain-wide delegation available), so
 * there's no way to mint a refresh token without a real person
 * consenting through Google's own login screen. This command exists so
 * that consent happens explicitly, in the account owner's own browser,
 * rather than any automated process touching their Google credentials.
 */
class AuthorizeDriveCommand extends Command
{
    protected $signature = 'accounting:drive:authorize';

    protected $description = 'One-time setup: obtain a Google Drive refresh token by authorizing this app in your browser';

    public function handle(): int
    {
        $clientId = config('accounting_drive.client_id');
        $clientSecret = config('accounting_drive.client_secret');

        if (! $clientId || ! $clientSecret) {
            $this->error('Set ACCOUNTING_DRIVE_CLIENT_ID and ACCOUNTING_DRIVE_CLIENT_SECRET in .env first -- from a "Desktop app" OAuth client you create in Google Cloud Console.');

            return self::FAILURE;
        }

        $client = new Client;
        $client->setClientId($clientId);
        $client->setClientSecret($clientSecret);
        $client->setRedirectUri('urn:ietf:wg:oauth:2.0:oob');
        $client->setScopes(config('accounting_drive.scopes', []));
        $client->setAccessType('offline');
        $client->setPrompt('consent');

        $this->info('1. Open this URL and sign in with the Google account that should own the Drive folder structure:');
        $this->line($client->createAuthUrl());
        $this->newLine();

        $code = $this->ask('2. Paste the authorization code Google gives you');

        try {
            $token = $client->fetchAccessTokenWithAuthCode($code);
        } catch (\Throwable $e) {
            $this->error('Could not exchange that code for a token: '.$e->getMessage());

            return self::FAILURE;
        }

        if (! isset($token['refresh_token'])) {
            $this->error('Google did not return a refresh token. This usually means an existing authorization from a previous run needs to be revoked first, at https://myaccount.google.com/permissions -- then try again.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Success. Add this to your .env:');
        $this->line('ACCOUNTING_DRIVE_REFRESH_TOKEN='.$token['refresh_token']);

        return self::SUCCESS;
    }
}
