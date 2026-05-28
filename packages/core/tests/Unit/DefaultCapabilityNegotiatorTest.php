<?php

declare(strict_types=1);

namespace Ucp\Sdk\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Contract\CapabilityInterface;
use Ucp\Sdk\Contract\CheckoutCapabilityInterface;
use Ucp\Sdk\Contract\DiscountCapabilityInterface;
use Ucp\Sdk\Contract\OrderCapabilityInterface;
use Ucp\Sdk\Contract\PaymentHandlerInterface;
use Ucp\Sdk\Internal\Negotiation\DefaultCapabilityNegotiator;
use Ucp\Sdk\Model\Cart\Cart;
use Ucp\Sdk\Model\Checkout\Checkout;
use Ucp\Sdk\Model\Checkout\CheckoutCreateRequest;
use Ucp\Sdk\Model\Checkout\CheckoutUpdateRequest;
use Ucp\Sdk\Model\Checkout\DiscountCode;
use Ucp\Sdk\Model\Checkout\PaymentInstrument;
use Ucp\Sdk\Model\Order\OrderView;
use Ucp\Sdk\Model\Profile\CapabilityDescriptor;
use Ucp\Sdk\Model\Profile\PaymentHandlerDescriptor;
use Ucp\Sdk\Model\Profile\PlatformProfile;
use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Service\CapabilityRegistryInterface;
use Ucp\Sdk\Service\PaymentHandlerRegistryInterface;

final class DefaultCapabilityNegotiatorTest extends TestCase
{
    #[Test]
    public function itIntersectsCapabilitiesAndPaymentHandlers(): void
    {
        $negotiator = new DefaultCapabilityNegotiator(
            new class () implements CapabilityRegistryInterface {
                public function all(): array
                {
                    return [
                        new class () implements CheckoutCapabilityInterface {
                            public function describe(): CapabilityDescriptor
                            {
                                return new CapabilityDescriptor('dev.ucp.shopping.checkout', '2026-04-08', 'https://example.test/spec/checkout', 'https://example.test/schema/checkout');
                            }

                            public function createCheckout(CheckoutCreateRequest $request, RequestContext $context): Checkout
                            {
                                throw new \LogicException('Not used in this test.');
                            }

                            public function getCheckout(string $id, RequestContext $context): Checkout
                            {
                                throw new \LogicException('Not used in this test.');
                            }

                            public function updateCheckout(CheckoutUpdateRequest $request, RequestContext $context): Checkout
                            {
                                throw new \LogicException('Not used in this test.');
                            }

                            public function completeCheckout(string $id, RequestContext $context): Checkout
                            {
                                throw new \LogicException('Not used in this test.');
                            }

                            public function cancelCheckout(string $id, RequestContext $context): Checkout
                            {
                                throw new \LogicException('Not used in this test.');
                            }
                        },
                        new class () implements DiscountCapabilityInterface {
                            public function describe(): CapabilityDescriptor
                            {
                                return new CapabilityDescriptor('dev.ucp.shopping.discount', '2026-04-08', 'https://example.test/spec/discount', 'https://example.test/schema/discount');
                            }

                            public function applyCartDiscount(string $cartId, DiscountCode $discount, RequestContext $context): Cart
                            {
                                throw new \LogicException('Not used in this test.');
                            }
                        },
                        new class () implements OrderCapabilityInterface {
                            public function describe(): CapabilityDescriptor
                            {
                                return new CapabilityDescriptor('dev.ucp.shopping.order', '2026-04-08', 'https://example.test/spec/order', 'https://example.test/schema/order');
                            }

                            public function getOrder(string $id, RequestContext $context): OrderView
                            {
                                throw new \LogicException('Not used in this test.');
                            }
                        },
                    ];
                }

                public function find(string $name): ?CapabilityInterface
                {
                    return null;
                }

                public function firstImplementing(string $interface): ?CapabilityInterface
                {
                    return null;
                }
            },
            new class () implements PaymentHandlerRegistryInterface {
                public function all(): array
                {
                    return [
                        new class () implements PaymentHandlerInterface {
                            public function id(): string
                            {
                                return 'handler-1';
                            }

                            public function describe(RequestContext $context): PaymentHandlerDescriptor
                            {
                                return new PaymentHandlerDescriptor($this->id(), 'Card', '2026-04-08', 'https://example.test/spec/card', 'https://example.test/schema/card', []);
                            }

                            public function prepareInstrument(PaymentInstrument $instrument, RequestContext $context): array
                            {
                                return ['paymentMethodId' => 'card', 'token' => 'tok_1'];
                            }

                            public function supportsTokenization(): bool
                            {
                                return true;
                            }

                            public function tokenize(PaymentInstrument $instrument, RequestContext $context): ?array
                            {
                                return null;
                            }
                        },
                        new class () implements PaymentHandlerInterface {
                            public function id(): string
                            {
                                return 'handler-2';
                            }

                            public function describe(RequestContext $context): PaymentHandlerDescriptor
                            {
                                return new PaymentHandlerDescriptor($this->id(), 'Wallet', '2026-04-08', 'https://example.test/spec/wallet', 'https://example.test/schema/wallet', []);
                            }

                            public function prepareInstrument(PaymentInstrument $instrument, RequestContext $context): array
                            {
                                return ['paymentMethodId' => 'wallet', 'token' => 'tok_2'];
                            }

                            public function supportsTokenization(): bool
                            {
                                return true;
                            }

                            public function tokenize(PaymentInstrument $instrument, RequestContext $context): ?array
                            {
                                return null;
                            }
                        },
                    ];
                }

                public function find(string $name): ?PaymentHandlerInterface
                {
                    return null;
                }
            },
        );

        $platformProfile = new PlatformProfile(
            '2026-04-08',
            [],
            [
                'dev.ucp.shopping.checkout' => [
                    new CapabilityDescriptor('dev.ucp.shopping.checkout', '2026-04-08', 'https://platform.example/spec/checkout', 'https://platform.example/schema/checkout'),
                ],
                'dev.ucp.shopping.discount' => [
                    new CapabilityDescriptor('dev.ucp.shopping.discount', '2026-04-08', 'https://platform.example/spec/discount', 'https://platform.example/schema/discount', ['dev.ucp.shopping.checkout']),
                ],
                'dev.ucp.shopping.loyalty' => [
                    new CapabilityDescriptor('dev.ucp.shopping.loyalty', '2026-04-08', 'https://platform.example/spec/loyalty', 'https://platform.example/schema/loyalty', ['dev.ucp.shopping.catalog']),
                ],
            ],
            [
                'payments' => [
                    new PaymentHandlerDescriptor('handler-2', 'Wallet', '2026-04-08', 'https://platform.example/spec/wallet', 'https://platform.example/schema/wallet', []),
                    new PaymentHandlerDescriptor('handler-3', 'Voucher', '2026-04-08', 'https://platform.example/spec/voucher', 'https://platform.example/schema/voucher', []),
                ],
            ],
        );

        $result = $negotiator->negotiate($platformProfile, new RequestContext('merchant.example'));

        self::assertSame(['dev.ucp.shopping.checkout', 'dev.ucp.shopping.discount'], $result->capabilityNames());
        self::assertSame(['handler-2'], $result->paymentHandlerIds);
        self::assertSame(['dev.ucp.shopping.checkout', 'dev.ucp.shopping.discount'], $result->capabilitiesForOperation('checkout.create'));
        self::assertSame([], $result->capabilitiesForOperation('order.get'));
    }
}
