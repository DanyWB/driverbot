<?php

namespace App\Domain\Shared\Services;

use App\Domain\Bookings\Exceptions\BookingException;
use App\Domain\Shared\Data\IdempotencyResponse;
use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use JsonException;

class IdempotencyService
{
    /**
     * @param  array<string, mixed>  $request
     * @param  Closure(): IdempotencyResponse  $operation
     */
    public function execute(
        ?int $serviceApiClientId,
        string $scope,
        string $key,
        array $request,
        Closure $operation,
        int $ttlHours = 24,
    ): IdempotencyResponse {
        $scope = trim($scope);
        $key = trim($key);

        if ($scope === '' || strlen($scope) > 100 || $key === '' || strlen($key) > 255) {
            throw new BookingException('invalid_idempotency_key', 'Idempotency scope or key is invalid.', 422);
        }

        $hash = $this->hash($request);

        try {
            return DB::transaction(function () use ($serviceApiClientId, $scope, $key, $hash, $operation, $ttlHours): IdempotencyResponse {
                $existing = IdempotencyKey::query()
                    ->where('scope', $scope)
                    ->where('idempotency_key', $key)
                    ->lockForUpdate()
                    ->first();

                if ($existing instanceof IdempotencyKey && $existing->expiresAt()->isPast()) {
                    $existing->delete();
                    $existing = null;
                }

                if ($existing instanceof IdempotencyKey) {
                    return $this->replay($existing, $hash);
                }

                $record = IdempotencyKey::query()->create([
                    'service_api_client_id' => $serviceApiClientId,
                    'scope' => $scope,
                    'idempotency_key' => $key,
                    'request_hash' => $hash,
                    'status' => 'processing',
                    'expires_at' => now()->addHours(max(1, $ttlHours)),
                ]);

                $response = $operation();
                $record->forceFill([
                    'status' => 'completed',
                    'response_status' => $response->status,
                    'response_body' => $response->body,
                ])->save();

                return $response;
            }, 3);
        } catch (QueryException $exception) {
            if (! $this->isUniqueViolation($exception)) {
                throw $exception;
            }

            $existing = IdempotencyKey::query()
                ->where('scope', $scope)
                ->where('idempotency_key', $key)
                ->first();

            if (! $existing instanceof IdempotencyKey) {
                throw $exception;
            }

            return $this->replay($existing, $hash);
        }
    }

    private function replay(IdempotencyKey $record, string $hash): IdempotencyResponse
    {
        if (! hash_equals((string) $record->request_hash, $hash)) {
            throw new BookingException('idempotency_key_reused', 'Idempotency key was already used with a different request.', 409);
        }

        $responseBody = $record->responseBody();

        if ($record->status !== 'completed' || $record->response_status === null || $responseBody === null) {
            throw new BookingException('idempotency_in_progress', 'An operation with this idempotency key is still processing.', 409);
        }

        return new IdempotencyResponse((int) $record->response_status, $responseBody, true);
    }

    /** @param array<string, mixed> $request */
    private function hash(array $request): string
    {
        try {
            return hash('sha256', json_encode($this->canonicalize($request), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        } catch (JsonException $exception) {
            throw new BookingException('invalid_idempotency_payload', 'Idempotency payload cannot be encoded.', 422, previous: $exception);
        }
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());

        return in_array($sqlState, ['23000', '23505'], true);
    }
}
