<?php

namespace Laraditz\Courier\SpxExpress\Mappers;

use Laraditz\Courier\DTOs\Results\CancelResult;
use Laraditz\Courier\Exceptions\CancellationException;

class CancelMapper
{
    /**
     * Maps batch_cancel_order's data for a single waybill.
     *
     * The envelope's ret_code is 0 even when the one order we sent failed to cancel;
     * success is the waybill appearing in data.tracking_no_list.
     */
    public static function map(array $data, string $waybillNumber): CancelResult
    {
        if (in_array($waybillNumber, $data['tracking_no_list'] ?? [], true)) {
            return new CancelResult(
                success: true,
                message: 'Cancelled.',
                meta: ['tracking_no' => $waybillNumber],
            );
        }

        $failure = FailList::for($data, 'tracking_no', $waybillNumber);

        throw new CancellationException(
            "SPX Express could not cancel [{$waybillNumber}]: " . FailList::describe($failure)
        );
    }
}
