<?php

namespace App\Http\Middleware;

use App\Domain\Customers\Enums\IdentityProvider;
use App\Domain\Integrations\Bot\Exceptions\BotApiException;
use App\Models\CustomerIdentity;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveTelegramCustomer
{
    public function handle(Request $request, Closure $next): Response
    {
        $telegramId = trim((string) $request->header('X-Telegram-User-ID', ''));

        if (preg_match('/^[1-9][0-9]{0,19}$/', $telegramId) !== 1) {
            throw new BotApiException('telegram_identity_required', 'X-Telegram-User-ID is required.', 422);
        }

        $identity = CustomerIdentity::query()
            ->where('provider', IdentityProvider::Telegram->value)
            ->where('external_id', $telegramId)
            ->with('customer')
            ->first();

        if (! $identity instanceof CustomerIdentity) {
            throw new BotApiException('customer_not_synced', 'The Telegram customer must be synchronized first.', 404);
        }

        $request->attributes->set('telegram_identity', $identity);
        $request->attributes->set('customer', $identity->customer);

        return $next($request);
    }
}
