<?php

declare(strict_types=1);

use App\Domain\Bookings\Data\BookingActor;
use App\Domain\Bookings\Data\CreateBookingData;
use App\Domain\Bookings\Enums\BookingSource;
use App\Domain\Bookings\Exceptions\BookingException;
use App\Domain\Bookings\Services\BookingService;
use App\Models\Booking;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$script, $customerId, $vehicleId, $startsOn, $endsOn, $barrier] = $argv;
CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-01 10:00', 'Asia/Bangkok'));
Carbon::setTestNow(CarbonImmutable::now());
$waitMicroseconds = max(0, (int) (((float) $barrier - microtime(true)) * 1_000_000));

if ($waitMicroseconds > 0) {
    usleep($waitMicroseconds);
}

try {
    if (($argv[6] ?? '') === 'approve') {
        $booking = $app->make(BookingService::class)->approve(
            Booking::findOrFail((int) $customerId),
            BookingActor::admin((int) $vehicleId),
        );
        echo json_encode(['status' => 200, 'booking_id' => $booking->public_id], JSON_THROW_ON_ERROR);
        exit(0);
    }
    $booking = $app->make(BookingService::class)->create(
        new CreateBookingData(
            customerId: (int) $customerId,
            vehicleId: (int) $vehicleId,
            startsOn: $startsOn,
            endsOn: $endsOn,
            source: BookingSource::Telegram,
            termsAcceptedAt: now(),
            termsVersion: (string) config('business.terms_version'),
        ),
        BookingActor::customer((int) $customerId),
    );

    echo json_encode(['status' => 201, 'booking_id' => $booking->public_id], JSON_THROW_ON_ERROR);
} catch (BookingException $exception) {
    echo json_encode([
        'status' => $exception->httpStatus,
        'error_code' => $exception->errorCode,
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage());
    echo json_encode(['status' => 500], JSON_THROW_ON_ERROR);
}
