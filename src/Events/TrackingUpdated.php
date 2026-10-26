<?php

namespace Laraditz\Courier\SpxExpress\Events;

readonly class TrackingUpdated
{
    public function __construct(
        public string $trackingNo,
        public ?string $orderId,
        public string $statusCode,
        public string $mappedStatus,
        public array $raw,
    ) {}
}
