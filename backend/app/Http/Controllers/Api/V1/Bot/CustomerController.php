<?php

namespace App\Http\Controllers\Api\V1\Bot;

use App\Domain\Customers\Enums\IdentityProvider;
use App\Domain\Integrations\Bot\Presenters\BotApiPresenter;
use App\Domain\Integrations\Bot\Services\IdempotentBotAction;
use App\Domain\Integrations\Bot\Services\ServiceAuditService;
use App\Domain\Integrations\Bot\Services\TelegramCustomerService;
use App\Http\Controllers\Api\V1\Bot\Concerns\HandlesBotApiContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Bot\SyncTelegramCustomerRequest;
use App\Http\Requests\Api\V1\Bot\UpdateBotCustomerRequest;
use App\Http\Responses\BotApiResponse;
use App\Models\Customer;
use App\Models\CustomerIdentity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomerController extends Controller
{
    use HandlesBotApiContext;

    public function sync(
        SyncTelegramCustomerRequest $request,
        TelegramCustomerService $customers,
        BotApiPresenter $presenter,
        IdempotentBotAction $idempotent,
    ): JsonResponse {
        $data = $request->validated();

        return $idempotent->execute($request, 'customers.sync', $data, function () use ($customers, $presenter, $data): array {
            $customer = $customers->sync($data);

            return ['status' => 200, 'data' => $presenter->customer($customer)];
        });
    }

    public function show(Request $request, BotApiPresenter $presenter): JsonResponse
    {
        $customer = $this->customer($request)->load(['contacts', 'identities', 'documents']);

        return BotApiResponse::success($request, $presenter->customer($customer));
    }

    public function update(
        UpdateBotCustomerRequest $request,
        TelegramCustomerService $customers,
        BotApiPresenter $presenter,
        ServiceAuditService $audit,
        IdempotentBotAction $idempotent,
    ): JsonResponse {
        $data = $request->validated();
        $customer = $this->customer($request);

        return $idempotent->execute($request, "customers.{$customer->id}.update", $data, function () use ($request, $customers, $presenter, $audit, $data, $customer): array {
            $before = $this->auditProfile($customer->load(['contacts', 'identities', 'documents']));

            $updated = DB::transaction(function () use ($request, $customers, $audit, $data, $customer, $before) {
                $updated = $customers->update($customer, $data);
                $audit->record(
                    $this->serviceClient($request),
                    $updated,
                    'customer.telegram_profile_updated',
                    $before,
                    $this->auditProfile($updated),
                    $this->requestId($request),
                    $request->ip(),
                    $request->userAgent(),
                );

                return $updated;
            });

            return ['status' => 200, 'data' => $presenter->customer($updated)];
        });
    }

    /** @return array<string, mixed> */
    private function auditProfile(Customer $customer): array
    {
        $private = $customer->privateData();
        $identity = $customer->identities->first(fn (CustomerIdentity $identity): bool => $identity->getRawOriginal('provider') === IdentityProvider::Telegram->value);

        return [
            'name' => (string) $customer->name,
            'locale' => (string) $customer->locale,
            'telegram_id' => $identity?->external_id,
            'contacts' => $customer->contacts->map(fn ($contact): array => [
                'type' => $contact->getRawOriginal('type'),
                'value' => $contact->value,
            ])->values()->all(),
            'has_passport_number' => isset($private['passport_number']),
        ];
    }
}
