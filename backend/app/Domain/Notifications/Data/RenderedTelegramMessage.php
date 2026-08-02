<?php

namespace App\Domain\Notifications\Data;

final readonly class RenderedTelegramMessage
{
    /** @param array<string, mixed>|null $replyMarkup */
    public function __construct(
        public string $chatId,
        public string $text,
        public ?array $replyMarkup = null,
    ) {}
}
