<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Common;

final class Money
{
    public function __construct(
        public readonly string $type,
        public readonly float $amount,
        public readonly ?string $displayText = null,
    ) {
    }

    /**
     * @return array<string, float|string>
     */
    public function toArray(): array
    {
        $data = [
            'type' => $this->type,
            'amount' => $this->amount,
        ];

        if ($this->displayText !== null) {
            $data['display_text'] = $this->displayText;
        }

        return $data;
    }
}
