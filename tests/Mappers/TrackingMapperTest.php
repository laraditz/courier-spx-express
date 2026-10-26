<?php

namespace Laraditz\Courier\SpxExpress\Tests\Mappers;

use Laraditz\Courier\SpxExpress\Mappers\TrackingMapper;
use Laraditz\Courier\SpxExpress\Tests\TestCase;

class TrackingMapperTest extends TestCase
{
    public function test_maps_every_documented_status_code(): void
    {
        $expected = [
            '1001' => 'pending',
            '2001' => 'in_transit',
            '2006' => 'out_for_delivery',
            '3001' => 'exception',
            '4001' => 'delivered',
            '5001' => 'exception',
            '5002' => 'exception',
            '5003' => 'exception',
            '6001' => 'returning',
            '6002' => 'exception',
            '6003' => 'returned',
            '7001' => 'cancelled',
        ];

        foreach ($expected as $code => $status) {
            $this->assertSame($status, TrackingMapper::mapStatus($code), "status_code {$code}");
        }

        $this->assertSame('unknown', TrackingMapper::mapStatus('9999'));
    }

    public function test_events_are_returned_oldest_first(): void
    {
        $order = $this->fixture('track-success')['data']['orders'][0];

        $result = TrackingMapper::map($order, 'MY06058790760A');

        $this->assertSame('MY06058790760A', $result->waybillNumber);
        $this->assertSame('cancelled', $result->status);
        $this->assertCount(2, $result->events);
        $this->assertSame('pending', $result->events[0]->status);
        $this->assertSame('Sender is preparing to ship your parcel', $result->events[0]->description);
        $this->assertSame('cancelled', $result->events[1]->status);
        $this->assertSame(1792970811, $result->events[1]->timestamp->getTimestamp());
        $this->assertSame('7001', $result->meta()['status_code']);
    }

    public function test_zero_edd_is_treated_as_unknown(): void
    {
        $order = $this->fixture('track-success')['data']['orders'][0];

        $this->assertNull(TrackingMapper::map($order, 'X')->estimatedDelivery);

        $order['edd_info']['edd_max'] = 1793174400;

        $this->assertSame(1793174400, TrackingMapper::map($order, 'X')->estimatedDelivery->getTimestamp());
    }
}
