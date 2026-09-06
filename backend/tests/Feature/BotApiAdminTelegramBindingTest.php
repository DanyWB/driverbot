<?php

namespace Tests\Feature;

use App\Domain\Administration\Services\AdminTelegramBindingService;
use App\Models\AdminTelegramBinding;
use App\Models\AdminTelegramBindingCode;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

class BotApiAdminTelegramBindingTest extends BotApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.key', 'base64:'.base64_encode(str_repeat('b', 32)));
        config()->set('notifications.telegram.binding_code_ttl_minutes', 10);
        config()->set('bot_api.binding_rate_limit_per_minute', 10);
        config()->set('bot_api.binding_revoke_service_rate_limit_per_minute', 120);
        config()->set('bot_api.binding_revoke_rate_limit_per_minute', 30);
        RateLimiter::clear("service:{$this->serviceClient->id}:admin-binding-revoke:service");
    }

    public function test_valid_code_connects_private_telegram_account_and_is_idempotent(): void
    {
        $admin = User::factory()->create(['name' => 'Operations Admin']);
        $issued = app(AdminTelegramBindingService::class)->issueCode($admin);
        $payload = $this->bindingPayload($issued->code, '800001');
        $headers = $this->apiHeaders('800001', 'bind-800001');

        $this->withHeaders($headers)
            ->postJson('/api/v1/bot/admin-telegram-bindings', $payload)
            ->assertOk()
            ->assertJsonPath('data.connected', true)
            ->assertJsonPath('data.admin_name', 'Operations Admin')
            ->assertJsonPath('data.telegram_id', '800001')
            ->assertJsonPath('data.username', 'bound_admin')
            ->assertJsonPath('data.generation', 1)
            ->assertJsonPath('meta.idempotency_replayed', false);

        $this->withHeaders($headers)
            ->postJson('/api/v1/bot/admin-telegram-bindings', $payload)
            ->assertOk()
            ->assertJsonPath('data.generation', 1)
            ->assertJsonPath('meta.idempotency_replayed', true);

        $binding = AdminTelegramBinding::query()->sole();
        $this->assertSame($admin->id, $binding->admin_user_id);
        $this->assertSame('800001', $binding->telegram_user_id);
        $this->assertSame('800001', $binding->telegram_chat_id);
        $this->assertSame('ru', $binding->locale);
        $this->assertSame(1, $binding->generation);
        $this->assertNotNull(AdminTelegramBindingCode::query()->sole()->consumed_at);
        $this->assertDatabaseHas('audit_logs', [
            'actor_service_client_id' => $this->serviceClient->id,
            'subject_id' => (string) $binding->id,
            'action' => 'admin.telegram_binding_connected',
        ]);
        $this->assertDatabaseCount('customers', 0);
    }

    public function test_invalid_expired_used_and_ineligible_codes_share_one_safe_error(): void
    {
        $this->withHeaders($this->apiHeaders('800010', 'missing-code'))
            ->postJson('/api/v1/bot/admin-telegram-bindings', $this->bindingPayload('AAAA-AAAA', '800010'))
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'TELEGRAM_BINDING_CODE_INVALID');

        $expiredAdmin = User::factory()->create();
        $expired = app(AdminTelegramBindingService::class)->issueCode($expiredAdmin);
        AdminTelegramBindingCode::query()->where('admin_user_id', $expiredAdmin->id)->update([
            'expires_at' => now()->subSecond(),
        ]);
        $this->withHeaders($this->apiHeaders('800011', 'expired-code'))
            ->postJson('/api/v1/bot/admin-telegram-bindings', $this->bindingPayload($expired->code, '800011'))
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'TELEGRAM_BINDING_CODE_INVALID');

        $usedAdmin = User::factory()->create();
        $used = app(AdminTelegramBindingService::class)->issueCode($usedAdmin);
        $payload = $this->bindingPayload($used->code, '800012');
        $this->withHeaders($this->apiHeaders('800012', 'used-first'))
            ->postJson('/api/v1/bot/admin-telegram-bindings', $payload)
            ->assertOk();
        $this->withHeaders($this->apiHeaders('800012', 'used-second'))
            ->postJson('/api/v1/bot/admin-telegram-bindings', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'TELEGRAM_BINDING_CODE_INVALID');

        $inactiveAdmin = User::factory()->create();
        $inactive = app(AdminTelegramBindingService::class)->issueCode($inactiveAdmin);
        $inactiveAdmin->forceFill(['is_active' => false])->save();
        $this->withHeaders($this->apiHeaders('800013', 'inactive-code'))
            ->postJson('/api/v1/bot/admin-telegram-bindings', $this->bindingPayload($inactive->code, '800013'))
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'TELEGRAM_BINDING_CODE_INVALID');
    }

    public function test_telegram_identity_can_belong_to_only_one_administrator(): void
    {
        $firstAdmin = User::factory()->create();
        $secondAdmin = User::factory()->create();
        $first = app(AdminTelegramBindingService::class)->issueCode($firstAdmin);
        $second = app(AdminTelegramBindingService::class)->issueCode($secondAdmin);

        $this->withHeaders($this->apiHeaders('800020', 'bind-first-admin'))
            ->postJson('/api/v1/bot/admin-telegram-bindings', $this->bindingPayload($first->code, '800020'))
            ->assertOk();
        $this->withHeaders($this->apiHeaders('800020', 'bind-second-admin'))
            ->postJson('/api/v1/bot/admin-telegram-bindings', $this->bindingPayload($second->code, '800020'))
            ->assertConflict()
            ->assertJsonPath('error.code', 'TELEGRAM_ACCOUNT_ALREADY_BOUND');

        $this->assertDatabaseCount('admin_telegram_bindings', 1);
        $this->assertNull(AdminTelegramBindingCode::query()
            ->where('admin_user_id', $secondAdmin->id)
            ->sole()
            ->consumed_at);
    }

    public function test_rebinding_the_same_administrator_replaces_identity_and_increments_generation(): void
    {
        $admin = User::factory()->create();
        $service = app(AdminTelegramBindingService::class);
        $first = $service->issueCode($admin);

        $this->withHeaders($this->apiHeaders('800030', 'first-generation'))
            ->postJson('/api/v1/bot/admin-telegram-bindings', $this->bindingPayload($first->code, '800030'))
            ->assertOk()
            ->assertJsonPath('data.generation', 1);

        $second = $service->issueCode($admin);
        $this->withHeaders($this->apiHeaders('800031', 'second-generation'))
            ->postJson('/api/v1/bot/admin-telegram-bindings', $this->bindingPayload($second->code, '800031', [
                'locale' => 'uk-UA',
            ]))
            ->assertOk()
            ->assertJsonPath('data.generation', 2);

        $binding = AdminTelegramBinding::query()->sole();
        $this->assertSame('800031', $binding->telegram_user_id);
        $this->assertSame('ua', $binding->locale);
        $this->assertSame(2, $binding->generation);
        $this->assertDatabaseMissing('admin_telegram_bindings', ['telegram_user_id' => '800030']);
    }

    public function test_disconnect_releases_the_identity_and_keeps_the_administrators_generation_tombstone(): void
    {
        $firstAdmin = User::factory()->create();
        $secondAdmin = User::factory()->create();
        $service = app(AdminTelegramBindingService::class);
        $firstCode = $service->issueCode($firstAdmin);

        $this->withHeaders($this->apiHeaders('800035', 'bind-before-disconnect'))
            ->postJson('/api/v1/bot/admin-telegram-bindings', $this->bindingPayload($firstCode->code, '800035'))
            ->assertOk();

        $firstBinding = AdminTelegramBinding::query()
            ->where('admin_user_id', $firstAdmin->id)
            ->sole();
        $service->disconnect($firstAdmin);
        $firstBinding->refresh();

        $this->assertSame(2, $firstBinding->generation);
        $this->assertNull($firstBinding->telegram_user_id);
        $this->assertNull($firstBinding->telegram_chat_id);
        $this->assertNotNull($firstBinding->disconnected_at);

        $secondCode = $service->issueCode($secondAdmin);
        $this->withHeaders($this->apiHeaders('800035', 'claim-after-disconnect'))
            ->postJson('/api/v1/bot/admin-telegram-bindings', $this->bindingPayload($secondCode->code, '800035'))
            ->assertOk()
            ->assertJsonPath('data.generation', 1);

        $this->assertDatabaseCount('admin_telegram_bindings', 2);
        $this->assertDatabaseHas('admin_telegram_bindings', [
            'admin_user_id' => $secondAdmin->id,
            'telegram_user_id' => '800035',
            'telegram_chat_id' => '800035',
            'generation' => 1,
        ]);
    }

    public function test_binding_requires_header_identity_matching_a_private_chat(): void
    {
        $admin = User::factory()->create();
        $issued = app(AdminTelegramBindingService::class)->issueCode($admin);

        $this->withHeaders($this->apiHeaders(idempotencyKey: 'missing-header'))
            ->postJson('/api/v1/bot/admin-telegram-bindings', $this->bindingPayload($issued->code, '800040'))
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonValidationErrors(['telegram_id'], 'error.fields');

        $this->withHeaders($this->apiHeaders('800041', 'mismatched-chat'))
            ->postJson('/api/v1/bot/admin-telegram-bindings', $this->bindingPayload($issued->code, '800042'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['chat_id'], 'error.fields');

        $this->withHeaders($this->apiHeaders('800043', 'group-chat'))
            ->postJson('/api/v1/bot/admin-telegram-bindings', $this->bindingPayload($issued->code, '800043', [
                'chat_type' => 'group',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['chat_type'], 'error.fields');

        $this->assertDatabaseCount('admin_telegram_bindings', 0);
    }

    public function test_binding_endpoint_has_a_dedicated_per_telegram_rate_limit(): void
    {
        config()->set('bot_api.binding_rate_limit_per_minute', 1);
        RateLimiter::clear("service:{$this->serviceClient->id}:admin-binding:telegram:800050");
        $payload = $this->bindingPayload('AAAA-AAAA', '800050');

        $this->withHeaders($this->apiHeaders('800050', 'rate-one'))
            ->postJson('/api/v1/bot/admin-telegram-bindings', $payload)
            ->assertUnprocessable();
        $this->withHeaders($this->apiHeaders('800050', 'rate-two'))
            ->postJson('/api/v1/bot/admin-telegram-bindings', $payload)
            ->assertTooManyRequests()
            ->assertJsonPath('error.code', 'RATE_LIMIT_EXCEEDED');
    }

    public function test_exposed_code_revocation_has_an_independent_rate_limit_bucket(): void
    {
        config()->set('bot_api.binding_rate_limit_per_minute', 1);
        config()->set('bot_api.binding_revoke_rate_limit_per_minute', 1);
        $bindingKey = "service:{$this->serviceClient->id}:admin-binding:telegram:800055";
        $revokeKey = "service:{$this->serviceClient->id}:admin-binding-revoke:telegram:800055";
        RateLimiter::clear($bindingKey);
        RateLimiter::clear($revokeKey);

        $this->withHeaders($this->apiHeaders('800055', 'rate-bind-one'))
            ->postJson('/api/v1/bot/admin-telegram-bindings', $this->bindingPayload('AAAA-AAAA', '800055'))
            ->assertUnprocessable();
        $this->withHeaders($this->apiHeaders('800055', 'rate-bind-two'))
            ->postJson('/api/v1/bot/admin-telegram-bindings', $this->bindingPayload('AAAA-AAAA', '800055'))
            ->assertTooManyRequests();

        $admin = User::factory()->create();
        $issued = app(AdminTelegramBindingService::class)->issueCode($admin);
        $this->withHeaders($this->apiHeaders('800055', 'rate-revoke-one'))
            ->postJson('/api/v1/bot/admin-telegram-binding-codes/revoke', ['code' => $issued->code])
            ->assertOk();
        $this->withHeaders($this->apiHeaders('800055', 'rate-revoke-two'))
            ->postJson('/api/v1/bot/admin-telegram-binding-codes/revoke', ['code' => $issued->code])
            ->assertTooManyRequests()
            ->assertJsonPath('error.code', 'RATE_LIMIT_EXCEEDED');
    }

    public function test_exposed_code_revocation_is_not_blocked_by_the_general_bot_api_bucket(): void
    {
        config()->set('bot_api.service_rate_limit_per_minute', 1);
        config()->set('bot_api.user_rate_limit_per_minute', 1);
        config()->set('bot_api.binding_revoke_service_rate_limit_per_minute', 10);
        config()->set('bot_api.binding_revoke_rate_limit_per_minute', 10);
        RateLimiter::clear("service:{$this->serviceClient->id}");
        RateLimiter::clear("service:{$this->serviceClient->id}:telegram:800056");
        RateLimiter::clear("service:{$this->serviceClient->id}:admin-binding-revoke:telegram:800056");

        $admin = User::factory()->create();
        $issued = app(AdminTelegramBindingService::class)->issueCode($admin);
        $this->withHeaders($this->apiHeaders('800056', 'exhaust-general-bucket'))
            ->postJson('/api/v1/bot/admin-telegram-bindings', $this->bindingPayload('AAAA-AAAA', '800056'))
            ->assertUnprocessable();
        $this->withHeaders($this->apiHeaders('800056', 'general-bucket-is-exhausted'))
            ->postJson('/api/v1/bot/admin-telegram-bindings', $this->bindingPayload('AAAA-AAAA', '800056'))
            ->assertTooManyRequests();

        $this->withHeaders($this->apiHeaders('800056', 'revoke-outside-general-bucket'))
            ->postJson('/api/v1/bot/admin-telegram-binding-codes/revoke', ['code' => $issued->code])
            ->assertOk()
            ->assertJsonPath('data.revoked', true);
        $this->assertNotNull(AdminTelegramBindingCode::query()
            ->where('admin_user_id', $admin->id)
            ->sole()
            ->revoked_at);
    }

    public function test_bot_can_revoke_a_code_exposed_outside_a_private_chat_without_an_oracle(): void
    {
        $admin = User::factory()->create();
        $issued = app(AdminTelegramBindingService::class)->issueCode($admin);
        $headers = $this->apiHeaders('800060', 'revoke-exposed-code');

        $this->withHeaders($headers)
            ->postJson('/api/v1/bot/admin-telegram-binding-codes/revoke', ['code' => $issued->code])
            ->assertOk()
            ->assertJsonPath('data.revoked', true)
            ->assertJsonPath('meta.idempotency_replayed', false);
        $this->withHeaders($headers)
            ->postJson('/api/v1/bot/admin-telegram-binding-codes/revoke', ['code' => $issued->code])
            ->assertOk()
            ->assertJsonPath('data.revoked', true)
            ->assertJsonPath('meta.idempotency_replayed', true);

        $this->assertNotNull(AdminTelegramBindingCode::query()->sole()->revoked_at);
        $this->assertDatabaseHas('audit_logs', [
            'actor_service_client_id' => $this->serviceClient->id,
            'action' => 'admin.telegram_binding_code_revoked_exposed',
        ]);
        $this->withHeaders($this->apiHeaders('800060', 'bind-revoked-code'))
            ->postJson('/api/v1/bot/admin-telegram-bindings', $this->bindingPayload($issued->code, '800060'))
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'TELEGRAM_BINDING_CODE_INVALID');

        $this->withHeaders($this->apiHeaders('800060', 'revoke-unknown-code'))
            ->postJson('/api/v1/bot/admin-telegram-binding-codes/revoke', ['code' => 'AAAA-AAAA'])
            ->assertOk()
            ->assertJsonPath('data.revoked', true);
    }

    public function test_revoking_an_already_consumed_code_does_not_hide_or_replace_the_existing_binding(): void
    {
        $admin = User::factory()->create();
        $issued = app(AdminTelegramBindingService::class)->issueCode($admin);

        $this->withHeaders($this->apiHeaders('800061', 'consume-before-exposed-revoke'))
            ->postJson('/api/v1/bot/admin-telegram-bindings', $this->bindingPayload($issued->code, '800061'))
            ->assertOk();
        $this->withHeaders($this->apiHeaders('800062', 'revoke-after-consume'))
            ->postJson('/api/v1/bot/admin-telegram-binding-codes/revoke', ['code' => $issued->code])
            ->assertOk()
            ->assertJsonPath('data.revoked', true);

        $binding = AdminTelegramBinding::query()->sole();
        $code = AdminTelegramBindingCode::query()->sole();
        $this->assertSame($admin->id, $binding->admin_user_id);
        $this->assertSame('800061', $binding->telegram_user_id);
        $this->assertNotNull($code->consumed_at);
        $this->assertNull($code->revoked_at);
    }

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function bindingPayload(string $code, string $chatId, array $overrides = []): array
    {
        return [
            'code' => $code,
            'chat_id' => $chatId,
            'chat_type' => 'private',
            'username' => '@bound_admin',
            'first_name' => 'Bound',
            'last_name' => 'Admin',
            'locale' => 'ru-RU',
            ...$overrides,
        ];
    }
}
