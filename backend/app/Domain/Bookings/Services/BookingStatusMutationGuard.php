<?php

namespace App\Domain\Bookings\Services;

use Closure;

class BookingStatusMutationGuard
{
    private int $depth = 0;

    public function isAllowed(): bool
    {
        return $this->depth > 0;
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function run(Closure $callback): mixed
    {
        $this->depth++;

        try {
            return $callback();
        } finally {
            $this->depth--;
        }
    }
}
