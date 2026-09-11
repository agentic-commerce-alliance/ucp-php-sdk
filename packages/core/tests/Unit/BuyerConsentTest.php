<?php

declare(strict_types=1);

namespace Ucp\Sdk\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Model\Checkout\BuyerConsent;
use Ucp\Sdk\Model\Checkout\ConsentPurpose;

/**
 * Consent as a map of purposes rather than one boolean.
 *
 * The question a business actually has to answer is "may I email this person", and the shape
 * that replaced a single flag is what makes that answerable -- along with who asserted it,
 * which is the part that decides whether the business can stand behind the answer later.
 */
final class BuyerConsentTest extends TestCase
{
    #[Test]
    public function itReadsAPurposeMapKeyedByReverseDnsIdentifier(): void
    {
        $consent = BuyerConsent::fromArray([
            BuyerConsent::PURPOSE_MARKETING => [
                'granted' => true,
                'source' => 'business',
                'description' => 'Promotional email',
            ],
            BuyerConsent::PURPOSE_ANALYTICS => [
                'granted' => false,
                'source' => 'platform',
                'description' => 'Performance tracking',
            ],
        ]);

        self::assertTrue($consent->granted(BuyerConsent::PURPOSE_MARKETING));
        self::assertFalse($consent->granted(BuyerConsent::PURPOSE_ANALYTICS));
        self::assertSame('business', $consent->purposes[BuyerConsent::PURPOSE_MARKETING]->source);
    }

    #[Test]
    public function anUnknownPurposeIsNotGranted(): void
    {
        $consent = BuyerConsent::fromArray([]);

        self::assertFalse($consent->granted('dev.ucp.consent.marketing'));
        self::assertFalse($consent->granted('com.example.something_nobody_asked_about'));
    }

    #[Test]
    public function aSegmentOverridesItsParentPurpose(): void
    {
        // "Marketing, but not SMS" is the whole reason segments exist -- the parent grant must
        // not answer for a segment that revoked it.
        $consent = BuyerConsent::fromArray([
            BuyerConsent::PURPOSE_MARKETING => [
                'granted' => true,
                'source' => 'business',
                'description' => 'Marketing communications',
                'segments' => [
                    'sms' => [
                        'granted' => false,
                        'source' => 'business',
                        'description' => 'Marketing by SMS',
                    ],
                ],
            ],
        ]);

        self::assertTrue($consent->granted(BuyerConsent::PURPOSE_MARKETING));
        self::assertTrue($consent->granted(BuyerConsent::PURPOSE_MARKETING, 'email'), 'an unsegmented channel falls back to the parent');
        self::assertFalse($consent->granted(BuyerConsent::PURPOSE_MARKETING, 'sms'));
    }

    #[Test]
    public function itMigratesTheLegacyBooleanFlags(): void
    {
        // 2026-04-08 shipped four booleans on buyer.consent. sale_of_data became
        // sale_or_sharing, so a straight key copy would drop it.
        $consent = BuyerConsent::fromArray([
            'marketing' => true,
            'analytics' => false,
            'preferences' => true,
            'sale_of_data' => false,
        ]);

        self::assertTrue($consent->granted(BuyerConsent::PURPOSE_MARKETING));
        self::assertFalse($consent->granted(BuyerConsent::PURPOSE_ANALYTICS));
        self::assertTrue($consent->granted(BuyerConsent::PURPOSE_PREFERENCES));
        self::assertFalse($consent->granted(BuyerConsent::PURPOSE_SALE_OR_SHARING));

        self::assertSame(
            ConsentPurpose::SOURCE_PLATFORM,
            $consent->purposes[BuyerConsent::PURPOSE_MARKETING]->source,
            'a bare boolean makes no claim about who recorded it, so it is attributed to the sender',
        );
    }

    #[Test]
    public function aPurposeMissingItsSourceIsDropped(): void
    {
        // Defaulting the source would manufacture an attribution the payload never made.
        $consent = BuyerConsent::fromArray([
            BuyerConsent::PURPOSE_MARKETING => ['granted' => true, 'description' => 'Promotional email'],
            BuyerConsent::PURPOSE_ANALYTICS => ['granted' => true, 'source' => 'business', 'description' => 'Tracking'],
        ]);

        self::assertSame([BuyerConsent::PURPOSE_ANALYTICS], array_keys($consent->purposes));
        self::assertFalse($consent->granted(BuyerConsent::PURPOSE_MARKETING));
    }

    #[Test]
    public function itRoundTripsThroughItsArrayForm(): void
    {
        $payload = [
            BuyerConsent::PURPOSE_MARKETING => [
                'granted' => true,
                'source' => 'business',
                'description' => 'Marketing communications',
                'links' => [['rel' => 'privacy-policy', 'href' => 'https://merchant.example/privacy']],
                'segments' => [
                    'sms' => ['granted' => false, 'source' => 'business', 'description' => 'By SMS'],
                ],
            ],
        ];

        self::assertSame($payload, BuyerConsent::fromArray($payload)->toArray());
    }

    #[Test]
    public function itOmitsEmptyOptionalMembers(): void
    {
        $purpose = new ConsentPurpose(true, ConsentPurpose::SOURCE_BUSINESS, 'Marketing');

        self::assertSame(
            ['granted' => true, 'source' => 'business', 'description' => 'Marketing'],
            $purpose->toArray(),
        );
    }
}
