# laraditz/courier-spx-express

[![Latest Version on Packagist](https://img.shields.io/packagist/v/laraditz/courier-spx-express.svg?style=flat-square)](https://packagist.org/packages/laraditz/courier-spx-express)
[![Total Downloads](https://img.shields.io/packagist/dt/laraditz/courier-spx-express.svg?style=flat-square)](https://packagist.org/packages/laraditz/courier-spx-express)

SPX Express (Shopee Xpress) Malaysia driver for [laraditz/courier](https://github.com/laraditz/courier).

Targets the **SPX Open Service API** (`spx.com.my/open/api/v1`), covering Malaysian domestic shipping only. API reference: <https://spx.com.my/integration/>.

## Requirements

- PHP 8.1+
- Laravel 10, 11, 12, or 13
- `laraditz/courier` ^1.0
- SPX Open Platform credentials. SPX staff issue these on request; there is no self-serve signup.

## Installation

```bash
composer require laraditz/courier-spx-express
```

Both service providers are auto-discovered. Publish the config:

```bash
php artisan vendor:publish --tag=courier-config
php artisan vendor:publish --tag=courier-spx-express-config
```

## Configuration

SPX issues two sets of credentials, and both are required:

| Pair | What it is | Where it goes |
|---|---|---|
| App ID + Secret Key | Your developer app. Signs every request. | `app-id` / `check-sign` headers |
| User ID + User Secret | The SPX sender account that shipments are booked and billed against | Every request body |

Add to your `.env`:

```env
COURIER_DRIVER=spxexpress

SPXEXPRESS_APP_ID=your-app-id
SPXEXPRESS_APP_SECRET=your-secret-key
SPXEXPRESS_USER_ID=your-user-id
SPXEXPRESS_USER_SECRET=your-user-secret
SPXEXPRESS_SANDBOX=true
```

| Key | Default | Description |
|---|---|---|
| `app_id` / `app_secret` | — | App ID and Secret Key from SPX |
| `user_id` / `user_secret` | — | Sender account User ID and User Secret |
| `service_type` | `1` | SPX `service_type`. Malaysia only offers `1` (Standard). A non-empty numeric `serviceCode` on a payload overrides it. |
| `default_fulfillment` | `dropoff` | `pickup` or `dropoff`, used when a `ShipmentPayload` has no `fulfillment`. See [Pickup vs drop-off](#pickup-vs-drop-off). |
| `webhook_timestamp_tolerance` | `900` | Seconds of clock skew allowed on a webhook's `timestamp` header. `0` disables the check. |
| `sandbox` | `false` | `true` uses `https://test-stable.spx.com.my`; `false` uses `https://spx.com.my` |
| `timeout` | `30` | HTTP timeout, in seconds |

### Log redaction

SPX sends `user_secret` in every request body and `check-sign` in every set of headers. The core redactor matches whole key names, so `secret` does not catch `user_secret`. This package therefore adds `user_secret` and `check-sign` to `courier.logging.redact` at boot, and both show as `[REDACTED]` in `courier_api_logs`.

## Available Methods

| Method | SPX endpoint | Notes |
|---|---|---|
| `createShipment(ShipmentPayload)` | `order/batch_create_order` | Sends a batch of one. If no reference is given, generates a ULID. SPX caps `order_id` at **31 characters**, so a longer reference throws `InvalidPayloadException`. |
| `getShipment(string $reference)` | `order/batch_search_order` (by `order_id`) | Returns the live status, unlike J&T. |
| `track(string $trackingNumber)` | `order/batch_search_order` (by `tracking_no`) | Events come oldest first. Throws `ShipmentNotFoundException` for an unknown waybill. |
| `getRates(RatePayload)` | `order/batch_check_order` | One `MYR` option. `estimatedDays` is SPX's `edt_max`. Throws `RateFetchException` for an unserviceable route. |
| `cancelShipment(string $waybill, ?string $reference)` | `order/batch_cancel_order` | Needs only the waybill. Throws `CancellationException` with SPX's reason. |
| `getLabel(string $waybill, ?string $reference)` | `order/batch_get_shipping_label` | Returns `format: 'url'`. **The link expires after 30 minutes or 10 opens**, so don't store it. |
| `getAvailability(AvailabilityPayload)` | — | Always returns an empty collection: SPX Malaysia has one service and no availability endpoint. Use `getRates()` to check whether a route is serviceable. |
| `getDeliveryModes()` | — | `[DeliveryMode::Scheduled]` |
| `getPickupTimeslots()` | `order/get_pickup_time` | Specific to this driver. Lists the pickup days and slots offered to your account. |

Batch failures: each SPX endpoint returns `ret_code: 0` even when the order inside it failed, and puts the reason in `data.fail_list`. The driver reads that list and throws the matching typed exception, with SPX's message and `ret_code` included, so you never get a silent success.

## Usage

```php
use Laraditz\Courier\Facades\Courier;
use Laraditz\Courier\DTOs\Shared\Address;
use Laraditz\Courier\DTOs\Shared\Location;
use Laraditz\Courier\DTOs\Shared\Parcel;
use Laraditz\Courier\DTOs\Payloads\RatePayload;
use Laraditz\Courier\DTOs\Payloads\ShipmentPayload;

$spx = Courier::driver('spxexpress');

$parcel = new Parcel(
    weight: 1.5,          // kg — MY: over 0.1 and up to 30
    length: 20.0,         // cm — MY: up to 125 per side
    width: 15.0,
    height: 10.0,
    declaredValue: 100.0, // required by SPX, 0 is allowed
    description: 'Goods',
    quantity: 1,
);

// Quote first. State and city are required here.
$rates = $spx->getRates(new RatePayload(
    origin: new Location('50000', 'Kuala Lumpur', 'Kuala Lumpur', 'MY'),
    destination: new Location('40000', 'Shah Alam', 'Selangor', 'MY'),
    parcel: $parcel,
));
$rates->items[0]->price; // 6.89

$result = $spx->createShipment(new ShipmentPayload(
    sender: new Address(
        name: 'Sender',
        phone: '+60123456789', // formatting is stripped: SPX wants digits only
        email: null,
        line1: 'No 1, Jalan Test',
        line2: 'Taman Maju',   // all street lines are joined into detail_address
        line3: null,
        city: 'Kuala Lumpur',
        state: 'Kuala Lumpur',
        postcode: '50000',
        country: 'MY',
    ),
    recipient: new Address(/* ... */),
    parcel: $parcel,
    serviceCode: '',             // '' uses config service_type (1, Standard)
    remarks: 'Leave at guardhouse', // sent as deliver_instruction
    reference: 'ORDER-001',      // optional, 31 characters max
    meta: [
        'cod_amount' => 45.00,   // optional COD, MY limit RM500, selected postcodes only
    ],
));

$result->waybillNumber;                    // 'MY06058790760A'
$result->reference;                        // 'ORDER-001'
$result->meta()['estimated_shipping_fee']; // 6.89

$tracking = $spx->track($result->waybillNumber);
$order    = $spx->getShipment('ORDER-001');

$label = $spx->getLabel($result->waybillNumber);
$label->content; // a short-lived URL

$spx->cancelShipment($result->waybillNumber);
```

SPX warns that an order can take a few seconds after creation before `track()` or `getShipment()` can find it. Until then the call throws `ShipmentNotFoundException`, so retry with backoff rather than treating it as a failure.

### Pickup vs drop-off

`ShipmentPayload::$fulfillment` maps to SPX's `collect_type`. Without it, `default_fulfillment` applies.

- **Drop-off** (`FulfillmentMode::Dropoff`, `collect_type` 2): the sender takes the parcel to an SPX drop-off point. No timeslot is needed.
- **Pickup** (`FulfillmentMode::Pickup`, `collect_type` 1): SPX requires a timeslot. The driver chooses one in this order:
  1. `meta['pickup_time']` + `meta['pickup_time_range_id']`, if both are given, sent as-is.
  2. Otherwise it calls `get_pickup_time` and takes the day matching `scheduledAt` (compared in Malaysian time), or the first day with any slot if `scheduledAt` is null. If `meta['pickup_time_range_id']` is set, it takes that slot on the day; otherwise the first slot.
  3. If no slot is found, it throws `InvalidPayloadException`. It never quietly downgrades to a drop-off, because then nobody comes to collect the parcel.

```php
$slots = $spx->getPickupTimeslots();
// [['date' => '2026-10-27', 'pickup_time' => 1793088000,
//   'slots' => [['pickup_time_range_id' => 1, 'pickup_time_range' => '10:00 - 19:00']]], ...]
```

> The default is drop-off because the sandbox sender account has no pickup coverage: `get_pickup_time` returns days with empty `slots`, and pickup orders fail with `postcode match product failure`. Pickup is therefore implemented to SPX's spec but has **not** been verified end-to-end. Confirm with SPX that your live account has pickup coverage before setting `default_fulfillment=pickup`.

## Webhooks

The driver implements `HandlesWebhooks` and `ExtractsWebhookReference`. SPX doesn't let you register the webhook URL yourself; they bind it to your app when they issue credentials. Give them:

```
POST {your-app}/courier/webhook/spxexpress
```

### Verification

SPX signs each push the same way as an outbound request: `check-sign` is the HMAC-SHA256, keyed with your app secret, of `{app_id}_{timestamp}_{random-num}_{raw body}`. `verifyWebhook()` recomputes it from the **raw** request body and the `timestamp` and `random-num` headers, and compares with `hash_equals()`. A push is rejected with HTTP 401 if:

- any of the three headers is missing,
- the signature doesn't match, or
- `timestamp` is more than `webhook_timestamp_tolerance` seconds from now.

> SPX documents the signing scheme, but not which headers a push carries. The driver assumes the push includes `timestamp` and `random-num`, the same as an outbound request. Check the first live push in `courier_webhook_logs`. If every push is `rejected`, look at the headers it actually sent.

### Response

SPX needs **HTTP 200 within 3 seconds**. If pushes keep failing or timing out, SPX **stops sending them**, and those status updates are lost. The core controller already returns an empty 200 once the driver has handled the push. Keep your `TrackingUpdated` listeners fast, or queue them.

The core webhook route is throttled to 60 requests a minute per IP by default. If you ship in volume, raise the limit for this driver:

```php
// config/courier.php → drivers.spxexpress
'webhook' => ['rate_limit' => 600],
```

### Events

A Tracking Web Hook push raises `Laraditz\Courier\SpxExpress\Events\TrackingUpdated`:

```php
use Laraditz\Courier\SpxExpress\Events\TrackingUpdated;

Event::listen(function (TrackingUpdated $event) {
    $event->trackingNo;   // 'MY06058790760A'
    $event->orderId;      // your reference
    $event->statusCode;   // '2001'
    $event->mappedStatus; // 'in_transit'
    $event->raw;          // full push, e.g. latest_shipping_fee, proof_of_delivery_list
});
```

SPX's other webhooks (order created, shipping fee, evidence proofs) have no `status_code` and don't raise `TrackingUpdated`. The core `WebhookReceived` event still fires for them, and they are logged.

## Status mapping

| SPX `status_code` | SPX status | Mapped |
|---|---|---|
| 1001 | Pending Pickup | `pending` |
| 2001 | In Transit | `in_transit` |
| 2006 | Delivering | `out_for_delivery` |
| 3001 | On Hold | `exception` |
| 4001 | Delivered | `delivered` |
| 5001 | Pickup Failed | `exception` |
| 5002 | Damaged | `exception` |
| 5003 | Lost | `exception` |
| 6001 | Returning | `returning` |
| 6002 | Return Failed | `exception` |
| 6003 | Returned | `returned` |
| 7001 | Cancelled | `cancelled` |

The raw `status_code` is kept in `meta()['status_code']` on tracking and shipment results, and in `TrackingUpdated::$statusCode`.

## Testing

```bash
composer test
```

## License

MIT
