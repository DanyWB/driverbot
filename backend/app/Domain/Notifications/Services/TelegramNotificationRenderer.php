<?php

namespace App\Domain\Notifications\Services;

use App\Domain\Bookings\Enums\BookingStatus;
use App\Domain\Customers\Enums\ContactType;
use App\Domain\Notifications\Data\RenderedTelegramMessage;
use App\Domain\Notifications\Exceptions\NotificationDeliveryException;
use App\Domain\Notifications\Exceptions\NotificationNotApplicableException;
use App\Models\Booking;
use App\Models\BookingPriceSnapshot;
use App\Models\CustomerContact;
use App\Models\NotificationOutbox;
use DateTimeInterface;
use Illuminate\Support\Facades\Lang;

class TelegramNotificationRenderer
{
    public function render(NotificationOutbox $notification): RenderedTelegramMessage
    {
        $payload = $this->payload($notification);
        $publicId = trim((string) ($payload['booking_public_id'] ?? ''));

        if ($publicId === '') {
            throw NotificationDeliveryException::permanent('Notification payload has no booking_public_id.');
        }

        $booking = Booking::query()
            ->with(['customer.contacts', 'vehicle', 'latestPriceSnapshot'])
            ->where('public_id', $publicId)
            ->first();

        if (! $booking instanceof Booking) {
            throw new NotificationNotApplicableException('The booking no longer exists.');
        }

        if ($this->isReminder($notification)) {
            $this->assertCurrentReminder($booking, $payload);
        }

        $admin = $notification->channel === 'internal' && $notification->recipient === 'admin';
        $locale = $admin
            ? $this->locale(config('notifications.telegram.admin_locale', 'ru'))
            : $this->locale($booking->customer->locale);
        $chatId = $admin
            ? trim((string) config('notifications.telegram.admin_chat_id'))
            : trim((string) $notification->recipient);

        if ($chatId === '') {
            throw NotificationDeliveryException::permanent($admin
                ? 'TELEGRAM_ADMIN_CHAT_ID is not configured.'
                : 'Telegram recipient is empty.');
        }

        $reason = $this->cancellationReason($booking, $payload);
        $replacements = $this->replacements($booking, $payload, $reason);
        $template = $this->template($notification, $admin, $reason);
        $text = Lang::get($template, $replacements, $locale);

        if (! is_string($text) || $text === $template) {
            throw NotificationDeliveryException::permanent("Notification template [{$template}] is missing.");
        }

        return new RenderedTelegramMessage(
            $chatId,
            $this->telegramText($text),
            $admin ? $this->adminKeyboard($booking, $locale) : $this->customerKeyboard($booking, $notification, $locale),
        );
    }

    /** @return array<string, mixed> */
    private function payload(NotificationOutbox $notification): array
    {
        $payload = $notification->getAttribute('payload');

        return is_array($payload) ? $payload : [];
    }

    private function template(NotificationOutbox $notification, bool $admin, ?string $reason): string
    {
        if ($admin) {
            return match ($notification->event_type) {
                'booking.pending' => 'notifications.admin.pending',
                'booking.cancelled_by_client' => 'notifications.admin.cancelled_by_client',
                'booking.expired' => 'notifications.admin.expired',
                default => throw NotificationDeliveryException::permanent(
                    "Unsupported admin notification event [{$notification->event_type}].",
                ),
            };
        }

        return match ($notification->event_type) {
            'booking.pending' => 'notifications.customer.pending',
            'booking.approved' => 'notifications.customer.approved',
            'booking.cancelled' => $reason === null
                ? 'notifications.customer.cancelled'
                : 'notifications.customer.cancelled_with_reason',
            'booking.expired' => 'notifications.customer.expired',
            'booking.dates_changed' => 'notifications.customer.dates_changed',
            'booking.price_changed' => 'notifications.customer.price_changed',
            'booking.reminder.pickup_day' => 'notifications.customer.reminder_pickup_day',
            'booking.reminder.pickup_one_hour' => 'notifications.customer.reminder_pickup_one_hour',
            default => throw NotificationDeliveryException::permanent(
                "Unsupported customer notification event [{$notification->event_type}].",
            ),
        };
    }

    /** @param array<string, mixed> $payload */
    private function assertCurrentReminder(Booking $booking, array $payload): void
    {
        $expectedVersion = (int) ($payload['reminder_version'] ?? -1);
        $expectedDate = trim((string) ($payload['starts_on'] ?? ''));
        $expectedTime = $this->normalizeTime($payload['pickup_time'] ?? null);
        $actualTime = $this->normalizeTime($booking->getRawOriginal('pickup_time'));

        if ($booking->bookingStatus() !== BookingStatus::Approved
            || (int) $booking->reminder_version !== $expectedVersion
            || $this->dateValue($booking, 'starts_on') !== $expectedDate
            || $actualTime !== $expectedTime) {
            throw new NotificationNotApplicableException('The booking reminder was superseded.');
        }
    }

    /** @param array<string, mixed> $payload
     * @return array<string, string>
     */
    private function replacements(Booking $booking, array $payload, ?string $reason): array
    {
        $snapshot = $booking->latestPriceSnapshot;
        $finalTotal = is_numeric($payload['final_total'] ?? null)
            ? (int) $payload['final_total']
            : ($snapshot instanceof BookingPriceSnapshot ? (int) $snapshot->final_total : null);
        $currency = trim((string) ($payload['currency'] ?? ($snapshot instanceof BookingPriceSnapshot ? $snapshot->currency : 'THB')));
        $oldDates = is_array($payload['old_dates'] ?? null) ? $payload['old_dates'] : [];
        $newDates = is_array($payload['new_dates'] ?? null) ? $payload['new_dates'] : [];

        return array_map($this->escape(...), [
            'booking' => '#'.$this->shortBookingId((string) $booking->public_id),
            'customer' => $this->preview((string) $booking->customer->name, 120),
            'phone' => $this->preview($this->contact($booking, ContactType::Phone) ?? '-', 120),
            'telegram' => $this->preview($this->contact($booking, ContactType::TelegramUsername) ?? '-', 120),
            'vehicle' => $this->preview((string) $booking->vehicle->name, 160),
            'period' => $this->period($booking),
            'old_period' => $this->periodValues($oldDates),
            'new_period' => $this->periodValues($newDates),
            'price' => $finalTotal === null ? '-' : number_format($finalTotal, 0, '.', ' ').' '.($currency !== '' ? $currency : 'THB'),
            'comment' => $this->preview((string) ($booking->client_comment ?: '-'), 500),
            'delivery' => $booking->delivery_required ? 'yes' : 'no',
            'address' => $this->preview((string) ($booking->delivery_address ?: '-'), 300),
            'helmets' => (string) $booking->helmets_quantity,
            'reason' => $reason ?? '',
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function cancellationReason(Booking $booking, array $payload): ?string
    {
        $reason = $booking->getAttribute('cancellation_reason');

        if (! is_string($reason) || trim($reason) === '') {
            $reason = $payload['reason'] ?? null;
        }

        if (! is_string($reason) || trim($reason) === '') {
            return null;
        }

        return $this->preview(trim($reason), 2000);
    }

    /** @return array<string, mixed> */
    private function adminKeyboard(Booking $booking, string $locale): array
    {
        return [
            'inline_keyboard' => [[[
                'text' => $this->button('notifications.buttons.open_admin', $locale),
                'url' => rtrim((string) config('app.url'), '/').'/bookings/'.$booking->public_id,
            ]]],
        ];
    }

    /** @return array<string, mixed> */
    private function customerKeyboard(Booking $booking, NotificationOutbox $notification, string $locale): array
    {
        if ($notification->event_type === 'booking.expired') {
            return [
                'inline_keyboard' => [[[
                    'text' => $this->button('notifications.buttons.create_booking', $locale),
                    'callback_data' => 'rent:book',
                ]]],
            ];
        }

        return [
            'inline_keyboard' => [[[
                'text' => $this->button('notifications.buttons.booking_details', $locale),
                'callback_data' => 'rent:details:'.$booking->public_id,
            ]]],
        ];
    }

    private function button(string $key, string $locale): string
    {
        $value = Lang::get($key, locale: $locale);

        return is_string($value) && $value !== $key ? $value : 'Open';
    }

    private function contact(Booking $booking, ContactType $type): ?string
    {
        $contact = $booking->customer->contacts
            ->first(fn (CustomerContact $contact): bool => $contact->getRawOriginal('type') === $type->value);

        return $contact instanceof CustomerContact ? (string) $contact->value : null;
    }

    private function period(Booking $booking): string
    {
        return $this->periodValues([
            'starts_on' => $this->dateValue($booking, 'starts_on'),
            'ends_on' => $this->dateValue($booking, 'ends_on'),
            'pickup_time' => $booking->getRawOriginal('pickup_time'),
            'return_time' => $booking->getRawOriginal('return_time'),
        ]);
    }

    /** @param array<string, mixed> $values */
    private function periodValues(array $values): string
    {
        $startsOn = substr((string) ($values['starts_on'] ?? ''), 0, 10);
        $endsOn = substr((string) ($values['ends_on'] ?? ''), 0, 10);

        if ($startsOn === '' || $endsOn === '') {
            return '-';
        }

        $start = $this->formatDate($startsOn, $this->normalizeTime($values['pickup_time'] ?? null));
        $end = $this->formatDate($endsOn, $this->normalizeTime($values['return_time'] ?? null));

        return "{$start} - {$end}";
    }

    private function formatDate(string $date, ?string $time): string
    {
        $value = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $formatted = $value instanceof \DateTimeImmutable ? $value->format('d.m.Y') : $date;

        return $time === null ? $formatted : $formatted.' '.substr($time, 0, 5);
    }

    private function dateValue(Booking $booking, string $attribute): string
    {
        $value = $booking->getAttribute($attribute);

        return $value instanceof DateTimeInterface
            ? $value->format('Y-m-d')
            : substr((string) $booking->getRawOriginal($attribute), 0, 10);
    }

    private function normalizeTime(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : substr($value, 0, 5);
    }

    private function isReminder(NotificationOutbox $notification): bool
    {
        return str_starts_with((string) $notification->event_type, 'booking.reminder.');
    }

    private function shortBookingId(string $publicId): string
    {
        return strtoupper(substr(str_replace('-', '', $publicId), -12));
    }

    private function preview(string $value, int $limit): string
    {
        $value = trim($value);

        return mb_strlen($value) <= $limit ? $value : mb_substr($value, 0, $limit - 1).'...';
    }

    private function escape(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function telegramText(string $html): string
    {
        if ($this->telegramVisibleLength($html) <= 4096) {
            return $html;
        }

        $tokens = preg_split(
            '/(<\/?[a-z][^>]*>|&(?:#[0-9]+|#x[0-9a-f]+|[a-z][a-z0-9]+);)/iu',
            $html,
            -1,
            PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY,
        );

        if (! is_array($tokens)) {
            throw NotificationDeliveryException::permanent('Rendered Telegram notification could not be truncated safely.');
        }

        $result = '';
        $visibleLength = 0;
        $contentLimit = 4095;
        $openTags = [];

        foreach ($tokens as $token) {
            if (str_starts_with($token, '<')) {
                $result .= $token;
                $this->trackTelegramTag($token, $openTags);

                continue;
            }

            $tokenLength = $this->telegramVisibleLength($token);

            if ($visibleLength + $tokenLength <= $contentLimit) {
                $result .= $token;
                $visibleLength += $tokenLength;

                continue;
            }

            $remaining = $contentLimit - $visibleLength;

            if ($remaining > 0 && ! str_starts_with($token, '&')) {
                $result .= mb_substr($token, 0, $remaining);
            }

            break;
        }

        $result .= '…';

        foreach (array_reverse($openTags) as $tag) {
            $result .= "</{$tag}>";
        }

        return $result;
    }

    private function telegramVisibleLength(string $html): int
    {
        return mb_strlen(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /** @param list<string> $openTags */
    private function trackTelegramTag(string $token, array &$openTags): void
    {
        if (preg_match('/^<\s*\/\s*([a-z0-9]+)/i', $token, $matches) === 1) {
            if ($openTags !== [] && end($openTags) === strtolower($matches[1])) {
                array_pop($openTags);
            }

            return;
        }

        if (str_ends_with(trim($token), '/>') || preg_match('/^<\s*([a-z0-9]+)/i', $token, $matches) !== 1) {
            return;
        }

        $openTags[] = strtolower($matches[1]);
    }

    private function locale(mixed $locale): string
    {
        $locale = strtolower(trim((string) $locale));

        return in_array($locale, ['ru', 'en', 'ua'], true) ? $locale : 'ru';
    }
}
