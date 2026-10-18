<?php

namespace App\Domain\Customers\Services;

use App\Domain\Bookings\Exceptions\BookingException;
use App\Models\Customer;
use Illuminate\Support\Facades\DB;

class CustomerTermsService
{
    public function accept(Customer $customer, string $version): Customer
    {
        if (! hash_equals((string) config('business.terms_version'), $version)) {
            throw new BookingException('terms_version_outdated', 'The rental terms have changed and must be accepted again.', 409);
        }

        return DB::transaction(function () use ($customer, $version): Customer {
            $locked = Customer::query()->lockForUpdate()->findOrFail($customer->id);
            if ($locked->terms_version !== $version || $locked->terms_accepted_at === null) {
                $locked->forceFill(['terms_version' => $version, 'terms_accepted_at' => now()])->save();
            }

            return $locked;
        });
    }
}
