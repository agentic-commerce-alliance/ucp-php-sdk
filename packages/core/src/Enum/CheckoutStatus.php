<?php

declare(strict_types=1);

namespace Ucp\Sdk\Enum;

/**
 * Checkout lifecycle states from the UCP checkout flow.
 *
 * Reference: https://ucp.dev/specification/checkout/
 */
enum CheckoutStatus: string
{
    case Incomplete = 'incomplete';
    case RequiresEscalation = 'requires_escalation';
    case ReadyForComplete = 'ready_for_complete';
    case CompleteInProgress = 'complete_in_progress';
    case Completed = 'completed';
    case Canceled = 'canceled';
}
