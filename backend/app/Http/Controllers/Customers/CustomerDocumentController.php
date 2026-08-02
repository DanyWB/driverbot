<?php

namespace App\Http\Controllers\Customers;

use App\Domain\Customers\Services\CustomerDocumentService;
use App\Domain\Shared\Services\AdminAuditService;
use App\Http\Controllers\Concerns\HandlesAdminContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customers\StoreCustomerDocumentRequest;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\CustomerDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CustomerDocumentController extends Controller
{
    use HandlesAdminContext;

    public function storeForCustomer(
        StoreCustomerDocumentRequest $request,
        Customer $customer,
        CustomerDocumentService $documents,
        AdminAuditService $audit,
    ): RedirectResponse {
        $this->store($request, $customer, null, $documents, $audit);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Document uploaded.']);

        return to_route('customers.show', $customer);
    }

    public function storeForBooking(
        StoreCustomerDocumentRequest $request,
        Booking $booking,
        CustomerDocumentService $documents,
        AdminAuditService $audit,
    ): RedirectResponse {
        $booking->loadMissing('customer');
        $this->store($request, $booking->customer, $booking, $documents, $audit);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Document uploaded.']);

        return to_route('bookings.show', $booking);
    }

    public function download(CustomerDocument $document): StreamedResponse
    {
        abort_unless(Storage::disk((string) $document->disk)->exists((string) $document->file_path), 404);

        return Storage::disk((string) $document->disk)->download(
            (string) $document->file_path,
            (string) $document->original_filename,
            array_filter([
                'Content-Type' => $document->mime_type,
                'Cache-Control' => 'no-store, private',
                'X-Content-Type-Options' => 'nosniff',
            ]),
        );
    }

    public function destroy(
        Request $request,
        CustomerDocument $document,
        CustomerDocumentService $documents,
        AdminAuditService $audit,
    ): RedirectResponse {
        $customer = $document->customer;
        $booking = $document->booking;
        $oldValues = [
            'customer_id' => (int) $document->customer_id,
            'booking_id' => $document->booking_id,
            'type' => (string) $document->type,
            'filename' => (string) $document->original_filename,
        ];

        DB::transaction(function () use ($request, $document, $documents, $audit, $oldValues): void {
            $documents->delete($document);
            $audit->record(
                $this->admin($request),
                $document,
                'customer_document.deleted',
                $oldValues,
                null,
                $this->requestId($request),
                $request->ip(),
                $this->userAgent($request),
            );
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Document deleted.']);

        return $booking instanceof Booking
            ? to_route('bookings.show', $booking)
            : to_route('customers.show', $customer);
    }

    private function store(
        StoreCustomerDocumentRequest $request,
        Customer $customer,
        ?Booking $booking,
        CustomerDocumentService $documents,
        AdminAuditService $audit,
    ): CustomerDocument {
        $file = $request->file('document');
        abort_if($file === null, 422);

        $document = null;

        try {
            return DB::transaction(function () use ($request, $customer, $booking, $documents, $audit, $file, &$document): CustomerDocument {
                $document = $documents->store(
                    $customer,
                    $file,
                    (string) $request->validated('type'),
                    $this->admin($request),
                    $booking,
                );
                $audit->record(
                    $this->admin($request),
                    $document,
                    'customer_document.uploaded',
                    null,
                    [
                        'customer_id' => (int) $customer->id,
                        'booking_id' => $booking?->id,
                        'type' => (string) $document->type,
                        'filename' => (string) $document->original_filename,
                        'file_size' => $document->file_size,
                    ],
                    $this->requestId($request),
                    $request->ip(),
                    $this->userAgent($request),
                );

                return $document;
            });
        } catch (\Throwable $exception) {
            if ($document instanceof CustomerDocument) {
                $documents->discardFile($document);
            }

            throw $exception;
        }
    }
}
