<?php

declare(strict_types=1);

namespace Ucp\Sdk\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Exception\AgentProfileException;
use Ucp\Sdk\Exception\ConfigurationException;
use Ucp\Sdk\Exception\IdempotencyConflictException;
use Ucp\Sdk\Exception\NegotiationException;
use Ucp\Sdk\Exception\OAuthException;
use Ucp\Sdk\Exception\ResourceNotFoundException;
use Ucp\Sdk\Exception\SignatureException;
use Ucp\Sdk\Exception\UcpException;
use Ucp\Sdk\Exception\UnsupportedCapabilityException;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Model\Common\UcpErrorDescriptor;

final class UcpErrorDescriptorTest extends TestCase
{
    /**
     * @return iterable<string, array{\Throwable, string, string, string, int, bool}>
     */
    public static function throwableProvider(): iterable
    {
        yield 'validation' => [new ValidationException('invalid'), 'validation', 'invalid_request', 'recoverable', 422, false];
        yield 'idempotency conflict' => [new IdempotencyConflictException('conflict'), 'idempotency_conflict', 'idempotency_conflict', 'unrecoverable', 409, false];
        yield 'signature' => [new SignatureException('bad signature'), 'signature', 'signature_invalid', 'unrecoverable', 401, false];
        yield 'oauth' => [new OAuthException('no token'), 'oauth', 'identity_required', 'requires_buyer_input', 400, false];
        yield 'unsupported capability' => [new UnsupportedCapabilityException('nope'), 'unsupported_capability', 'capability_unsupported', 'unrecoverable', 501, false];
        yield 'not found' => [new ResourceNotFoundException('gone'), 'not_found', 'not_found', 'unrecoverable', 404, false];
        yield 'configuration' => [new ConfigurationException('misconfigured'), 'configuration', 'server_misconfigured', 'unrecoverable', 500, false];
        yield 'plain ucp' => [new UcpException('something'), 'ucp', 'request_failed', 'unrecoverable', 500, false];
        yield 'not a ucp exception' => [new \RuntimeException('connection refused to db-01:3306'), 'internal', 'internal_error', 'unrecoverable', 500, true];
    }

    #[DataProvider('throwableProvider')]
    #[Test]
    public function itDescribesEveryThrowableTheSdkCanRaise(
        \Throwable $throwable,
        string $type,
        string $code,
        string $severity,
        int $httpStatus,
        bool $internal,
    ): void {
        $descriptor = UcpErrorDescriptor::fromThrowable($throwable);

        self::assertSame($type, $descriptor->type);
        self::assertSame($code, $descriptor->code);
        self::assertSame($severity, $descriptor->severity);
        self::assertSame($httpStatus, $descriptor->httpStatus);
        self::assertSame($internal, $descriptor->internal);
    }

    #[Test]
    public function itCarriesTheErrorCodeOfTheExceptionsThatDefineTheirOwn(): void
    {
        $negotiation = UcpErrorDescriptor::fromThrowable(NegotiationException::capabilitiesIncompatible());
        self::assertSame('negotiation', $negotiation->type);
        self::assertSame('capabilities_incompatible', $negotiation->code);
        self::assertSame(400, $negotiation->httpStatus);

        $profile = UcpErrorDescriptor::fromThrowable(AgentProfileException::unreachable('https://platform.example/.well-known/ucp'));
        self::assertSame('ucp', $profile->type);
        self::assertSame('agent_profile_unreachable', $profile->code);
        self::assertSame('recoverable', $profile->severity);
        self::assertSame(424, $profile->httpStatus);
        self::assertFalse($profile->internal);
    }

    #[Test]
    public function itDerivesCodeAndSeverityFromAFrameworkStatusWithoutClaimingTheFaultIsInternal(): void
    {
        $clientError = UcpErrorDescriptor::forHttpStatus(400);
        self::assertSame('invalid_request', $clientError->code);
        self::assertSame('recoverable', $clientError->severity);
        self::assertFalse($clientError->internal);

        $serverError = UcpErrorDescriptor::forHttpStatus(503);
        self::assertSame('request_failed', $serverError->code);
        self::assertSame('unrecoverable', $serverError->severity);
        self::assertFalse($serverError->internal);
    }

    #[Test]
    public function itRendersAMessageThatSatisfiesEveryRequiredErrorMessageField(): void
    {
        // types/message_error.json requires type, code, content AND severity. A body
        // carrying only type and content is what this class exists to stop.
        $message = UcpErrorDescriptor::fromThrowable(new ValidationException('invalid'))
            ->toMessage('$.payment is required', '$.payment');

        self::assertSame([
            'type' => 'error',
            'content' => '$.payment is required',
            'severity' => 'recoverable',
            'code' => 'invalid_request',
            'path' => '$.payment',
        ], $message->toArray());
    }
}
