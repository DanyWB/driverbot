<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['id', 'minimum_days', 'category_minimum_days'])]
class RentalPolicy extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['minimum_days' => 'integer', 'category_minimum_days' => 'array'];
    }

    public function minimumFor(?int $categoryId): int
    {
        $overrides = $this->getAttribute('category_minimum_days');
        $override = is_array($overrides) ? ($overrides[$categoryId] ?? null) : null;

        return max(1, (int) ($override ?? $this->getAttribute('minimum_days') ?? 1));
    }
}
