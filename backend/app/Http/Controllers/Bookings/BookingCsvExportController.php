<?php

namespace App\Http\Controllers\Bookings;

use App\Domain\Bookings\Queries\AdminBookingQuery;
use App\Domain\Bookings\Services\BookingCsvExporter;
use App\Http\Controllers\Controller;
use App\Http\Requests\Bookings\ListBookingsRequest;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BookingCsvExportController extends Controller
{
    public function __invoke(
        ListBookingsRequest $request,
        AdminBookingQuery $query,
        BookingCsvExporter $exporter,
    ): StreamedResponse {
        $bookings = $query->getForExport($request->filters());
        $filename = 'bookings-'.now((string) config('business.timezone'))->format('Y-m-d-His').'.csv';

        return response()->streamDownload(
            fn () => $exporter->write($bookings),
            $filename,
            [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Cache-Control' => 'no-store, private',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }
}
