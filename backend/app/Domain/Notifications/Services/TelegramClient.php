<?php

namespace App\Domain\Notifications\Services;

use App\Domain\Notifications\Data\RenderedTelegramMessage;
use App\Domain\Notifications\Exceptions\NotificationDeliveryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

class TelegramClient
{
    public function send(RenderedTelegramMessage $message): string
    {
        $token = trim((string) config('notifications.telegram.bot_token'));

        if ($token === '') {
            throw NotificationDeliveryException::permanent('TELEGRAM_BOT_TOKEN is not configured.');
        }

        $baseUrl = rtrim((string) config('notifications.telegram.api_base_url', 'https://api.telegram.org'), '/');
        $payload = [
            'chat_id' => $message->chatId,
            'text' => $message->text,
            'parse_mode' => 'HTML',
            'link_preview_options' => ['is_disabled' => true],
        ];

        if ($message->replyMarkup !== null) {
            $payload['reply_markup'] = $message->replyMarkup;
        }

        try {
            $response = Http::baseUrl("{$baseUrl}/bot{$token}")
                ->acceptJson()
                ->asJson()
                ->connectTimeout(max(1, (int) config('notifications.telegram.connect_timeout_seconds', 5)))
                ->timeout(max(1, (int) config('notifications.telegram.timeout_seconds', 10)))
                ->post('sendMessage', $payload);
        } catch (ConnectionException $exception) {
            throw NotificationDeliveryException::transient('Telegram connection failed.', previous: $exception);
        } catch (Throwable $exception) {
            throw NotificationDeliveryException::transient('Telegram request failed unexpectedly.', previous: $exception);
        }

        return $this->messageId($response);
    }

    private function messageId(Response $response): string
    {
        $body = $response->json();
        $body = is_array($body) ? $body : [];

        if ($response->successful() && ($body['ok'] ?? false) === true) {
            $messageId = data_get($body, 'result.message_id');

            return is_scalar($messageId) ? (string) $messageId : '';
        }

        $status = $response->status();
        $errorCode = (int) ($body['error_code'] ?? $status);
        $description = mb_substr(trim((string) ($body['description'] ?? 'Telegram API rejected the message.')), 0, 500);
        $retryAfter = data_get($body, 'parameters.retry_after');
        $retryAfter = is_numeric($retryAfter) ? max(1, (int) $retryAfter) : null;

        if ($status === 429 || $errorCode === 429 || $status >= 500) {
            throw NotificationDeliveryException::transient(
                "Telegram API temporary error {$errorCode}: {$description}",
                $retryAfter,
            );
        }

        throw NotificationDeliveryException::permanent("Telegram API error {$errorCode}: {$description}");
    }
}
