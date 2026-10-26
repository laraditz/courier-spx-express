<?php

namespace Laraditz\Courier\SpxExpress\Mappers;

/**
 * Reads SPX's per-order data.fail_list, which every batch endpoint returns
 * alongside a top-level ret_code of 0.
 */
class FailList
{
    /**
     * The fail_list entry whose $key matches $value, or the only entry when there is
     * exactly one — this driver always sends a batch of one, and not every endpoint
     * echoes the identifier back on a failure.
     */
    public static function for(array $data, string $key, string $value): ?array
    {
        $failures = array_values(array_filter($data['fail_list'] ?? [], 'is_array'));

        foreach ($failures as $failure) {
            if ((string) ($failure[$key] ?? '') === $value) {
                return $failure;
            }
        }

        return count($failures) === 1 ? $failures[0] : null;
    }

    public static function describe(?array $failure): string
    {
        if ($failure === null) {
            return 'no reason given';
        }

        return trim((string) ($failure['message'] ?? 'no reason given')) .
            ' (ret_code ' . ($failure['ret_code'] ?? 'unknown') . ')';
    }
}
