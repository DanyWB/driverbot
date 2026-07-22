<?php

namespace App\Http\Controllers\Api\V1\Bot;

use App\Domain\Bookings\Exceptions\BookingException;
use App\Domain\Customers\Services\CustomerDocumentService;
use App\Domain\Integrations\Bot\Presenters\BotApiPresenter;
use App\Domain\Integrations\Bot\Services\IdempotentBotAction;
use App\Domain\Integrations\Bot\Services\ServiceAuditService;
use App\Http\Controllers\Api\V1\Bot\Concerns\HandlesBotApiContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Bot\StoreBotDocumentRequest;
use App\Models\Booking;
use App\Models\CustomerDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class DocumentController extends Controller
{
    use HandlesBotApiContext;

    public function storeForCustomer(
        StoreBotDocumentRequest $request,
        CustomerDocumentService $documents,
        ServiceAuditService $audit,
        BotApiPresenter $presenter,
        IdempotentBotAction $idempotent,
    ): JsonResponse {
        return $this->store($request, null, $documents, $audit, $presenter, $idempotent);
    }

    public function storeForBooking(
        StoreBotDocumentRequest $request,
        string $booking,
        CustomerDocumentService $documents,
        ServiceAuditService $audit,
        BotApiPresenter $presenter,
        IdempotentBotAction $idempotent,
    ): JsonResponse {
        $model = Booking::query()
            ->where('public_id', $booking)
            ->where('customer_id', $this->customer($request)->id)
            ->first();

        if (! $model instanceof Booking) {
            throw new BookingException('booking_not_found', 'Booking was not found.', 404);
        }

        return $this->store($request, $model, $documents, $audit, $presenter, $idempotent);
    }

    private function store(
        StoreBotDocumentRequest $request,
        ?Booking $booking,
        CustomerDocumentService $documents,
        ServiceAuditService $audit,
        BotApiPresenter $presenter,
        IdempotentBotAction $idempotent,
    ): JsonResponse {
        $file = $request->file('document');
        abort_if($file === null, 422);
        $customer = $this->customer($request);
        $payload = [
            'type' => (string) $request->validated('type'),
            'booking_public_id' => $booking?->public_id,
            'filename' => $file->getClientOriginalName(),
            'size' => $file->getSize(),
            'sha256' => hash_file('sha256', $file->getRealPath()),
        ];
        $document = null;

        try {
            return $idempotent->execute(
                $request,
                $booking instanceof Booking
                    ? "bookings.{$booking->public_id}.documents.store"
                    : "customers.{$customer->id}.documents.store",
                $payload,
                function () use ($request, $booking, $documents, $audit, $presenter, $customer, $file, &$document): array {
                    $storedDocument = DB::transaction(function () use ($request, $booking, $documents, $audit, $customer, $file, &$document): CustomerDocument {
                        $document = $documents->storeByCustomer(
                            $customer,
                            $file,
                            (string) $request->validated('type'),
                            $booking,
                        );
                        $audit->record(
                            $this->serviceClient($request),
                            $document,
                            'customer_document.uploaded_by_bot',
                            null,
                            [
                                'customer_id' => (int) $customer->id,
                                'booking_public_id' => $booking?->public_id,
                                'type' => (string) $document->type,
                                'filename' => (string) $document->original_filename,
                            ],
                            $this->requestId($request),
                            $request->ip(),
                            $request->userAgent(),
                        );

                        return $document;
                    });

                    return ['status' => 201, 'data' => $presenter->document($storedDocument)];
                },
            );
        } catch (\Throwable $exception) {
            if ($document instanceof CustomerDocument) {
                $documents->discardFile($document);
            }

            throw $exception;
        }
    }
}
