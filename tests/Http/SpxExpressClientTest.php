<?php

namespace Laraditz\Courier\SpxExpress\Tests\Http;

use Illuminate\Support\Facades\Http;
use Laraditz\Courier\Exceptions\CourierException;
use Laraditz\Courier\Models\CourierApiLog;
use Laraditz\Courier\SpxExpress\Http\SpxExpressClient;
use Laraditz\Courier\SpxExpress\Tests\TestCase;

class SpxExpressClientTest extends TestCase
{
    private function config(): array
    {
        return config('courier.drivers.spxexpress');
    }

    public function test_dispatch_returns_decoded_envelope_on_success(): void
    {
        Http::fake(['*/open/api/v1/order/batch_create_order' => Http::response($this->fixture('create-shipment-success'))]);

        $result = (new SpxExpressClient($this->config()))->dispatch('order/batch_create_order', ['orders' => []]);

        $this->assertSame('MY06058790760A', $result['data']['orders'][0]['tracking_no']);
    }

    public function test_dispatch_targets_sandbox_or_live_host(): void
    {
        Http::fake(['*' => Http::response(['ret_code' => 0, 'data' => []])]);

        (new SpxExpressClient($this->config()))->dispatch('order/get_pickup_time', []);
        (new SpxExpressClient(['sandbox' => false] + $this->config()))->dispatch('order/get_pickup_time', []);

        $urls = Http::recorded()->map(fn ($pair) => $pair[0]->url())->all();

        $this->assertSame([
            'https://test-stable.spx.com.my/open/api/v1/order/get_pickup_time',
            'https://spx.com.my/open/api/v1/order/get_pickup_time',
        ], $urls);
    }

    public function test_dispatch_injects_user_credentials_with_integer_user_id(): void
    {
        Http::fake(['*' => Http::response(['ret_code' => 0, 'data' => []])]);

        (new SpxExpressClient($this->config()))->dispatch('order/get_pickup_time', ['service_type' => 1]);

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            return $body['user_id'] === 239404781503925
                && $body['user_secret'] === '85f0d570-a265-4d9d-857e-b30aa57c4fbe'
                && $body['service_type'] === 1;
        });
    }

    /**
     * The guard on the encoding assumption in SpxExpressClient::dispatch(): the
     * signature must be over the exact bytes Laravel sends, not over our own
     * re-encoding of the array.
     */
    public function test_check_sign_is_computed_over_the_exact_body_sent(): void
    {
        Http::fake(['*' => Http::response(['ret_code' => 0, 'data' => []])]);

        (new SpxExpressClient($this->config()))->dispatch('order/batch_create_order', [
            'orders' => [[
                'order_id' => 'REF/001',
                'deliver_info' => ['deliver_detail_address' => 'Jalan Ampang, Kuala Lumpur — Unit 5/3'],
                'parcel_info' => ['parcel_weight' => 1.5, 'parcel_value' => 99.9],
            ]],
        ]);

        Http::assertSent(function ($request) {
            $expected = hash_hmac(
                'sha256',
                '100000_' . $request->header('timestamp')[0] . '_' . $request->header('random-num')[0] . '_' . $request->body(),
                'H25HY53GO4BA2GQ',
            );

            return $request->header('app-id')[0] === '100000'
                && $request->header('check-sign')[0] === $expected
                && ctype_digit($request->header('timestamp')[0])
                && $request->hasHeader('Content-Type', 'application/json');
        });
    }

    public function test_dispatch_throws_on_non_zero_ret_code(): void
    {
        Http::fake(['*' => Http::response(['ret_code' => 11001, 'message' => 'parcel value can not be null or less than 0', 'data' => null])]);

        $this->expectException(CourierException::class);
        $this->expectExceptionMessage('[11001]: parcel value can not be null');

        (new SpxExpressClient($this->config()))->dispatch('order/batch_check_order', []);
    }

    public function test_dispatch_throws_on_http_failure(): void
    {
        Http::fake(['*' => Http::response('404: Page Not Found', 404)]);

        $this->expectException(CourierException::class);
        $this->expectExceptionMessage('SPX Express API error (404)');

        (new SpxExpressClient($this->config()))->dispatch('order/nope', []);
    }

    public function test_dispatch_logs_with_secrets_redacted(): void
    {
        Http::fake(['*' => Http::response(['ret_code' => 0, 'data' => []])]);

        (new SpxExpressClient($this->config()))->dispatch('order/batch_search_order', ['tracking_no_list' => ['MY1']], waybillNumber: 'MY1');

        $log = CourierApiLog::first();

        $this->assertSame('spxexpress', $log->driver);
        $this->assertSame('order/batch_search_order', $log->action);
        $this->assertSame('MY1', $log->waybill_number);
        $this->assertSame('[REDACTED]', $log->request_body['user_secret']);
        $this->assertStringNotContainsString('85f0d570', json_encode($log->request_body));
        $this->assertSame('[REDACTED]', $log->request_headers['check-sign']);
    }
}
