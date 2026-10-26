<?php

namespace Laraditz\Courier\SpxExpress\Tests;

use Carbon\Carbon;
use Laraditz\Courier\DTOs\Payloads\AvailabilityPayload;
use Laraditz\Courier\DTOs\Payloads\RatePayload;
use Laraditz\Courier\DTOs\Payloads\ShipmentPayload;
use Laraditz\Courier\DTOs\Shared\Address;
use Laraditz\Courier\DTOs\Shared\Location;
use Laraditz\Courier\DTOs\Shared\Parcel;
use Laraditz\Courier\Enums\DeliveryMode;
use Laraditz\Courier\Enums\FulfillmentMode;
use Laraditz\Courier\Exceptions\CancellationException;
use Laraditz\Courier\Exceptions\CourierException;
use Laraditz\Courier\Exceptions\InvalidPayloadException;
use Laraditz\Courier\Exceptions\LabelFetchException;
use Laraditz\Courier\Exceptions\RateFetchException;
use Laraditz\Courier\Exceptions\ShipmentCreationException;
use Laraditz\Courier\Exceptions\ShipmentNotFoundException;
use Laraditz\Courier\SpxExpress\Http\SpxExpressClient;
use Laraditz\Courier\SpxExpress\SpxExpressDriver;

class SpxExpressDriverTest extends TestCase
{
    private function sender(): Address
    {
        return new Address('Farhan', '+60 12-345 6789', null, 'No 1 Jalan Test', 'Taman Maju', null, 'Kuala Lumpur', 'Kuala Lumpur', '50000', 'MY');
    }

    private function recipient(): Address
    {
        return new Address('Receiver', '0123456789', null, 'No 2 Jalan Test', null, null, 'Shah Alam', 'Selangor', '40000', 'MY');
    }

    private function parcel(): Parcel
    {
        return new Parcel(1.5, 20.0, 15.0, 10.0, 100.0, 'Goods', 1);
    }

    private function payload(array $overrides = []): ShipmentPayload
    {
        return new ShipmentPayload(...$overrides + [
            'sender'      => $this->sender(),
            'recipient'   => $this->recipient(),
            'parcel'      => $this->parcel(),
            'serviceCode' => '',
            'reference'   => 'ORDER-001',
        ]);
    }

    /**
     * A driver whose client answers each path from $responses, recording every call
     * into $calls as [path, body].
     *
     * @param  array<string, array|\Throwable>  $responses
     */
    private function driver(array $responses, ?array &$calls = null, array $config = []): SpxExpressDriver
    {
        $calls = [];
        $client = $this->createMock(SpxExpressClient::class);
        $client->method('dispatch')->willReturnCallback(
            function (string $path, array $body) use ($responses, &$calls) {
                $calls[] = [$path, $body];
                $response = $responses[$path] ?? throw new \LogicException("Unexpected call to {$path}");

                if ($response instanceof \Throwable) {
                    throw $response;
                }

                return $response;
            }
        );

        return new SpxExpressDriver($config + config('courier.drivers.spxexpress'), $client);
    }

    public function test_create_shipment_returns_waybill_and_reference(): void
    {
        $driver = $this->driver(['order/batch_create_order' => $this->fixture('create-shipment-success')]);

        $result = $driver->createShipment($this->payload(['reference' => 'LRDZ-PROBE-001']));

        $this->assertSame('MY06058790760A', $result->waybillNumber);
        $this->assertSame('LRDZ-PROBE-001', $result->reference);
        $this->assertSame('pending', $result->status);
        $this->assertSame(6.89, $result->meta()['estimated_shipping_fee']);
    }

    public function test_create_shipment_sends_a_single_drop_off_order_by_default(): void
    {
        $driver = $this->driver(['order/batch_create_order' => $this->fixture('create-shipment-success')], $calls);

        $driver->createShipment($this->payload(['remarks' => 'Leave at guardhouse']));

        [$path, $body] = $calls[0];
        $order = $body['orders'][0];

        $this->assertSame('order/batch_create_order', $path);
        $this->assertCount(1, $body['orders']);
        $this->assertSame('ORDER-001', $order['order_id']);
        $this->assertSame(['service_type' => 1], $order['base_info']);
        $this->assertSame(['payment_role' => 1, 'cod_collection' => 0, 'collect_type' => 2], $order['fulfillment_info']);
        $this->assertSame('60123456789', $order['sender_info']['sender_phone']);
        $this->assertSame('No 1 Jalan Test, Taman Maju', $order['sender_info']['sender_detail_address']);
        $this->assertSame('50000', $order['sender_info']['sender_post_code']);
        $this->assertSame('0123456789', $order['deliver_info']['deliver_phone']);
        $this->assertSame('Leave at guardhouse', $order['deliver_info']['deliver_instruction']);
        $this->assertSame(100.0, $order['parcel_info']['parcel_value']);
        $this->assertSame('Goods', $order['parcel_info']['parcel_item_name']);
    }

    public function test_create_shipment_sends_cod_and_voucher_from_meta(): void
    {
        $driver = $this->driver(['order/batch_create_order' => $this->fixture('create-shipment-success')], $calls);

        $driver->createShipment($this->payload(['meta' => ['cod_amount' => 45.555, 'voucher_code' => 'V1']]));

        $fulfillment = $calls[0][1]['orders'][0]['fulfillment_info'];

        $this->assertSame(1, $fulfillment['cod_collection']);
        $this->assertSame(45.56, $fulfillment['cod_amount']);
        $this->assertSame('V1', $fulfillment['voucher_code']);
    }

    public function test_create_shipment_generates_a_reference_within_spx_limit(): void
    {
        $driver = $this->driver(['order/batch_create_order' => [
            'ret_code' => 0,
            'data' => ['orders' => [['tracking_no' => 'MY1']], 'fail_list' => []],
        ]], $calls);

        $result = $driver->createShipment($this->payload(['reference' => null]));

        $sent = $calls[0][1]['orders'][0]['order_id'];

        $this->assertLessThanOrEqual(31, strlen($sent));
        $this->assertSame($sent, $result->reference);
    }

    public function test_create_shipment_rejects_a_reference_over_31_characters(): void
    {
        $this->expectException(InvalidPayloadException::class);

        $this->driver([])->createShipment($this->payload(['reference' => str_repeat('A', 32)]));
    }

    public function test_create_shipment_throws_with_spx_reason_when_order_is_in_fail_list(): void
    {
        $driver = $this->driver(['order/batch_create_order' => $this->fixture('create-shipment-failed')]);

        $this->expectException(ShipmentCreationException::class);
        $this->expectExceptionMessage('order id has been used already');

        $driver->createShipment($this->payload());
    }

    public function test_create_shipment_wraps_envelope_errors(): void
    {
        $driver = $this->driver(['order/batch_create_order' => new CourierException('SPX Express business error [11001]: bad')]);

        $this->expectException(ShipmentCreationException::class);
        $this->expectExceptionMessage('[11001]');

        $driver->createShipment($this->payload());
    }

    public function test_pickup_books_the_first_offered_slot(): void
    {
        $driver = $this->driver([
            'order/get_pickup_time'    => $this->fixture('pickup-timeslots'),
            'order/batch_create_order' => $this->fixture('create-shipment-success'),
        ], $calls);

        $driver->createShipment($this->payload(['fulfillment' => FulfillmentMode::Pickup]));

        $fulfillment = $calls[1][1]['orders'][0]['fulfillment_info'];

        $this->assertSame(1, $fulfillment['collect_type']);
        $this->assertSame(1793088000, $fulfillment['pickup_time']);
        $this->assertSame(1, $fulfillment['pickup_time_range_id']);
        $this->assertSame('10:00 - 19:00', $fulfillment['pickup_time_range']);
    }

    public function test_pickup_matches_scheduled_at_by_malaysian_date_and_slot_id(): void
    {
        $driver = $this->driver([
            'order/get_pickup_time'    => $this->fixture('pickup-timeslots'),
            'order/batch_create_order' => $this->fixture('create-shipment-success'),
        ], $calls);

        // 2026-10-27 18:00 UTC is already the 28th in Malaysia.
        $driver->createShipment($this->payload([
            'fulfillment' => FulfillmentMode::Pickup,
            'scheduledAt' => Carbon::parse('2026-10-27 18:00:00', 'UTC'),
            'meta'        => ['pickup_time_range_id' => 2],
        ]));

        $fulfillment = $calls[1][1]['orders'][0]['fulfillment_info'];

        $this->assertSame(1793174400, $fulfillment['pickup_time']);
        $this->assertSame(2, $fulfillment['pickup_time_range_id']);
    }

    public function test_pickup_uses_an_explicit_slot_without_a_lookup(): void
    {
        $driver = $this->driver(['order/batch_create_order' => $this->fixture('create-shipment-success')], $calls);

        $driver->createShipment($this->payload([
            'fulfillment' => FulfillmentMode::Pickup,
            'meta'        => ['pickup_time' => 1793088000, 'pickup_time_range_id' => 1],
        ]));

        $this->assertCount(1, $calls);
        $this->assertSame(1793088000, $calls[0][1]['orders'][0]['fulfillment_info']['pickup_time']);
    }

    public function test_pickup_without_an_offered_slot_fails_rather_than_booking_a_drop_off(): void
    {
        $driver = $this->driver(['order/get_pickup_time' => $this->fixture('pickup-timeslots')]);

        $this->expectException(InvalidPayloadException::class);
        $this->expectExceptionMessage('on 2026-10-26');

        $driver->createShipment($this->payload([
            'fulfillment' => FulfillmentMode::Pickup,
            'scheduledAt' => Carbon::parse('2026-10-26 10:00:00', 'Asia/Kuala_Lumpur'),
        ]));
    }

    public function test_default_fulfillment_config_is_honoured(): void
    {
        $driver = $this->driver([
            'order/get_pickup_time'    => $this->fixture('pickup-timeslots'),
            'order/batch_create_order' => $this->fixture('create-shipment-success'),
        ], $calls, ['default_fulfillment' => 'pickup']);

        $driver->createShipment($this->payload());

        $this->assertSame(1, $calls[1][1]['orders'][0]['fulfillment_info']['collect_type']);
    }

    public function test_track_maps_order(): void
    {
        $driver = $this->driver(['order/batch_search_order' => $this->fixture('track-success')], $calls);

        $result = $driver->track('MY06058790760A');

        $this->assertSame(['tracking_no_list' => ['MY06058790760A']], $calls[0][1]);
        $this->assertSame('cancelled', $result->status);
        $this->assertCount(2, $result->events);
    }

    public function test_track_throws_not_found_for_fail_list(): void
    {
        $driver = $this->driver(['order/batch_search_order' => $this->fixture('track-not-found')]);

        $this->expectException(ShipmentNotFoundException::class);

        $driver->track('NOPE123');
    }

    public function test_get_shipment_searches_by_order_id_and_reports_live_status(): void
    {
        $driver = $this->driver(['order/batch_search_order' => $this->fixture('track-success')], $calls);

        $result = $driver->getShipment('LRDZ-PROBE-001');

        $this->assertSame(['order_id_list' => ['LRDZ-PROBE-001']], $calls[0][1]);
        $this->assertSame('MY06058790760A', $result->waybillNumber);
        $this->assertSame('LRDZ-PROBE-001', $result->reference);
        $this->assertSame('cancelled', $result->status);
    }

    public function test_get_rates_returns_one_myr_option(): void
    {
        $driver = $this->driver(['order/batch_check_order' => $this->fixture('rate-success')], $calls);

        $rates = $driver->getRates(new RatePayload(
            new Location('50000', 'Kuala Lumpur', 'Kuala Lumpur', 'MY'),
            new Location('40000', 'Shah Alam', 'Selangor', 'MY'),
            $this->parcel(),
        ));

        $order = $calls[0][1]['orders'][0];

        $this->assertSame('Selangor', $order['deliver_info']['deliver_state']);
        $this->assertSame(2, $order['fulfillment_info']['collect_type']);
        $this->assertSame(100.0, $order['parcel_info']['parcel_value']);
        $this->assertCount(1, $rates->items);
        $this->assertSame(6.89, $rates->items[0]->price);
        $this->assertSame('MYR', $rates->items[0]->currency);
        $this->assertSame('1', $rates->items[0]->serviceCode);
        $this->assertSame(3, $rates->items[0]->estimatedDays);
    }

    public function test_get_rates_wraps_unserviceable_area(): void
    {
        $driver = $this->driver(['order/batch_check_order' => new CourierException('SPX Express business error [13051]: postcode match product failure')]);

        $this->expectException(RateFetchException::class);

        $driver->getRates(new RatePayload(
            new Location('50000', '', '', 'MY'),
            new Location('40000', '', '', 'MY'),
            $this->parcel(),
        ));
    }

    public function test_cancel_succeeds_when_waybill_is_listed(): void
    {
        $driver = $this->driver(['order/batch_cancel_order' => $this->fixture('cancel-response')], $calls);

        $result = $driver->cancelShipment('MY06058790760A');

        $this->assertSame(['tracking_no_list' => ['MY06058790760A']], $calls[0][1]);
        $this->assertTrue($result->success);
    }

    public function test_cancel_throws_with_spx_reason_when_waybill_failed(): void
    {
        $driver = $this->driver(['order/batch_cancel_order' => $this->fixture('cancel-response')]);

        $this->expectException(CancellationException::class);
        $this->expectExceptionMessage('MismatchedSpxTn (ret_code 13001)');

        $driver->cancelShipment('NOPE123');
    }

    public function test_get_label_returns_url(): void
    {
        $driver = $this->driver(['order/batch_get_shipping_label' => $this->fixture('label-success')]);

        $label = $driver->getLabel('MY06058790760A');

        $this->assertSame('url', $label->format);
        $this->assertStringStartsWith('https://test-stable.spx.com.my/', $label->content);
    }

    public function test_get_label_throws_on_fail_list(): void
    {
        $driver = $this->driver(['order/batch_get_shipping_label' => [
            'ret_code' => 0,
            'data' => ['awb_link' => '', 'fail_list' => [['ret_code' => 13200, 'message' => 'Get Shipping Label Failed. ', 'tracking_no' => 'MY1']]],
        ]]);

        $this->expectException(LabelFetchException::class);
        $this->expectExceptionMessage('Get Shipping Label Failed. (ret_code 13200)');

        $driver->getLabel('MY1');
    }

    public function test_availability_is_empty_and_mode_is_scheduled(): void
    {
        $driver = $this->driver([]);

        $this->assertSame([], $driver->getAvailability(new AvailabilityPayload(
            new Location('50000', 'Kuala Lumpur', 'Kuala Lumpur', 'MY'),
            new Location('40000', 'Shah Alam', 'Selangor', 'MY'),
        ))->items);
        $this->assertSame([DeliveryMode::Scheduled], $driver->getDeliveryModes());
    }
}
