<?php

namespace App\Domain\Pricing\Imports\Data;

use App\Domain\Pricing\Enums\PricingSeasonKey;
use App\Domain\Pricing\Enums\PricingTier;

final readonly class ImportedPrice
{
    public function __construct(
        public PricingSeasonKey $season,
        public PricingTier $tier,
        public int $packageTotal,
        public int $sourceRow,
    ) {}
}
