<?php

declare(strict_types=1);

namespace Ucp\Sdk\Tests\Unit\Capability;

use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Capability\Ap2MandateCapability;

final class Ap2MandateCapabilityTest extends TestCase
{
    public function testItAdvertisesTheAp2MandateCapabilityExtendingCheckout(): void
    {
        $descriptor = (new Ap2MandateCapability())->describe();

        self::assertSame('dev.ucp.shopping.ap2_mandate', $descriptor->name);
        self::assertSame(['dev.ucp.shopping.checkout'], $descriptor->extends);
        self::assertSame(['vp_formats_supported' => ['dc+sd-jwt' => []]], $descriptor->config);
    }

    public function testItAdvertisesTheConfiguredPresentationFormats(): void
    {
        $descriptor = (new Ap2MandateCapability(['jwt_vc_json' => ['alg' => ['ES256']]], '2026-04-08'))->describe();

        self::assertSame(['vp_formats_supported' => ['jwt_vc_json' => ['alg' => ['ES256']]]], $descriptor->config);
        self::assertSame('2026-04-08', $descriptor->version);
    }
}
