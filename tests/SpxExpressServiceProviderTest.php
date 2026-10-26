<?php

namespace Laraditz\Courier\SpxExpress\Tests;

use Laraditz\Courier\SpxExpress\SpxExpressDriver;

class SpxExpressServiceProviderTest extends TestCase
{
    public function test_spxexpress_driver_resolves_via_courier_manager(): void
    {
        $this->assertInstanceOf(SpxExpressDriver::class, app('courier')->driver('spxexpress'));
    }

    public function test_spx_secrets_are_added_to_the_redact_list(): void
    {
        $redact = config('courier.logging.redact');

        $this->assertContains('user_secret', $redact);
        $this->assertContains('check-sign', $redact);
        // The core defaults are kept, not replaced.
        $this->assertContains('authorization', $redact);
    }
}
