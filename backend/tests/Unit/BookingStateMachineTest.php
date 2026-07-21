<?php

namespace Tests\Unit;

use App\Domain\Bookings\Enums\BookingStatus;
use App\Domain\Bookings\Exceptions\BookingException;
use App\Domain\Bookings\Services\BookingStateMachine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BookingStateMachineTest extends TestCase
{
    /** @return iterable<string, array{BookingStatus, BookingStatus}> */
    public static function allowedTransitions(): iterable
    {
        yield 'process to pending' => [BookingStatus::Process, BookingStatus::Pending];
        yield 'process client cancellation' => [BookingStatus::Process, BookingStatus::CancelledByClient];
        yield 'pending approval' => [BookingStatus::Pending, BookingStatus::Approved];
        yield 'pending admin cancellation' => [BookingStatus::Pending, BookingStatus::Cancelled];
        yield 'pending client cancellation' => [BookingStatus::Pending, BookingStatus::CancelledByClient];
        yield 'pending expiry' => [BookingStatus::Pending, BookingStatus::Expired];
        yield 'approved activation' => [BookingStatus::Approved, BookingStatus::Active];
        yield 'approved admin cancellation' => [BookingStatus::Approved, BookingStatus::Cancelled];
        yield 'approved client cancellation' => [BookingStatus::Approved, BookingStatus::CancelledByClient];
        yield 'approved no show' => [BookingStatus::Approved, BookingStatus::NoShow];
        yield 'active completion' => [BookingStatus::Active, BookingStatus::Completed];
        yield 'active emergency cancellation' => [BookingStatus::Active, BookingStatus::Cancelled];
    }

    /** @return iterable<string, array{BookingStatus, BookingStatus}> */
    public static function forbiddenTransitions(): iterable
    {
        yield 'active no show' => [BookingStatus::Active, BookingStatus::NoShow];
        yield 'completed no show' => [BookingStatus::Completed, BookingStatus::NoShow];
        yield 'cancelled approval' => [BookingStatus::Cancelled, BookingStatus::Approved];
        yield 'pending activation' => [BookingStatus::Pending, BookingStatus::Active];
        yield 'completed cancellation' => [BookingStatus::Completed, BookingStatus::Cancelled];
    }

    #[DataProvider('allowedTransitions')]
    public function test_all_approved_transitions_are_allowed(BookingStatus $from, BookingStatus $to): void
    {
        $this->assertTrue((new BookingStateMachine)->canTransition($from, $to));
    }

    #[DataProvider('forbiddenTransitions')]
    public function test_unapproved_transitions_have_a_stable_business_error(BookingStatus $from, BookingStatus $to): void
    {
        try {
            (new BookingStateMachine)->assertCanTransition($from, $to);
            $this->fail('A forbidden booking transition was accepted.');
        } catch (BookingException $exception) {
            $this->assertSame('invalid_booking_transition', $exception->errorCode);
            $this->assertSame(409, $exception->httpStatus);
        }
    }
}
