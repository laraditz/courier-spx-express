<?php

namespace Laraditz\Courier\SpxExpress\Http;

class SpxExpressSigner
{
    public function __construct(
        private readonly string $appId,
        private readonly string $appSecret,
    ) {}

    /**
     * SPX's check-sign: HMAC-SHA256 over "{app_id}_{timestamp}_{random_num}_{body}",
     * keyed with the app secret, as lowercase hex.
     *
     * $payload must be the exact bytes on the wire. Re-encoding a decoded body can
     * reorder keys or change escaping, and the signature then no longer matches.
     * The timestamp and random number are taken as strings so an inbound webhook
     * is checked against the header values verbatim.
     */
    public function sign(string $payload, int|string $timestamp, int|string $randomNum): string
    {
        return hash_hmac(
            'sha256',
            "{$this->appId}_{$timestamp}_{$randomNum}_{$payload}",
            $this->appSecret,
        );
    }
}
