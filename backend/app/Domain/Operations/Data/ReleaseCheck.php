<?php

namespace App\Domain\Operations\Data;

final readonly class ReleaseCheck
{
    public const PASS = 'pass';

    public const WARNING = 'warning';

    public const FAILURE = 'failure';

    public function __construct(
        public string $id,
        public string $status,
        public string $message,
    ) {}

    /** @return array{id: string, status: string, message: string} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'message' => $this->message,
        ];
    }
}
