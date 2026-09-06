<?php

namespace App\Domain\Administration\Data;

use App\Models\AdminTelegramBinding;

final readonly class AdminTelegramBindingMutation
{
    /** @param array<string, mixed>|null $oldValues */
    public function __construct(
        public AdminTelegramBinding $binding,
        public ?array $oldValues,
    ) {}
}
