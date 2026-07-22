<?php

namespace App\Domain\Customers\Services;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\CustomerDocument;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class CustomerDocumentService
{
    public function store(
        Customer $customer,
        UploadedFile $file,
        string $type,
        User $admin,
        ?Booking $booking = null,
    ): CustomerDocument {
        return $this->storeDocument($customer, $file, $type, 'admin', $admin->id, $booking);
    }

    public function storeByCustomer(
        Customer $customer,
        UploadedFile $file,
        string $type,
        ?Booking $booking = null,
    ): CustomerDocument {
        return $this->storeDocument($customer, $file, $type, 'customer', null, $booking);
    }

    private function storeDocument(
        Customer $customer,
        UploadedFile $file,
        string $type,
        string $uploadedBy,
        ?int $adminId,
        ?Booking $booking,
    ): CustomerDocument {
        if ($booking instanceof Booking && (int) $booking->customer_id !== (int) $customer->id) {
            throw new RuntimeException('Booking does not belong to this customer.');
        }

        $disk = 'private';
        $extension = strtolower($file->extension() ?: 'bin');
        $directory = "customers/{$customer->id}/documents";
        $path = "{$directory}/".Str::uuid()->toString().".{$extension}";
        $stored = Storage::disk($disk)->putFileAs($directory, $file, basename($path));

        if ($stored === false) {
            throw new RuntimeException('Customer document could not be stored.');
        }

        try {
            return CustomerDocument::query()->create([
                'customer_id' => $customer->id,
                'booking_id' => $booking?->id,
                'type' => $type,
                'disk' => $disk,
                'file_path' => $path,
                'original_filename' => basename($file->getClientOriginalName()),
                'mime_type' => $file->getMimeType(),
                'file_size' => $file->getSize(),
                'uploaded_by' => $uploadedBy,
                'uploaded_by_admin_id' => $adminId,
            ]);
        } catch (\Throwable $exception) {
            Storage::disk($disk)->delete($path);

            throw $exception;
        }
    }

    public function delete(CustomerDocument $document): void
    {
        $disk = (string) $document->disk;
        $path = (string) $document->file_path;
        $document->delete();
        DB::afterCommit(fn () => Storage::disk($disk)->delete($path));
    }

    public function discardFile(CustomerDocument $document): void
    {
        Storage::disk((string) $document->disk)->delete((string) $document->file_path);
    }
}
