<?php

namespace Laraditz\Courier\SpxExpress\Mappers;

use Laraditz\Courier\DTOs\Results\LabelResult;
use Laraditz\Courier\Exceptions\LabelFetchException;

class LabelMapper
{
    /**
     * Maps batch_get_shipping_label's data for a single waybill.
     *
     * SPX returns only a link, and per their docs it is valid for half an hour and
     * opens at most 10 times — fetch or hand it to the user straight away rather
     * than storing it.
     */
    public static function map(array $data, string $waybillNumber): LabelResult
    {
        $failure = FailList::for($data, 'tracking_no', $waybillNumber);
        $link    = (string) ($data['awb_link'] ?? '');

        if ($failure !== null || $link === '') {
            throw new LabelFetchException(
                "SPX Express could not produce a label for [{$waybillNumber}]: " . FailList::describe($failure)
            );
        }

        return new LabelResult(
            waybillNumber: $waybillNumber,
            format: 'url',
            content: $link,
            meta: ['expires_in_minutes' => 30, 'max_opens' => 10],
        );
    }
}
