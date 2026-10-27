<?php

namespace Webkul\Recruitment\Services\Website;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;
use Webkul\Recruitment\Models\WebsiteConnection;

/**
 * The Aureus side of the WordPress API contract v1 (automation plan §7):
 * HTTP Basic with the integration user's Application Password, over HTTPS,
 * to {base_url}/wp-json/aureus/v1. Outbound only. Each call times out after
 * recruitment.website.timeout seconds and is retried with a pause on
 * connection errors and 5xx answers; any other failure throws a
 * WebsiteRequestException at once. Redirects are not followed, so the
 * password is never sent anywhere but the configured address.
 */
class WordPressCareersClient
{
    public function __construct(protected WebsiteConnection $connection) {}

    public static function for(WebsiteConnection $connection): static
    {
        return new static($connection);
    }

    /** GET ping: the plugin is installed and the credentials are accepted. */
    public function ping(): array
    {
        return (array) $this->send('GET', 'ping')->json();
    }

    /**
     * PUT jobs/{token}: create or update the job's page. Returns the public job URL.
     *
     * @param  array<string, mixed>  $payload
     */
    public function putJob(string $token, array $payload): string
    {
        $url = $this->send('PUT', 'jobs/'.$this->token($token), ['json' => $payload])->json('url');

        if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false || ! in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
            throw new WebsiteRequestException('The careers website accepted the job but did not return its address ("url").');
        }

        return $url;
    }

    /** DELETE jobs/{token}: take the job off the website. A job the website no longer has counts as removed. */
    public function deleteJob(string $token): void
    {
        $this->send('DELETE', 'jobs/'.$this->token($token), allowNotFound: true);
    }

    /**
     * GET applications?status=new&limit=N
     *
     * @return array<int, array<string, mixed>>
     */
    public function applications(int $limit = 50): array
    {
        $data = $this->send('GET', 'applications', ['query' => ['status' => 'new', 'limit' => max(1, min(100, $limit))]])->json();

        if (! is_array($data) || ! array_is_list($data)) {
            throw new WebsiteRequestException('The careers website sent an unexpected list of applications.');
        }

        return array_values(array_filter($data, 'is_array'));
    }

    /**
     * GET applications/{id}/cv, read up to $maxBytes.
     *
     * @return array{contents: string, mime: ?string, filename: ?string}
     */
    public function downloadCv(int $applicationId, int $maxBytes): array
    {
        $response = $this->send('GET', "applications/{$applicationId}/cv", ['stream' => true]);

        $length = $response->header('Content-Length');
        if (is_numeric($length) && (int) $length > $maxBytes) {
            throw new WebsiteRequestException('The CV is larger than the '.intdiv($maxBytes, 1024).' KB limit.');
        }

        $body = $response->toPsrResponse()->getBody();
        $contents = '';

        while (! $body->eof()) {
            $contents .= $body->read(65536);

            if (strlen($contents) > $maxBytes) {
                throw new WebsiteRequestException('The CV is larger than the '.intdiv($maxBytes, 1024).' KB limit.');
            }
        }

        $filename = null;
        if (preg_match('/filename\*?=(?:UTF-8\'\')?"?([^";]+)"?/i', (string) $response->header('Content-Disposition'), $match) === 1) {
            $filename = rawurldecode(trim($match[1]));
        }

        return [
            'contents' => $contents,
            'mime'     => $response->header('Content-Type') ?: null,
            'filename' => $filename,
        ];
    }

    /** POST applications/{id}/ack: the website marks it imported and deletes its CV copy. */
    public function ack(int $applicationId, ?int $applicantId): void
    {
        $this->send('POST', "applications/{$applicationId}/ack", ['json' => ['aureus_applicant_id' => $applicantId]]);
    }

    protected function request(): PendingRequest
    {
        $delays = array_values(array_map('intval', (array) config('recruitment.website.retry_delays_ms', [500, 1500])));

        $request = Http::baseUrl($this->connection->apiBaseUrl())
            ->withBasicAuth((string) $this->connection->username, (string) $this->connection->app_password)
            ->acceptJson()
            ->withUserAgent('AureusERP-Careers/1')
            ->timeout(max(1, (int) config('recruitment.website.timeout', 10)))
            ->connectTimeout(max(1, (int) config('recruitment.website.connect_timeout', 5)))
            ->withOptions(['allow_redirects' => false]);

        if ($delays === []) {
            return $request;
        }

        // A 3xx answer reaches this callback as null: never retried, reported by send().
        return $request->retry(
            $delays,
            0,
            fn (?Throwable $e): bool => $e instanceof ConnectionException
                || ($e instanceof RequestException && $e->response->serverError()),
        );
    }

    /** @param array<string, mixed> $options */
    protected function send(string $method, string $path, array $options = [], bool $allowNotFound = false): Response
    {
        $stream = (bool) ($options['stream'] ?? false);
        unset($options['stream']);

        $request = $this->request();

        if ($stream) {
            $request = $request->withOptions(['stream' => true]);
        }

        try {
            $response = $request->send($method, $path, $options);
        } catch (ConnectionException $e) {
            throw new WebsiteRequestException("Could not reach the careers website at {$this->connection->base_url}: ".$this->clean($e->getMessage()), null, $e);
        } catch (RequestException $e) {
            if ($allowNotFound && $e->response->status() === 404) {
                return $e->response;
            }

            throw $this->failure($e->response, $method, $path, $e);
        }

        if ($allowNotFound && $response->status() === 404) {
            return $response;
        }

        if (! $response->successful()) {
            throw $this->failure($response, $method, $path);
        }

        return $response;
    }

    protected function failure(Response $response, string $method, string $path, ?Throwable $previous = null): WebsiteRequestException
    {
        $status = $response->status();
        $detail = null;

        try {
            $detail = $response->json('message');
        } catch (Throwable) {
            $detail = null;
        }

        $hint = match (true) {
            $status === 401, $status === 403 => ' Check the WordPress user and its Application Password, and that the host passes the Authorization header.',
            $status === 404                  => ' Check the website address and that the Aureus Careers plugin is active.',
            $status >= 300 && $status < 400  => ' The website redirected the request: enter its final address (e.g. with or without www).',
            default                          => '',
        };

        $message = "The careers website answered {$status} to {$method} /".ltrim($path, '/')
            .(is_string($detail) && $detail !== '' ? ': '.$this->clean($detail) : '.')
            .$hint;

        return new WebsiteRequestException(Str::limit($message, 900, '…'), $status, $previous);
    }

    /** 32-hex tokens only, so nothing else can reach the URL path. */
    protected function token(string $token): string
    {
        if (preg_match('/^[0-9a-f]{32}$/', $token) !== 1) {
            throw new WebsiteRequestException('Invalid job token.');
        }

        return $token;
    }

    protected function clean(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', strip_tags($text)));

        // Never echo credentials that a library may have put into a message.
        $password = (string) $this->connection->app_password;

        return Str::limit($password !== '' ? str_replace($password, '***', $text) : $text, 400, '…');
    }
}
