<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Operation;

/** @internal */
final class ShoppingOperationToolSchemas
{
    public const CART_CREATE_INPUT = [
        'type' => 'object',
        'properties' => [
            'payload' => self::CART_CREATE_PAYLOAD,
        ],
        'required' => ['payload'],
        'additionalProperties' => false,
    ];

    public const CART_UPDATE_INPUT = [
        'type' => 'object',
        'properties' => [
            'id' => self::ID,
            'payload' => self::CART_UPDATE_PAYLOAD,
        ],
        'required' => ['id', 'payload'],
        'additionalProperties' => false,
    ];

    public const CHECKOUT_CREATE_INPUT = [
        'type' => 'object',
        'properties' => [
            'payload' => self::CHECKOUT_CREATE_PAYLOAD,
        ],
        'required' => ['payload'],
        'additionalProperties' => false,
    ];

    public const CHECKOUT_UPDATE_INPUT = [
        'type' => 'object',
        'properties' => [
            'id' => self::ID,
            'payload' => self::CHECKOUT_UPDATE_PAYLOAD,
        ],
        'required' => ['id', 'payload'],
        'additionalProperties' => false,
    ];

    public const CHECKOUT_COMPLETE_INPUT = [
        'type' => 'object',
        'properties' => [
            'id' => self::ID,
            'payload' => self::CHECKOUT_COMPLETE_PAYLOAD,
        ],
        'required' => ['id', 'payload'],
        'additionalProperties' => false,
    ];

    private const ID = [
        'type' => 'string',
        'minLength' => 1,
        'description' => 'The UCP resource identifier.',
    ];

    private const CART_CREATE_PAYLOAD = [
        'type' => 'object',
        'description' => 'UCP cart.create request payload.',
        'required' => ['line_items'],
        'properties' => [
            'line_items' => ['type' => 'array'],
            'signals' => ['type' => 'object'],
        ],
        'additionalProperties' => true,
    ];

    private const CART_UPDATE_PAYLOAD = [
        'type' => 'object',
        'description' => 'UCP cart.update request payload.',
        'required' => ['line_items'],
        'properties' => [
            'line_items' => ['type' => 'array'],
        ],
        'additionalProperties' => true,
    ];

    private const CHECKOUT_CREATE_PAYLOAD = [
        'type' => 'object',
        'description' => 'UCP checkout.create request payload. Provide either cart_id or line_items.',
        'anyOf' => [
            ['required' => ['line_items']],
            ['required' => ['cart_id']],
        ],
        'properties' => [
            'cart_id' => ['type' => 'string'],
            'line_items' => ['type' => 'array'],
            'buyer' => ['type' => 'object'],
            'signals' => ['type' => 'object'],
            'discounts' => ['type' => 'object'],
            'fulfillment' => ['type' => 'object'],
            'buyer_consent' => ['type' => 'object'],
        ],
        'additionalProperties' => true,
    ];

    private const CHECKOUT_COMPLETE_PAYLOAD = [
        'type' => 'object',
        'description' => 'UCP checkout.complete request payload with the selected payment instruments and optional AP2 mandate data.',
        'required' => ['payment'],
        'properties' => [
            'payment' => [
                'type' => 'object',
                'properties' => [
                    'instruments' => ['type' => 'array'],
                ],
            ],
            'ap2' => [
                'type' => 'object',
                'properties' => [
                    'checkout_mandate' => [
                        'type' => 'string',
                        'pattern' => '^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]*\.[A-Za-z0-9_-]+(~[A-Za-z0-9_-]+)*$',
                    ],
                ],
            ],
        ],
        'additionalProperties' => true,
    ];

    private const CHECKOUT_UPDATE_PAYLOAD = [
        'type' => 'object',
        'description' => 'UCP checkout.update request payload.',
        'properties' => [
            'line_items' => ['type' => 'array'],
            'buyer' => ['type' => 'object'],
            'discounts' => ['type' => 'object'],
            'fulfillment' => ['type' => 'object'],
            'buyer_consent' => ['type' => 'object'],
            'payment' => ['type' => 'object'],
        ],
        'additionalProperties' => true,
    ];
}
