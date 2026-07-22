<?php

namespace App\Http\Controllers\Api\V1\Bot\Concerns;

use App\Domain\Integrations\Bot\Exceptions\BotApiException;
use App\Models\Customer;
use App\Models\ServiceApiClient;
use Illuminate\Http\Request;

trait HandlesBotApiContext
{
    protected function customer(Request $request): Customer
    {
        $customer = $request->attributes->get('customer');

        if (! $customer instanceof Customer) {
            throw new BotApiException('customer_not_synced', 'The Telegram customer must be synchronized first.', 404);
        }

        return $customer;
    }

    protected function serviceClient(Request $request): ServiceApiClient
    {
        $client = $request->attributes->get('service_api_client');

        if (! $client instanceof ServiceApiClient) {
            throw new BotApiException('unauthenticated', 'A service client is required.', 401);
        }

        return $client;
    }

    protected function requestId(Request $request): ?string
    {
        $requestId = trim((string) $request->attributes->get('request_id', ''));

        return $requestId === '' ? null : $requestId;
    }
}
