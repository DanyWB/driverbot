<?php

namespace Tests\Feature\Settings;

use App\Models\AdminTelegramBinding;
use App\Models\AdminTelegramBindingCode;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TelegramSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        config()->set('notifications.telegram.bot_username', '@DrivePhangan_TestBot');
        config()->set('notifications.telegram.admin_chat_id', '-100500');
        config()->set('notifications.telegram.binding_code_ttl_minutes', 10);
        config()->set('inertia.testing.ensure_pages_exist', false);

        $this->withoutVite();
    }

    public function test_page_requires_authentication_verification_activity_and_password_confirmation(): void
    {
        $this->get(route('telegram.edit'))->assertRedirect(route('login'));

        $unverified = User::factory()->unverified()->create();
        $this->actingAs($unverified)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('telegram.edit'))
            ->assertRedirect(route('verification.notice'));

        $inactive = User::factory()->create(['is_active' => false]);
        $this->actingAs($inactive)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('telegram.edit'))
            ->assertRedirect(route('login'));

        $admin = User::factory()->create();
        $this->actingAs($admin)
            ->get(route('telegram.edit'))
            ->assertRedirect(route('password.confirm'));
        $this->actingAs($admin)
            ->post(route('telegram.binding-code.store'))
            ->assertRedirect(route('password.confirm'));
    }

    public function test_page_exposes_only_the_current_binding_and_bootstrap_state(): void
    {
        $admin = User::factory()->create();
        $other = User::factory()->create();

        $this->confirmedInertia($admin)
            ->get(route('telegram.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/Telegram')
                ->where('binding', null)
                ->where('pendingCode', null)
                ->where('botUsername', 'DrivePhangan_TestBot')
                ->has('serverNow')
                ->where('legacyFallbackActive', true)
                ->where('activeRecipientCount', 1));

        $this->binding($other, '700001', generation: 2);

        $this->confirmedInertia($admin)
            ->get(route('telegram.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('binding', null)
                ->where('legacyFallbackActive', false)
                ->where('activeRecipientCount', 1));

        $own = $this->binding($admin, '700002', generation: 4);

        $this->confirmedInertia($admin)
            ->get(route('telegram.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('binding.telegramUserId', '700002')
                ->where('binding.telegramChatId', '700002')
                ->where('binding.username', 'admin_700002')
                ->where('binding.generation', 4)
                ->where('binding.notificationsEnabled', true)
                ->where('activeRecipientCount', 2));

        $this->assertSame($admin->id, $own->admin_user_id);
    }

    public function test_code_generation_stores_only_hmac_and_revokes_the_previous_code(): void
    {
        $admin = User::factory()->create();

        $firstResponse = $this->confirmed($admin)->post(route('telegram.binding-code.store'));
        $firstResponse->assertRedirect(route('telegram.edit'))
            ->assertSessionHas('telegram_binding_code');
        $firstPayload = session()->get('telegram_binding_code');
        $firstCode = $firstPayload['code'] ?? null;

        $this->assertIsString($firstCode);
        $this->assertMatchesRegularExpression('/^[23456789A-HJ-NP-Z]{4}-[23456789A-HJ-NP-Z]{4}$/', $firstCode);
        $this->assertSame('/bind '.$firstCode, $firstPayload['command']);
        $this->assertDatabaseCount('admin_telegram_binding_codes', 1);

        $firstRecord = AdminTelegramBindingCode::query()->sole();
        $this->assertNotSame($firstCode, $firstRecord->code_hash);
        $this->assertSame(64, strlen((string) $firstRecord->code_hash));
        $this->assertTrue($firstRecord->expires_at->between(now()->addMinutes(9), now()->addMinutes(11)));

        $secondResponse = $this->confirmed($admin)->post(route('telegram.binding-code.store'));
        $secondPayload = session()->get('telegram_binding_code');

        $this->assertNotSame($firstCode, $secondPayload['code'] ?? null);
        $this->assertNotNull($firstRecord->fresh()->revoked_at);
        $this->assertSame(1, AdminTelegramBindingCode::query()
            ->whereNull('consumed_at')
            ->whereNull('revoked_at')
            ->count());
        $this->assertDatabaseHas('audit_logs', [
            'actor_admin_id' => $admin->id,
            'action' => 'admin.telegram_binding_code_created',
        ]);
        $this->assertStringNotContainsString(
            $firstCode,
            (string) AuditLog::query()->get()->toJson(),
        );
    }

    public function test_disconnect_scrubs_identity_revokes_codes_and_increments_generation(): void
    {
        $admin = User::factory()->create();
        $binding = $this->binding($admin, '700010', generation: 3);
        AdminTelegramBindingCode::query()->create([
            'admin_user_id' => $admin->id,
            'code_hash' => str_repeat('a', 64),
            'expires_at' => now()->addMinutes(10),
        ]);

        $this->confirmed($admin)
            ->delete(route('telegram.binding.destroy'))
            ->assertRedirect(route('telegram.edit'))
            ->assertSessionHasNoErrors();

        $binding->refresh();
        $this->assertSame(4, $binding->generation);
        $this->assertNull($binding->telegram_user_id);
        $this->assertNull($binding->telegram_chat_id);
        $this->assertNull($binding->username);
        $this->assertNotNull($binding->disconnected_at);
        $this->assertNotNull(AdminTelegramBindingCode::query()->sole()->revoked_at);
        $this->assertDatabaseHas('audit_logs', [
            'actor_admin_id' => $admin->id,
            'subject_id' => (string) $binding->id,
            'action' => 'admin.telegram_binding_disconnected',
        ]);

        $this->confirmedInertia($admin)
            ->get(route('telegram.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('binding', null)
                ->where('legacyFallbackActive', false)
                ->where('activeRecipientCount', 0));
    }

    public function test_deactivation_or_lost_email_verification_invalidates_the_binding_generation(): void
    {
        foreach (['inactive', 'unverified'] as $scenario) {
            $admin = User::factory()->create();
            $binding = $this->binding($admin, $scenario === 'inactive' ? '700011' : '700012', generation: 5);

            $scenario === 'inactive'
                ? $admin->forceFill(['is_active' => false])->save()
                : $admin->forceFill(['email_verified_at' => null])->save();

            $binding->refresh();
            $this->assertSame(6, $binding->generation);
            $this->assertNull($binding->telegram_user_id);
            $this->assertNull($binding->telegram_chat_id);
            $this->assertNotNull($binding->disconnected_at);
        }
    }

    public function test_database_lifecycle_guard_scrubs_bindings_for_bulk_updates_and_deletes(): void
    {
        $deactivated = User::factory()->create();
        $deactivatedBinding = $this->binding($deactivated, '700013', generation: 2);
        $deactivatedCode = AdminTelegramBindingCode::query()->create([
            'admin_user_id' => $deactivated->id,
            'code_hash' => str_repeat('d', 64),
            'expires_at' => now()->addMinutes(10),
        ]);

        DB::table('admin_users')->where('id', $deactivated->id)->update([
            'is_active' => false,
            'updated_at' => now(),
        ]);

        $deactivatedBinding->refresh();
        $this->assertSame(3, $deactivatedBinding->generation);
        $this->assertNull($deactivatedBinding->telegram_user_id);
        $this->assertNull($deactivatedBinding->telegram_chat_id);
        $this->assertNull($deactivatedBinding->username);
        $this->assertNotNull($deactivatedBinding->disconnected_at);
        $this->assertNotNull($deactivatedCode->refresh()->revoked_at);

        $unverified = User::factory()->create();
        $unverifiedBinding = $this->binding($unverified, '700015', generation: 4);
        $unverifiedCode = AdminTelegramBindingCode::query()->create([
            'admin_user_id' => $unverified->id,
            'code_hash' => str_repeat('e', 64),
            'expires_at' => now()->addMinutes(10),
        ]);

        DB::table('admin_users')->where('id', $unverified->id)->update([
            'email_verified_at' => null,
            'updated_at' => now(),
        ]);

        $unverifiedBinding->refresh();
        $this->assertSame(5, $unverifiedBinding->generation);
        $this->assertNull($unverifiedBinding->telegram_user_id);
        $this->assertNull($unverifiedBinding->telegram_chat_id);
        $this->assertNotNull($unverifiedCode->refresh()->revoked_at);

        $deleted = User::factory()->create();
        $deletedBinding = $this->binding($deleted, '700014', generation: 7);
        AdminTelegramBindingCode::query()->create([
            'admin_user_id' => $deleted->id,
            'code_hash' => str_repeat('f', 64),
            'expires_at' => now()->addMinutes(10),
        ]);

        DB::table('admin_users')->where('id', $deleted->id)->delete();

        $deletedBinding->refresh();
        $this->assertNull($deletedBinding->admin_user_id);
        $this->assertSame(8, $deletedBinding->generation);
        $this->assertNull($deletedBinding->telegram_user_id);
        $this->assertNull($deletedBinding->telegram_chat_id);
        $this->assertNull($deletedBinding->first_name);
        $this->assertNotNull($deletedBinding->disconnected_at);
        $this->assertDatabaseMissing('admin_telegram_binding_codes', [
            'admin_user_id' => $deleted->id,
        ]);
    }

    public function test_test_message_targets_only_the_current_administrators_binding(): void
    {
        config()->set('notifications.telegram.bot_token', 'test-token');
        $admin = User::factory()->create();
        $other = User::factory()->create();
        $binding = $this->binding($admin, '700020', locale: 'ru');
        $this->binding($other, '700021', locale: 'en');
        Http::fake([
            'https://api.telegram.org/bottest-token/sendMessage' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 456],
            ]),
        ]);

        $this->confirmed($admin)
            ->post(route('telegram.test'))
            ->assertRedirect(route('telegram.edit'))
            ->assertSessionHasNoErrors();

        Http::assertSent(fn (HttpRequest $request): bool => $request['chat_id'] === '700020'
            && str_contains((string) $request['text'], 'Уведомления администратора'));
        Http::assertSentCount(1);
        $this->assertNotNull($binding->fresh()->last_tested_at);
        $this->assertDatabaseHas('audit_logs', [
            'actor_admin_id' => $admin->id,
            'action' => 'admin.telegram_test_sent',
        ]);
    }

    public function test_test_message_requires_a_current_binding(): void
    {
        $admin = User::factory()->create();
        Http::fake();

        $this->confirmed($admin)
            ->post(route('telegram.test'))
            ->assertSessionHasErrors('telegram');

        Http::assertNothingSent();
    }

    public function test_code_generation_is_rate_limited(): void
    {
        $admin = User::factory()->create();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->confirmed($admin)
                ->post(route('telegram.binding-code.store'))
                ->assertRedirect(route('telegram.edit'));
        }

        $this->confirmed($admin)
            ->post(route('telegram.binding-code.store'))
            ->assertTooManyRequests();
    }

    private function confirmed(User $admin): self
    {
        return $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()]);
    }

    private function confirmedInertia(User $admin): self
    {
        return $this->confirmed($admin);
    }

    private function binding(
        User $admin,
        string $telegramId,
        int $generation = 1,
        string $locale = 'ru',
    ): AdminTelegramBinding {
        return AdminTelegramBinding::query()->create([
            'admin_user_id' => $admin->id,
            'telegram_user_id' => $telegramId,
            'telegram_chat_id' => $telegramId,
            'username' => "admin_{$telegramId}",
            'first_name' => 'Admin',
            'locale' => $locale,
            'generation' => $generation,
            'connected_at' => now(),
        ]);
    }
}
