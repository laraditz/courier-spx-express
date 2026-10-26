<?php

namespace Laraditz\Courier\SpxExpress\Mappers;

use Laraditz\Courier\DTOs\Results\ShipmentResult;

class ShipmentMapper
{
    /**
     * Maps one entry of batch_create_order's data.orders.
     */
    public static function map(array $order, string $reference): ShipmentResult
    {
        return new ShipmentResult(
            waybillNumber: $order['tracking_no'],
            status: 'pending',
            estimatedDelivery: null,
            reference: $order['order_id'] ?? $reference,
            meta: [
                'tracking_link'          => $order['tracking_link'] ?? null,
                'estimated_shipping_fee' => $order['estimated_shipping_fee'] ?? null,
                'basic_shipping_fee'     => $order['basic_shipping_fee'] ?? null,
                'cod_service_fee'        => $order['cod_service_fee'] ?? null,
                'insurance_service_fee'  => $order['insurance_service_fee'] ?? null,
                'vat_fee'                => $order['vat_fee'] ?? null,
                'r_first_sort_code'      => $order['r_first_sort_code'] ?? null,
                'r_third_sort_code'      => $order['r_third_sort_code'] ?? null,
            ],
        );
    }

    /**
     * Maps one entry of batch_search_order's data.orders, looked up by order_id.
     *
     * Unlike J&T's inquiry, SPX's search carries the live status, so this reports
     * it rather than 'unknown'.
     */
    public static function mapFromSearch(array $order, string $reference): ShipmentResult
    {
        return new ShipmentResult(
            waybillNumber: $order['tracking_no'],
            status: TrackingMapper::mapStatus((string) ($order['status_code'] ?? '')),
            estimatedDelivery: TrackingMapper::estimatedDelivery($order),
            reference: $order['order_id'] ?? $reference,
            meta: [
                'status_code'      => $order['status_code'] ?? null,
                'status'           => $order['status'] ?? null,
                'tracking_link'    => $order['tracking_link'] ?? null,
                'collect_type'     => $order['fulfillment_info']['collect_type'] ?? null,
                'parcel_info'      => $order['parcel_info'] ?? null,
                'sort_code_info'   => $order['sort_code_info'] ?? null,
            ],
        );
    }
}
