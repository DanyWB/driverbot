<?php

namespace App\Http\Controllers\Api\V1\Bot;

use App\Domain\Administration\Exceptions\AdminTelegramBindingException;
use App\Domain\Administration\Services\AdminTelegramBindingService;
use App\Domain\Integrations\Bot\Exceptions\BotApiException;
use App\Domain\Integrations\Bot\Services\IdempotentBotAction;
use App\Domain\Integrations\Bot\Services\ServiceAuditService;
use App\Http\Controllers\Api\V1\Bot\Concerns\HandlesBotApiContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Bot\RevokeExposedAdminTelegramBindingCodeRequest;
use App\Http\Requests\Api\V1\Bot\StoreAdminTelegramBindingRequest;
use Illuminate\Http\JsonResponse;

class AdminTelegramBindingController extends Controller
{
    use HandlesBotApiContext;

    public function store(
        StoreAdminTelegramBindingRequest $request,
        AdminTelegramBindingService $bindings,
        ServiceAuditService $audit,
        IdempotentBotAction $idempotent,
    ): JsonResponse {
        $data = $request->bindingData();

        return $idempotent->execute(
            $request,
            'admin-telegram-bindings.store',
            $data,
            function () use ($request, $bindings, $audit, $data): array {
                try {
                    $mutation = $bindings->connect($data);
                } catch (AdminTelegramBindingException $exception) {
                    throw new BotApiException(
                        $exception->errorCode,
                        $exception->getMessage(),
                        $exception->httpStatus,
                    );
                }

                $binding = $mutation->binding;
                $audit->record(
                    $this->serviceClient($request),
                    $binding,
                    'admin.telegram_binding_connected',
                    $mutation->oldValues,
                    $bindings->snapshot($binding),
                    $this->requestId($request),
                    $request->ip(),
                    $request->userAgent(),
                );

                return [
                    'status' => 200,
                    'data' => [
                        'connected' => true,
                        'admin_name' => (string) $binding->admin()->value('name'),
                        'telegram_id' => (string) $binding->telegram_user_id,
                        'username' => $binding->username,
                        'generation' => (int) $binding->generation,
                        'connected_at' => $binding->connected_at?->toIso8601String(),
                    ],
                ];
            },
        );
    }

    public function revokeExposedCode(
        RevokeExposedAdminTelegramBindingCodeRequest $request,
        AdminTelegramBindingService $bindings,
        ServiceAuditService $audit,
        IdempotentBotAction $idempotent,
    ): JsonResponse {
        $data = [
            'code' => (string) $request->validated('code'),
            'telegram_id' => (string) $request->validated('telegram_id'),
        ];

        return $idempotent->execute(
            $request,
            'admin-telegram-binding-codes.revoke-exposed',
            $data,
            function () use ($request, $bindings, $audit, $data): array {
                $code = $bindings->revokeExposedCode($data['code']);

                if ($code !== null) {
                    $audit->record(
                        $this->serviceClient($request),
                        $code,
                        'admin.telegram_binding_code_revoked_exposed',
                        null,
                        ['admin_user_id' => (int) $code->admin_user_id],
                        $this->requestId($request),
                        $request->ip(),
                        $request->userAgent(),
                    );
                }

                // The result is intentionally identical for unknown, used and revoked codes.
                return [
                    'status' => 200,
                    'data' => ['revoked' => true],
                ];
            },
        );
    }
}
