<?php

namespace Laraditz\Courier\SpxExpress;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laraditz\Courier\Contracts\CourierDriver;
use Laraditz\Courier\Contracts\ExtractsWebhookReference;
use Laraditz\Courier\Contracts\HandlesWebhooks;
use Laraditz\Courier\DTOs\Payloads\AvailabilityPayload;
use Laraditz\Courier\DTOs\Payloads\RatePayload;
use Laraditz\Courier\DTOs\Payloads\ShipmentPayload;
use Laraditz\Courier\DTOs\Results\CancelResult;
use Laraditz\Courier\DTOs\Results\LabelResult;
use Laraditz\Courier\DTOs\Results\RateCollection;
use Laraditz\Courier\DTOs\Results\ServiceCollection;
use Laraditz\Courier\DTOs\Results\ShipmentResult;
use Laraditz\Courier\DTOs\Results\TrackingResult;
use Laraditz\Courier\DTOs\Shared\Address;
use Laraditz\Courier\Enums\DeliveryMode;
use Laraditz\Courier\Enums\FulfillmentMode;
use Laraditz\Courier\Exceptions\CancellationException;
use Laraditz\Courier\Exceptions\CourierException;
use Laraditz\Courier\Exceptions\InvalidPayloadException;
use Laraditz\Courier\Exceptions\LabelFetchException;
use Laraditz\Courier\Exceptions\RateFetchException;
use Laraditz\Courier\Exceptions\ShipmentCreationException;
use Laraditz\Courier\Exceptions\ShipmentNotFoundException;
use Laraditz\Courier\SpxExpress\Events\TrackingUpdated;
use Laraditz\Courier\SpxExpress\Http\SpxExpressClient;
use Laraditz\Courier\SpxExpress\Http\SpxExpressSigner;
use Laraditz\Courier\SpxExpress\Mappers\CancelMapper;
use Laraditz\Courier\SpxExpress\Mappers\FailList;
use Laraditz\Courier\SpxExpress\Mappers\LabelMapper;
use Laraditz\Courier\SpxExpress\Mappers\RateMapper;
use Laraditz\Courier\SpxExpress\Mappers\ShipmentMapper;
use Laraditz\Courier\SpxExpress\Mappers\TrackingMapper;

class SpxExpressDriver implements CourierDriver, HandlesWebhooks, ExtractsWebhookReference
{
    /**
     * Seconds of clock skew tolerated on the `timestamp` header before a push is
     * treated as a replay. Set `webhook_timestamp_tolerance` to 0 to disable.
     */
    private const DEFAULT_TIMESTAMP_TOLERANCE = 900;

    /**
     * SPX's order_id limit. A UUID (36) does not fit, so generated references are
     * ULIDs (26).
     */
    private const MAX_REFERENCE_LENGTH = 31;

    /** Pickup dates are calendar days in Malaysia, whatever the app's timezone. */
    private const TIMEZONE = 'Asia/Kuala_Lumpur';

    private const COLLECT_PICKUP = 1;
    private const COLLECT_DROPOFF = 2;

    private SpxExpressClient $client;
    private SpxExpressSigner $signer;

    public function __construct(private readonly array $config, ?SpxExpressClient $client = null)
    {
        $this->client = $client ?? new SpxExpressClient($config);
        $this->signer = new SpxExpressSigner(
            (string) ($config['app_id'] ?? ''),
            (string) ($config['app_secret'] ?? ''),
        );
    }

    /**
     * Supported $payload->meta keys:
     *  - cod_amount (float): collect this much on delivery (MY limit RM500, selected postcodes only)
     *  - voucher_code (string)
     *  - pickup_time (int) + pickup_time_range_id (int): book this exact pickup slot,
     *    skipping the timeslot lookup. pickup_time_range_id alone picks that slot on
     *    the resolved day.
     */
    public function createShipment(ShipmentPayload $payload): ShipmentResult
    {
        $reference = $payload->reference ?? (string) Str::ulid();

        if (strlen($reference) > self::MAX_REFERENCE_LENGTH) {
            throw new InvalidPayloadException(
                "SPX Express order references are limited to 31 characters; [{$reference}] is " . strlen($reference) . '.'
            );
        }

        $collectType = $this->collectTypeFor($payload->fulfillment);
        $codAmount   = (float) ($payload->meta['cod_amount'] ?? 0);

        $fulfillment = [
            'payment_role'   => 1, // sender pays — the only value SPX accepts in Malaysia
            'cod_collection' => $codAmount > 0 ? 1 : 0,
            'collect_type'   => $collectType,
        ];

        if ($codAmount > 0) {
            $fulfillment['cod_amount'] = round($codAmount, 2);
        }

        if (isset($payload->meta['voucher_code'])) {
            $fulfillment['voucher_code'] = (string) $payload->meta['voucher_code'];
        }

        if ($collectType === self::COLLECT_PICKUP) {
            $fulfillment += $this->pickupSlot($payload);
        }

        $order = [
            'order_id'         => $reference,
            'base_info'        => ['service_type' => $this->serviceType($payload->serviceCode)],
            'sender_info'      => $this->party('sender', $payload->sender),
            'fulfillment_info' => $fulfillment,
            'deliver_info'     => $this->party('deliver', $payload->recipient) + array_filter([
                'deliver_instruction' => $payload->remarks,
            ]),
            'parcel_info'      => [
                'parcel_weight'        => $payload->parcel->weight,
                'parcel_length'        => $payload->parcel->length,
                'parcel_width'         => $payload->parcel->width,
                'parcel_height'        => $payload->parcel->height,
                'parcel_item_name'     => $payload->parcel->description,
                'parcel_item_quantity' => $payload->parcel->quantity,
                'parcel_value'         => $payload->parcel->declaredValue,
            ],
        ];

        try {
            $response = $this->client->dispatch('order/batch_create_order', ['orders' => [$order]], reference: $reference);
        } catch (CourierException $e) {
            throw new ShipmentCreationException($e->getMessage(), previous: $e);
        }

        $created = $response['data']['orders'][0] ?? null;

        if (! is_array($created) || ($created['tracking_no'] ?? '') === '') {
            throw new ShipmentCreationException(
                "SPX Express rejected order [{$reference}]: " .
                FailList::describe(FailList::for($response['data'] ?? [], 'order_id', $reference))
            );
        }

        return ShipmentMapper::map($created, $reference);
    }

    public function getShipment(string $reference): ShipmentResult
    {
        $order = $this->search(['order_id_list' => [$reference]], $reference, reference: $reference);

        return ShipmentMapper::mapFromSearch($order, $reference);
    }

    /**
     * SPX notes an order can take a few seconds after creation to become searchable;
     * until then it reports "cannot be found", which surfaces here as
     * ShipmentNotFoundException.
     */
    public function track(string $trackingNumber): TrackingResult
    {
        $order = $this->search(['tracking_no_list' => [$trackingNumber]], $trackingNumber, waybillNumber: $trackingNumber);

        return TrackingMapper::map($order, $trackingNumber);
    }

    /**
     * SPX requires state and city here, unlike on order creation where it maps them
     * from the postcode: the sandbox rejects a rate check with them blank as an
     * unserviceable area.
     */
    public function getRates(RatePayload $payload): RateCollection
    {
        $serviceType = $this->serviceType($payload->serviceCode);

        try {
            $response = $this->client->dispatch('order/batch_check_order', [
                'orders' => [[
                    'base_info'        => ['service_type' => $serviceType],
                    'sender_info'      => [
                        'sender_state'     => $payload->origin->state,
                        'sender_city'      => $payload->origin->city,
                        'sender_post_code' => $payload->origin->postcode,
                    ],
                    'fulfillment_info' => ['collect_type' => $this->collectTypeFor(null)],
                    'deliver_info'     => [
                        'deliver_state'     => $payload->destination->state,
                        'deliver_city'      => $payload->destination->city,
                        'deliver_post_code' => $payload->destination->postcode,
                    ],
                    'parcel_info'      => [
                        'parcel_weight'        => $payload->parcel->weight,
                        'parcel_length'        => $payload->parcel->length,
                        'parcel_width'         => $payload->parcel->width,
                        'parcel_height'        => $payload->parcel->height,
                        'parcel_item_quantity' => $payload->parcel->quantity,
                        // Rejected when null ("parcel value can not be null or less than 0").
                        'parcel_value'         => $payload->parcel->declaredValue,
                    ],
                ]],
            ]);
        } catch (CourierException $e) {
            throw new RateFetchException($e->getMessage(), previous: $e);
        }

        $quote = $response['data']['orders'][0] ?? null;

        if (! is_array($quote)) {
            throw new RateFetchException(
                'SPX Express returned no rate: ' . FailList::describe(FailList::for($response['data'] ?? [], 'order_id', ''))
            );
        }

        return RateMapper::map($quote, $serviceType);
    }

    /**
     * Keyed on the waybill alone; $reference is accepted for the interface and used
     * only as log context.
     */
    public function cancelShipment(string $waybillNumber, ?string $reference = null): CancelResult
    {
        try {
            $response = $this->client->dispatch('order/batch_cancel_order', [
                'tracking_no_list' => [$waybillNumber],
            ], reference: $reference, waybillNumber: $waybillNumber);
        } catch (CourierException $e) {
            throw new CancellationException($e->getMessage(), previous: $e);
        }

        return CancelMapper::map($response['data'] ?? [], $waybillNumber);
    }

    /**
     * Returns a 'url' label. SPX's link expires after 30 minutes or 10 opens.
     */
    public function getLabel(string $waybillNumber, ?string $reference = null): LabelResult
    {
        try {
            $response = $this->client->dispatch('order/batch_get_shipping_label', [
                'tracking_no_list' => [$waybillNumber],
            ], reference: $reference, waybillNumber: $waybillNumber);
        } catch (CourierException $e) {
            throw new LabelFetchException($e->getMessage(), previous: $e);
        }

        return LabelMapper::map($response['data'] ?? [], $waybillNumber);
    }

    /**
     * SPX Malaysia has one service and no availability endpoint; whether a route is
     * serviceable is answered by getRates(), which fails for an unsupported postcode.
     *
     * Returns an empty collection rather than throwing, for the same reason as the
     * J&T driver: "nothing to choose from" is a legitimate answer.
     */
    public function getAvailability(AvailabilityPayload $payload): ServiceCollection
    {
        return new ServiceCollection([]);
    }

    public function getDeliveryModes(): array
    {
        return [DeliveryMode::Scheduled];
    }

    /**
     * The pickup days and slots SPX offers this account, as returned by
     * get_pickup_time: a list of ['date', 'pickup_time', 'slots' => [['pickup_time_range_id',
     * 'pickup_time_range'], ...]].
     *
     * Not part of CourierDriver — call it on the driver directly to let a sender
     * choose a slot, then pass it back via ShipmentPayload meta.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getPickupTimeslots(): array
    {
        $response = $this->client->dispatch('order/get_pickup_time', [
            'service_type' => $this->serviceType(''),
        ]);

        return array_values(array_filter($response['data'] ?? [], 'is_array'));
    }

    /**
     * Checks check-sign the way SPX computes it for outbound calls, over the raw
     * request body and the pushed timestamp and random-num headers.
     *
     * The raw body matters: decoding and re-encoding the JSON can change key order
     * or escaping, and the signature would no longer match a genuine push.
     */
    public function verifyWebhook(Request $request): bool
    {
        $signature = (string) $request->header('check-sign', '');
        $timestamp = (string) $request->header('timestamp', '');
        $randomNum = (string) $request->header('random-num', '');

        if ($signature === '' || ! ctype_digit($timestamp) || preg_match('/^-?\d+$/', $randomNum) !== 1) {
            return false;
        }

        if (! $this->timestampIsFresh((int) $timestamp)) {
            return false;
        }

        $expected = $this->signer->sign($request->getContent(), $timestamp, $randomNum);

        return hash_equals($expected, strtolower($signature));
    }

    public function extractWebhookReference(Request $request): array
    {
        $body = $this->body($request);

        return [
            'reference'     => isset($body['order_id']) ? (string) $body['order_id'] : null,
            'waybillNumber' => isset($body['tracking_no']) ? (string) $body['tracking_no'] : null,
        ];
    }

    /**
     * Raises TrackingUpdated for a Tracking Web Hook push. SPX's other webhooks
     * (order create, shipping fee, evidence proofs) carry no status_code and raise
     * nothing here — the core WebhookReceived event still fires for them.
     */
    public function handleWebhook(Request $request): void
    {
        $body = $this->body($request);

        if (! isset($body['status_code'], $body['tracking_no'])) {
            return;
        }

        $statusCode = (string) $body['status_code'];

        event(new TrackingUpdated(
            trackingNo: (string) $body['tracking_no'],
            orderId: isset($body['order_id']) ? (string) $body['order_id'] : null,
            statusCode: $statusCode,
            mappedStatus: TrackingMapper::mapStatus($statusCode),
            raw: $body,
        ));
    }

    private function body(Request $request): array
    {
        $decoded = json_decode($request->getContent(), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * The `<= 0` guard is deliberate: a zero or negative tolerance disables the
     * check, matching the J&T driver.
     */
    private function timestampIsFresh(int $timestamp): bool
    {
        $tolerance = (int) ($this->config['webhook_timestamp_tolerance'] ?? self::DEFAULT_TIMESTAMP_TOLERANCE);

        if ($tolerance <= 0) {
            return true;
        }

        return abs(time() - $timestamp) <= $tolerance;
    }

    /**
     * Runs batch_search_order for a single order and returns its entry.
     *
     * @param  array<string, list<string>>  $criteria
     */
    private function search(array $criteria, string $identifier, ?string $reference = null, ?string $waybillNumber = null): array
    {
        try {
            $response = $this->client->dispatch('order/batch_search_order', $criteria, $reference, $waybillNumber);
        } catch (CourierException $e) {
            throw new ShipmentNotFoundException("SPX order [{$identifier}] not found.", previous: $e);
        }

        $order = $response['data']['orders'][0] ?? null;

        if (! is_array($order) || ($order['tracking_no'] ?? '') === '') {
            throw new ShipmentNotFoundException("SPX order [{$identifier}] not found.");
        }

        return $order;
    }

    /**
     * The pickup_time / pickup_time_range_id pair SPX requires for a pickup.
     *
     * An explicit pair in meta is sent as-is. Otherwise the slot is looked up: the
     * day matching scheduledAt (in Malaysian time) or, without one, the first day
     * offering any slot. Failing rather than falling back to a drop-off — the
     * sender asked for a rider, and silently booking a walk-in would leave the
     * parcel uncollected.
     *
     * @return array<string, int|string>
     */
    private function pickupSlot(ShipmentPayload $payload): array
    {
        $meta = $payload->meta;

        if (isset($meta['pickup_time'], $meta['pickup_time_range_id'])) {
            return [
                'pickup_time'          => (int) $meta['pickup_time'],
                'pickup_time_range_id' => (int) $meta['pickup_time_range_id'],
            ];
        }

        $wantedDay = $payload->scheduledAt?->copy()->setTimezone(self::TIMEZONE)->format('Y-m-d');
        $wantedId  = isset($meta['pickup_time_range_id']) ? (int) $meta['pickup_time_range_id'] : null;

        foreach ($this->getPickupTimeslots() as $day) {
            $dayKey = Carbon::createFromTimestamp((int) ($day['pickup_time'] ?? 0), self::TIMEZONE)->format('Y-m-d');

            if ($wantedDay !== null && $dayKey !== $wantedDay) {
                continue;
            }

            foreach ($day['slots'] ?? [] as $slot) {
                if ($wantedId !== null && (int) ($slot['pickup_time_range_id'] ?? 0) !== $wantedId) {
                    continue;
                }

                return array_filter([
                    'pickup_time'          => (int) $day['pickup_time'],
                    'pickup_time_range_id' => (int) $slot['pickup_time_range_id'],
                    'pickup_time_range'    => (string) ($slot['pickup_time_range'] ?? ''),
                ], static fn ($value) => $value !== '');
            }
        }

        throw new InvalidPayloadException(
            'SPX Express offers no pickup slot' .
            ($wantedDay !== null ? " on {$wantedDay}" : '') .
            ' for this account. Book a drop-off instead, or choose a slot from getPickupTimeslots().'
        );
    }

    private function collectTypeFor(?FulfillmentMode $fulfillment): int
    {
        $fulfillment ??= FulfillmentMode::tryFrom((string) ($this->config['default_fulfillment'] ?? ''))
            ?? FulfillmentMode::Dropoff;

        return $fulfillment === FulfillmentMode::Pickup ? self::COLLECT_PICKUP : self::COLLECT_DROPOFF;
    }

    private function serviceType(string $serviceCode): int
    {
        return ctype_digit($serviceCode) && $serviceCode !== '0'
            ? (int) $serviceCode
            : (int) ($this->config['service_type'] ?? 1);
    }

    /**
     * SPX's {sender|deliver}_* address fields.
     *
     * State and city are sent but not load-bearing on creation: SPX maps them from
     * the postcode and overwrites what it is given (Shah Alam / 40000 comes back as
     * Petaling). Every street line is folded into detail_address, the only street
     * field SPX has.
     *
     * @return array<string, string>
     */
    private function party(string $prefix, Address $address): array
    {
        $street = array_filter(
            [$address->line1, $address->line2, $address->line3],
            static fn (?string $line): bool => $line !== null && trim($line) !== '',
        );

        $fields = [
            "{$prefix}_name"           => $address->name,
            "{$prefix}_phone"          => $this->phone($address->phone),
            "{$prefix}_detail_address" => implode(', ', $street),
            "{$prefix}_state"          => $address->state,
            "{$prefix}_city"           => $address->city,
            "{$prefix}_post_code"      => $address->postcode,
        ];

        if ($address->lat !== null && $address->lng !== null) {
            $fields["{$prefix}_latitude"]  = (string) $address->lat;
            $fields["{$prefix}_longitude"] = (string) $address->lng;
        }

        return $fields;
    }

    /**
     * SPX validates MY numbers as digits only: 9–11 starting 0, or 10–12 starting 60.
     * '+60 12-345 6789' becomes '60123456789'.
     */
    private function phone(?string $phone): string
    {
        return preg_replace('/\D+/', '', (string) $phone);
    }
}
