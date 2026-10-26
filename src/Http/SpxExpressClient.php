<?php

namespace Laraditz\Courier\SpxExpress\Http;

use Laraditz\Courier\Exceptions\CourierException;
use Laraditz\Courier\Http\CourierHttpClient;

class SpxExpressClient
{
    private readonly SpxExpressSigner $signer;

    private CourierHttpClient $http;

    // CourierHttpClient is not container-bound, so constructing one here is correct;
    // the parameter exists so tests can inject their own.
    public function __construct(
        private readonly array $config,
        ?SpxExpressSigner $signer = null,
        ?CourierHttpClient $http = null,
    ) {
        $this->signer = $signer ?? new SpxExpressSigner(
            (string) ($config['app_id'] ?? ''),
            (string) ($config['app_secret'] ?? ''),
        );
        $this->http = $http ?? new CourierHttpClient();
    }

    /**
     * POSTs to {host}/open/api/v1/{$path} and returns the decoded envelope.
     *
     * $path doubles as the logged action — every SPX call goes through here.
     * $reference/$waybillNumber are the log context only; they are never sent.
     *
     * Throws on a non-2xx response or a non-zero top-level ret_code. A zero
     * ret_code can still carry per-order failures in data.fail_list — SPX's batch
     * endpoints report those individually, so reading them is the caller's job.
     */
    public function dispatch(
        string $path,
        array $body,
        ?string $reference = null,
        ?string $waybillNumber = null,
    ): array {
        // user_id is an integer in SPX's schema; sent as a string it is rejected.
        $body = [
            'user_id'     => (int) ($this->config['user_id'] ?? 0),
            'user_secret' => (string) ($this->config['user_secret'] ?? ''),
        ] + $body;

        // Signed over json_encode() with no flags, which is byte-for-byte what
        // Laravel's HTTP client (via Guzzle's Utils::jsonEncode) puts on the wire
        // for a JSON body. Adding flags here — JSON_UNESCAPED_SLASHES, say — would
        // sign a different string from the one sent. ClientTest pins this.
        $payload   = json_encode($body);
        $timestamp = time();
        $randomNum = random_int(0, PHP_INT_MAX);

        // forLog() is called on every request, immediately before the verb. It mutates
        // and returns $this, and leaves configured = true, so an inherited context would
        // log silently against the previous call's action. Never rely on it persisting.
        $response = $this->http
            ->forLog('spxexpress', $path, $reference, $waybillNumber)
            ->timeout($this->config['timeout'] ?? 30)
            ->post(
                $this->baseUrl() . '/open/api/v1/' . ltrim($path, '/'),
                $body,
                [
                    'app-id'     => (string) ($this->config['app_id'] ?? ''),
                    'check-sign' => $this->signer->sign($payload, $timestamp, $randomNum),
                    'timestamp'  => (string) $timestamp,
                    'random-num' => (string) $randomNum,
                ],
            );

        if ($response->failed()) {
            throw new CourierException(
                'SPX Express API error (' . $response->status() . '): ' . $response->body()
            );
        }

        $data = $response->json();

        if (! is_array($data) || (int) ($data['ret_code'] ?? -1) !== 0) {
            throw new CourierException(
                'SPX Express business error [' . ($data['ret_code'] ?? 'unknown') . ']: ' .
                ($data['message'] ?? $response->body())
            );
        }

        return $data;
    }

    private function baseUrl(): string
    {
        $url = ($this->config['sandbox'] ?? false)
            ? ($this->config['sandbox_url'] ?? '')
            : ($this->config['base_url'] ?? '');

        return rtrim($url, '/');
    }
}
