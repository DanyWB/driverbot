<?php

namespace App\Domain\Integrations\Bot\Services;

use App\Domain\Integrations\Bot\Exceptions\BotApiException;
use App\Domain\Shared\Data\IdempotencyResponse;
use App\Domain\Shared\Services\IdempotencyService;
use App\Http\Responses\BotApiResponse;
use App\Models\ServiceApiClient;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IdempotentBotAction
{
    public function __construct(private readonly IdempotencyService $idempotency) {}

    /**
     * @param  array<string, mixed>  $payload
     * @param  Closure(): array{status: int, data: array<string, mixed>|list<mixed>|null}  $operation
     */
    public function execute(Request $request, string $scope, array $payload, Closure $operation): JsonResponse
    {
        $key = trim((string) $request->header('Idempotency-Key', ''));

        if ($key === '') {
            throw new BotApiException('idempotency_key_required', 'Idempotency-Key is required.', 422);
        }

        $client = $request->attributes->get('service_api_client');

        if (! $client instanceof ServiceApiClient) {
            throw new BotApiException('unauthenticated', 'A service client is required.', 401);
        }

        $response = $this->idempotency->execute(
            (int) $client->id,
            "bot:{$client->id}:{$scope}",
            $key,
            $payload,
            function () use ($operation): IdempotencyResponse {
                $result = $operation();

                return new IdempotencyResponse($result['status'], ['data' => $result['data']]);
            },
            (int) config('bot_api.idempotency_ttl_hours', 72),
        );

        $data = $response->body['data'] ?? null;

        return BotApiResponse::success(
            $request,
            is_array($data) ? $data : null,
            $response->status,
            $response->replayed,
        );
    }
}
