<?php

namespace Laraditz\Courier\SpxExpress\Tests\Http;

use Laraditz\Courier\SpxExpress\Http\SpxExpressSigner;
use PHPUnit\Framework\TestCase;

class SpxExpressSignerTest extends TestCase
{
    public function test_matches_spx_published_test_vector(): void
    {
        // From SPX's "API authentication guide" — "Test your generate check-sign method".
        $signer = new SpxExpressSigner('100000', 'H25HY53GO4BA2GQ');

        $this->assertSame(
            '97e21b23940e4ddc96fb4d2474f02425353d2b0e23aee384f3f94c3d0b9ba17d',
            $signer->sign(
                '{"user_id":239404781503925,"user_secret":"85f0d570-a265-4d9d-857e-b30aa57c4fbe","service_type":1}',
                1677918414,
                926611981,
            )
        );
    }

    public function test_string_and_int_header_values_sign_identically(): void
    {
        $signer = new SpxExpressSigner('100000', 'secret');

        $this->assertSame(
            $signer->sign('{}', 1677918414, 926611981),
            $signer->sign('{}', '1677918414', '926611981'),
        );
    }
}
