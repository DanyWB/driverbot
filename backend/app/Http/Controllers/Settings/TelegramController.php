<?php

namespace App\Http\Controllers\Settings;

use App\Domain\Administration\Services\AdminTelegramBindingService;
use App\Domain\Notifications\Data\RenderedTelegramMessage;
use App\Domain\Notifications\Exceptions\NotificationDeliveryException;
use App\Domain\Notifications\Services\TelegramClient;
use App\Domain\Shared\Services\AdminAuditService;
use App\Http\Controllers\Concerns\HandlesAdminContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\TelegramBindingActionRequest;
use App\Models\AdminTelegramBinding;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TelegramController extends Controller
{
    use HandlesAdminContext;

    public function edit(Request $request, AdminTelegramBindingService $bindings): Response
    {
        $admin = $this->admin($request);
        $binding = $bindings->connectedBinding($admin);
        $pendingCode = $request->session()->get('telegram_binding_code');
        $botUsername = ltrim(trim((string) config('notifications.telegram.bot_username')), '@');
        $legacyChatId = trim((string) config('notifications.telegram.admin_chat_id'));
        $legacyFallbackActive = ! AdminTelegramBinding::query()->exists()
            && preg_match('/^-?[1-9][0-9]{4,19}$/', $legacyChatId) === 1;
        $activeRecipientCount = AdminTelegramBinding::query()
            ->connected()
            ->whereHas('admin', fn ($query) => $query
                ->where('is_active', true)
                ->whereNotNull('email_verified_at'))
            ->count();

        return Inertia::render('settings/Telegram', [
            'binding' => $binding instanceof AdminTelegramBinding
                ? $this->presentBinding($binding)
                : null,
            'pendingCode' => is_array($pendingCode) ? $pendingCode : null,
            'botUsername' => preg_match('/^[A-Za-z0-9_]{5,32}$/', $botUsername) === 1
                ? $botUsername
                : null,
            'serverNow' => now()->toIso8601String(),
            'legacyFallbackActive' => $legacyFallbackActive,
            'activeRecipientCount' => $activeRecipientCount + (int) $legacyFallbackActive,
        ]);
    }

    public function issueCode(
        TelegramBindingActionRequest $request,
        AdminTelegramBindingService $bindings,
        AdminAuditService $audit,
    ): RedirectResponse {
        $admin = $this->admin($request);
        $issued = DB::transaction(function () use ($request, $bindings, $audit, $admin) {
            $issued = $bindings->issueCode($admin);
            $audit->record(
                $admin,
                $admin,
                'admin.telegram_binding_code_created',
                null,
                ['expires_at' => $issued->expiresAt->format(DATE_ATOM)],
                $this->requestId($request),
                $request->ip(),
                $this->userAgent($request),
            );

            return $issued;
        }, 3);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Telegram binding code created.']);

        return to_route('telegram.edit')->with('telegram_binding_code', [
            'code' => $issued->code,
            'command' => '/bind '.$issued->code,
            'expiresAt' => $issued->expiresAt->format(DATE_ATOM),
        ]);
    }

    public function test(
        TelegramBindingActionRequest $request,
        AdminTelegramBindingService $bindings,
        TelegramClient $telegram,
        AdminAuditService $audit,
    ): RedirectResponse {
        $admin = $this->admin($request);
        $binding = $bindings->connectedBinding($admin);

        if (! $binding instanceof AdminTelegramBinding) {
            throw ValidationException::withMessages([
                'telegram' => 'Connect a Telegram account before sending a test message.',
            ]);
        }

        try {
            $messageId = $telegram->send(new RenderedTelegramMessage(
                (string) $binding->telegram_chat_id,
                $this->testMessage((string) $binding->locale),
                null,
            ));
        } catch (NotificationDeliveryException) {
            throw ValidationException::withMessages([
                'telegram' => 'The test message could not be delivered. Check the bot connection and try again.',
            ]);
        }

        DB::transaction(function () use ($request, $binding, $admin, $audit, $messageId): void {
            $updated = AdminTelegramBinding::query()
                ->whereKey($binding->id)
                ->where('generation', $binding->generation)
                ->whereNull('disconnected_at')
                ->update(['last_tested_at' => now(), 'updated_at' => now()]);

            if ($updated !== 1) {
                return;
            }

            $binding->refresh();
            $audit->record(
                $admin,
                $binding,
                'admin.telegram_test_sent',
                null,
                [
                    'generation' => (int) $binding->generation,
                    'provider_message_id' => $messageId !== '' ? $messageId : null,
                ],
                $this->requestId($request),
                $request->ip(),
                $this->userAgent($request),
            );
        }, 3);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Telegram test message sent.']);

        return to_route('telegram.edit');
    }

    public function destroy(
        TelegramBindingActionRequest $request,
        AdminTelegramBindingService $bindings,
        AdminAuditService $audit,
    ): RedirectResponse {
        $admin = $this->admin($request);

        DB::transaction(function () use ($request, $bindings, $audit, $admin): void {
            $mutation = $bindings->disconnect($admin);

            if ($mutation === null) {
                return;
            }

            $audit->record(
                $admin,
                $mutation->binding,
                'admin.telegram_binding_disconnected',
                $mutation->oldValues,
                $bindings->snapshot($mutation->binding),
                $this->requestId($request),
                $request->ip(),
                $this->userAgent($request),
            );
        }, 3);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Telegram account disconnected.']);

        return to_route('telegram.edit');
    }

    /** @return array<string, mixed> */
    private function presentBinding(AdminTelegramBinding $binding): array
    {
        return [
            'telegramUserId' => (string) $binding->telegram_user_id,
            'telegramChatId' => (string) $binding->telegram_chat_id,
            'username' => $binding->username,
            'firstName' => $binding->first_name,
            'lastName' => $binding->last_name,
            'locale' => $binding->locale,
            'generation' => (int) $binding->generation,
            'connectedAt' => $binding->connected_at?->toIso8601String(),
            'lastTestedAt' => $binding->last_tested_at?->toIso8601String(),
            'notificationsEnabled' => true,
        ];
    }

    private function testMessage(string $locale): string
    {
        return match ($locale) {
            'en' => '✅ Drive Phangan administrator notifications are connected.',
            'ua' => '✅ Сповіщення адміністратора Drive Phangan підключено.',
            default => '✅ Уведомления администратора Drive Phangan подключены.',
        };
    }
}
