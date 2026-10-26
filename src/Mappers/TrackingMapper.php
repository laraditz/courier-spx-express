<?php

namespace Laraditz\Courier\SpxExpress\Mappers;

use Carbon\Carbon;
use Laraditz\Courier\DTOs\Results\TrackingEvent;
use Laraditz\Courier\DTOs\Results\TrackingResult;

class TrackingMapper
{
    /**
     * Every status_code in SPX's "Order status code" appendix (docs/spx-api).
     *
     * 3001 On Hold and the 500-series are carrier-side problems with no matching
     * value in the shared vocabulary, so they map to 'exception' as J&T's do; the
     * raw code is kept in meta and on each event for anything finer-grained.
     */
    private static array $statusMap = [
        '1001' => 'pending',           // Pending Pickup
        '2001' => 'in_transit',        // In Transit — picked up or dropped off
        '2006' => 'out_for_delivery',  // Delivering
        '3001' => 'exception',         // On Hold
        '4001' => 'delivered',         // Delivered
        '5001' => 'exception',         // Pickup Failed
        '5002' => 'exception',         // Damaged
        '5003' => 'exception',         // Lost
        '6001' => 'returning',         // Returning
        '6002' => 'exception',         // Return Failed
        '6003' => 'returned',          // Returned
        '7001' => 'cancelled',         // Cancelled
    ];

    public static function mapStatus(string $statusCode): string
    {
        return self::$statusMap[$statusCode] ?? 'unknown';
    }

    /**
     * Maps one entry of batch_search_order's data.orders.
     *
     * SPX lists routes newest first; events are returned oldest first, matching the
     * other drivers, so the last event is the latest. The result's status comes from
     * the order's own status_code rather than the last route, since that is what
     * SPX reports as current.
     */
    public static function map(array $order, string $trackingNumber): TrackingResult
    {
        $routes = $order['routes'] ?? [];

        usort($routes, static fn (array $a, array $b): int => ($a['timestamp'] ?? 0) <=> ($b['timestamp'] ?? 0));

        $events = array_map(
            static fn (array $route) => new TrackingEvent(
                timestamp: Carbon::createFromTimestamp((int) ($route['timestamp'] ?? 0)),
                location: '',
                description: trim((string) ($route['message'] ?? $route['status'] ?? '')),
                status: self::mapStatus((string) ($route['status_code'] ?? '')),
            ),
            $routes
        );

        return new TrackingResult(
            waybillNumber: $order['tracking_no'] ?? $trackingNumber,
            status: self::mapStatus((string) ($order['status_code'] ?? '')),
            estimatedDelivery: self::estimatedDelivery($order),
            events: $events,
            meta: [
                'status_code'   => $order['status_code'] ?? null,
                'status'        => $order['status'] ?? null,
                'order_id'      => $order['order_id'] ?? null,
                'tracking_link' => $order['tracking_link'] ?? null,
            ],
        );
    }

    /**
     * SPX documents edd_max only as "Max of Estimated Delivery Date" (an integer),
     * and the sandbox returns 0. Anything that is not a plausible epoch timestamp is
     * treated as absent rather than guessed at.
     */
    public static function estimatedDelivery(array $order): ?Carbon
    {
        $eddMax = (int) ($order['edd_info']['edd_max'] ?? 0);

        return $eddMax > 1_000_000_000 ? Carbon::createFromTimestamp($eddMax) : null;
    }
}
