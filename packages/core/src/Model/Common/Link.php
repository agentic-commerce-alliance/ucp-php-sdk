<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Common;

final class Link
{
    public function __construct(
        public readonly string $type,
        public readonly string $url,
        public readonly ?string $title = null,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        $data = [
            'type' => $this->type,
            'url' => $this->url,
        ];

        if ($this->title !== null) {
            $data['title'] = $this->title;
        }

        return $data;
    }
}
