<?php

namespace Laraditz\Courier\SpxExpress\Mappers;

use Laraditz\Courier\DTOs\Results\RateCollection;
use Laraditz\Courier\DTOs\Results\RateOption;

class RateMapper
{
    /**
     * Maps one entry of batch_check_order's data.orders.
     *
     * SPX quotes a single service per request, so the collection holds one option.
     * edt_max is in days (the sandbox returns 1–3), unlike edd_max on a tracked
     * order, which is a date.
     */
    public static function map(array $order, int $serviceType): RateCollection
    {
        $edtMax = $order['edt_max'] ?? null;

        return new RateCollection([
            new RateOption(
                serviceCode: (string) $serviceType,
                serviceName: $serviceType === 1 ? 'Standard Service' : "Service {$serviceType}",
                price: (float) ($order['estimated_shipping_fee'] ?? 0),
                currency: 'MYR',
                estimatedDays: $edtMax !== null ? (int) $edtMax : null,
                meta: [
                    'basic_shipping_fee'    => $order['basic_shipping_fee'] ?? null,
                    'cod_service_fee'       => $order['cod_service_fee'] ?? null,
                    'insurance_service_fee' => $order['insurance_service_fee'] ?? null,
                    'vat_fee'               => $order['vat_fee'] ?? null,
                    'voucher_shipping_fee'  => $order['voucher_shipping_fee'] ?? null,
                    'edt_min'               => $order['edt_min'] ?? null,
                ],
            ),
        ]);
    }
}
