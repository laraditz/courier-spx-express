<?php

namespace Laraditz\Courier\SpxExpress\Tests;

use Illuminate\Support\Facades\Event;
use Laraditz\Courier\Models\CourierWebhookLog;
use Laraditz\Courier\SpxExpress\Events\TrackingUpdated;

class WebhookTest extends TestCase
{
    private const BODY = '{"status":"In Transit","status_code":"2001","message":"xxx","tracking_no":"MY06058790760A","tracking_link":"https://uat.spx.com.my/track?MY06058790760A","order_id":"ORDER-001","timestamp":1792970787,"latest_shipping_fee":6.89,"id":"d4dcfea1-5286-4921-8fd3-7ded133f8579"}';

    /**
     * Signs the way SPX does, over the raw body and the header values.
     */
    private function headers(string $body, ?int $timestamp = null, string $secret = 'H25HY53GO4BA2GQ'): array
    {
        $timestamp ??= time();
        $randomNum = '-4412873123';

        return [
            'CONTENT_TYPE'     => 'application/json',
            'HTTP_TIMESTAMP'   => (string) $timestamp,
            'HTTP_RANDOM_NUM'  => $randomNum,
            'HTTP_CHECK_SIGN'  => hash_hmac('sha256', "100000_{$timestamp}_{$randomNum}_{$body}", $secret),
        ];
    }

    private function push(string $body, array $headers)
    {
        return $this->call('POST', '/courier/webhook/spxexpress', [], [], [], $headers, $body);
    }

    public function test_signed_push_is_accepted_and_raises_tracking_updated(): void
    {
        Event::fake([TrackingUpdated::class]);

        $this->push(self::BODY, $this->headers(self::BODY))->assertOk();

        Event::assertDispatched(TrackingUpdated::class, fn (TrackingUpdated $e) => $e->trackingNo === 'MY06058790760A'
            && $e->orderId === 'ORDER-001'
            && $e->statusCode === '2001'
            && $e->mappedStatus === 'in_transit'
            && $e->raw['latest_shipping_fee'] === 6.89);

        $log = CourierWebhookLog::first();
        $this->assertSame('processed', $log->status);
        $this->assertSame('ORDER-001', $log->reference);
        $this->assertSame('MY06058790760A', $log->waybill_number);
    }

    public function test_signature_over_a_different_secret_is_rejected(): void
    {
        Event::fake([TrackingUpdated::class]);

        $this->push(self::BODY, $this->headers(self::BODY, secret: 'wrong'))->assertStatus(401);

        Event::assertNotDispatched(TrackingUpdated::class);
        $this->assertSame('rejected', CourierWebhookLog::first()->status);
    }

    public function test_tampered_body_is_rejected(): void
    {
        $headers = $this->headers(self::BODY);

        $this->push(str_replace('2001', '4001', self::BODY), $headers)->assertStatus(401);
    }

    public function test_reencoded_body_is_still_checked_against_the_raw_bytes(): void
    {
        // Same JSON, different spacing: valid only because the raw body is what is signed.
        $body = str_replace(',', ', ', self::BODY);

        $this->push($body, $this->headers($body))->assertOk();
    }

    public function test_stale_timestamp_is_rejected(): void
    {
        $this->push(self::BODY, $this->headers(self::BODY, time() - 901))->assertStatus(401);
    }

    public function test_stale_timestamp_is_accepted_when_tolerance_disabled(): void
    {
        config(['courier.drivers.spxexpress.webhook_timestamp_tolerance' => 0]);

        $this->push(self::BODY, $this->headers(self::BODY, time() - 86400))->assertOk();
    }

    public function test_missing_signature_headers_are_rejected(): void
    {
        $this->push(self::BODY, ['CONTENT_TYPE' => 'application/json'])->assertStatus(401);
    }

    public function test_non_tracking_push_is_accepted_without_tracking_event(): void
    {
        Event::fake([TrackingUpdated::class]);

        $body = '{"tracking_no":"MY06058790760A","order_id":"ORDER-001","proof_of_delivery_list":["https://example.test/pod.jpg"]}';

        $this->push($body, $this->headers($body))->assertOk();

        Event::assertNotDispatched(TrackingUpdated::class);
    }
}
