<?php

namespace App\Domain\Notifications\Data;

final readonly class AdminTelegramRecipient
{
    public function __construct(
        public string $outboxRecipient,
        public string $chatId,
        public string $locale,
        public ?int $bindingId = null,
        public ?int $generation = null,
    ) {}

    public function deduplicationSuffix(): string
    {
        if ($this->bindingId === null || $this->generation === null) {
            return 'legacy';
        }

        return "binding:{$this->bindingId}:v{$this->generation}";
    }
}
