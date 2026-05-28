<?php

declare(strict_types=1);

namespace Ucp\Sdk\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Internal\Security\UnsupportedMerchantAuthorizationService;
use Ucp\Sdk\Model\Http\HttpRequest;
use Ucp\Sdk\Model\RequestContext;

final class UnsupportedMerchantAuthorizationServiceTest extends TestCase
{
    #[Test]
    public function itReportsMissingMerchantAuthorizationHeaders(): void
    {
        $service = new UnsupportedMerchantAuthorizationService();

        $result = $service->verify(new HttpRequest('POST', 'https://merchant.example/ucp/v1/checkouts'), [], new RequestContext('merchant.example'));

        self::assertFalse($result->verified);
        self::assertSame('Merchant authorization header is missing.', $result->failureReason);
    }

    #[Test]
    public function itReportsUnsupportedVerificationWhenTheHeaderIsPresent(): void
    {
        $service = new UnsupportedMerchantAuthorizationService();

        $result = $service->verify(new HttpRequest('POST', 'https://merchant.example/ucp/v1/checkouts', [
            'X-Ucp-Merchant-Authorization' => 'token',
        ]), [], new RequestContext('merchant.example'));

        self::assertFalse($result->verified);
        self::assertSame(
            'Merchant authorization verification is not implemented by the shared SDK. Provide a platform-specific service if this flow is required.',
            $result->failureReason,
        );
    }
}
