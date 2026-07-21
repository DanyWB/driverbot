<?php

namespace App\Domain\Bookings\Services;

use App\Domain\Bookings\Presenters\AdminBookingPresenter;
use App\Models\Booking;
use RuntimeException;

class BookingCsvExporter
{
    public function __construct(private readonly AdminBookingPresenter $presenter) {}

    /** @param iterable<Booking> $bookings */
    public function write(iterable $bookings): void
    {
        $stream = fopen('php://output', 'wb');

        if ($stream === false) {
            throw new RuntimeException('CSV output stream could not be opened.');
        }

        fwrite($stream, "\xEF\xBB\xBF");
        $delimiter = (string) config('business.csv_delimiter', ';');
        $delimiter = in_array($delimiter, [',', ';', "\t"], true) ? $delimiter : ';';
        fputcsv($stream, $this->presenter->csvHeaders(), $delimiter, '"', '');

        foreach ($bookings as $booking) {
            $row = array_map(fn (mixed $value): string => $this->safeCell($value), $this->presenter->csvRow($booking));
            fputcsv($stream, $row, $delimiter, '"', '');
        }

        fclose($stream);
    }

    private function safeCell(mixed $value): string
    {
        $cell = $value === null ? '' : (string) $value;

        return preg_match('/^[=+\-@]/u', $cell) === 1 ? "'{$cell}" : $cell;
    }
}
