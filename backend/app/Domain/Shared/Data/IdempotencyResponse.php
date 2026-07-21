<?php

namespace App\Domain\Shared\Data;

final readonly class IdempotencyResponse
{
    /** @param array<string, mixed> $body */
    public function __construct(
        public int $status,
        public array $body,
        public bool $replayed = false,
    ) {}
}
