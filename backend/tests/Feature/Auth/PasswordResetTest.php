<?php

namespace Tests\Feature\Auth;

use App\Domain\Administration\Services\AdminTelegramBindingService;
use App\Domain\Administration\Services\TelegramPasswordRecovery;
use App\Jobs\SendTelegramPasswordRecovery;
use App\Models\AdminTelegramBinding;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://admin.drivephangan.test', 'notifications.telegram.bot_token' => '123456789:'.str_repeat('a', 35)]);
        Http::preventStrayRequests();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 10]])]);
        Notification::fake();
        Cache::flush();
    }

    public function test_reset_screen_has_private_non_cacheable_headers(): void
    {
        $this->get(route('password.request'))->assertOk()
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertInertia(fn (Assert $page) => $page->component('auth/ForgotPassword'));
    }

    public function test_known_unknown_and_unbound_accounts_receive_identical_response_and_only_queue_work(): void
    {
        Queue::fake();
        $bound = $this->admin();
        $unbound = User::factory()->create();
        foreach ([$bound->email, $unbound->email, 'absent@example.com'] as $email) {
            $this->post(route('password.email'), ['email' => $email])->assertSessionHasNoErrors()
                ->assertSessionHas('status', __('recovery.sent'));
        }
        Queue::assertPushed(SendTelegramPasswordRecovery::class, 3);
        Http::assertNothingSent();
        Notification::assertNothingSent();
    }

    public function test_link_goes_only_to_bound_private_chat_and_not_to_email_or_bootstrap_chat(): void
    {
        $admin = $this->admin();
        config(['notifications.telegram.admin_chat_id' => '999999']);
        $token = $this->link($admin);
        Http::assertSent(fn ($request) => $request['chat_id'] === '700301'
            && str_starts_with($request['reply_markup']['inline_keyboard'][0][0]['url'], 'https://admin.drivephangan.test/'));
        $this->assertMatchesRegularExpression('/^[a-zA-Z0-9_-]+$/', $token);
        $this->assertNotSame($token, DB::table('password_reset_tokens')->value('token'));
        Notification::assertNothingSent();
        $this->get(route('password.reset', ['token' => $token, 'email' => $admin->email]))->assertOk()
            ->assertHeader('Referrer-Policy', 'no-referrer');
    }

    public function test_valid_link_changes_password_once_without_login_or_removing_two_factor(): void
    {
        $admin = $this->admin();
        $admin->forceFill(['two_factor_secret' => encrypt('secret'), 'two_factor_confirmed_at' => now()])->save();
        $secret = $admin->two_factor_secret;
        $token = $this->link($admin);
        $this->submit($admin, $token)->assertSessionHasNoErrors()->assertRedirect(route('login'));
        $this->assertGuest();
        $admin->refresh();
        $this->assertTrue(Hash::check('NewPassword123!', $admin->password));
        $this->assertSame(1, $admin->session_version);
        $this->assertSame($secret, $admin->two_factor_secret);
        $this->assertDatabaseCount('password_reset_tokens', 0);
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.password_reset_via_telegram', 'subject_id' => $admin->id]);
        $this->submit($admin, $token)->assertSessionHasErrors('email');
    }

    public function test_expiry_at_fifteen_minutes_is_rejected(): void
    {
        $admin = $this->admin();
        $token = $this->link($admin);
        $this->travel(15)->minutes();
        $this->submit($admin, $token)->assertSessionHasErrors('email');
        $this->assertTrue(Hash::check('password', $admin->fresh()->password));
    }

    public function test_link_before_expiry_is_valid(): void
    {
        $admin = $this->admin();
        $token = $this->link($admin);
        $this->travel(14)->minutes();
        $this->submit($admin, $token)->assertSessionHasNoErrors();
    }

    public function test_binding_disconnect_or_replacement_invalidates_old_link(): void
    {
        $admin = $this->admin();
        $token = $this->link($admin);
        $admin->telegramBinding->increment('generation');
        $this->submit($admin, $token)->assertSessionHasErrors('email');
        $this->travel(61)->seconds();
        $token = $this->link($admin);
        app(AdminTelegramBindingService::class)->disconnect($admin);
        $this->submit($admin, $token)->assertSessionHasErrors('email');
    }

    public function test_disabled_and_unverified_admins_cannot_receive_or_consume_links(): void
    {
        $admin = $this->admin();
        $token = $this->link($admin);
        $admin->forceFill(['is_active' => false])->save();
        $this->submit($admin, $token)->assertSessionHasErrors('email');
        $this->travel(61)->seconds();
        app(TelegramPasswordRecovery::class)->deliver($admin->email, now()->getTimestamp());
        Http::assertSentCount(1);
        $admin->forceFill(['is_active' => true, 'email_verified_at' => null])->save();
        app(TelegramPasswordRecovery::class)->deliver($admin->email, now()->getTimestamp());
        Http::assertSentCount(1);
    }

    public function test_missing_binding_group_chat_and_expired_queue_request_send_nothing(): void
    {
        $unbound = User::factory()->create();
        app(TelegramPasswordRecovery::class)->deliver($unbound->email, now()->getTimestamp());
        $admin = $this->admin();
        app(TelegramPasswordRecovery::class)->deliver($admin->email, now()->subMinutes(6)->getTimestamp());
        $admin->telegramBinding->update(['telegram_chat_id' => '-10012345']);
        app(TelegramPasswordRecovery::class)->deliver($admin->email, now()->getTimestamp());
        Http::assertNothingSent();
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_password_change_and_email_change_invalidate_existing_link(): void
    {
        $admin = $this->admin();
        $token = $this->link($admin);
        $admin->forceFill(['password' => 'AnotherPassword123!'])->save();
        $this->submit($admin, $token)->assertSessionHasErrors('email');
        $this->travel(61)->seconds();
        $token = $this->link($admin);
        $admin->forceFill(['email' => 'updated@example.com'])->save();
        $this->submit($admin, $token)->assertSessionHasErrors('email');
    }

    public function test_wrong_user_tampered_and_legacy_email_tokens_are_rejected(): void
    {
        $admin = $this->admin();
        $other = User::factory()->create();
        $token = $this->link($admin);
        $this->submit($other, $token)->assertSessionHasErrors('email');
        $this->submit($admin, substr($token, 0, -3).'bad')->assertSessionHasErrors('email');
        $legacy = Password::broker()->createToken($admin);
        $this->submit($admin, $legacy)->assertSessionHasErrors('email');
        $this->assertTrue(Hash::check('password', $admin->fresh()->password));
    }

    public function test_delivery_failure_rolls_back_token_and_never_changes_password(): void
    {
        $admin = $this->admin();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Forbidden'], 403)]);
        app(TelegramPasswordRecovery::class)->deliver($admin->email, now()->getTimestamp());
        $this->assertDatabaseCount('password_reset_tokens', 0);
        $this->assertTrue(Hash::check('password', $admin->fresh()->password));
    }

    public function test_pending_two_factor_session_is_invalidated_by_password_recovery(): void
    {
        $admin = $this->admin();
        $admin->forceFill(['two_factor_secret' => encrypt('secret'), 'two_factor_confirmed_at' => now()])->save();
        $this->post(route('login.store'), ['email' => $admin->email, 'password' => 'password'])
            ->assertSessionHas('login.id', $admin->id)->assertSessionHas('admin_session_version', 0);
        $token = $this->link($admin);
        $this->assertSame(Password::PASSWORD_RESET, app(TelegramPasswordRecovery::class)->reset($this->input($admin, $token)));
        $this->get(route('two-factor.login'))->assertRedirect(route('login'))->assertSessionMissing('login.id');
        $this->post(route('login.store'), ['email' => $admin->email, 'password' => 'NewPassword123!'])
            ->assertSessionHas('login.id', $admin->id)->assertSessionHas('admin_session_version', 1);
    }

    public function test_malformed_inputs_are_validation_errors_not_server_errors(): void
    {
        $this->post(route('password.email'), ['email' => ['bad']])->assertSessionHasErrors('email');
        $this->post(route('password.update'), ['email' => ['bad'], 'token' => ['bad'], 'password' => ['bad']])
            ->assertSessionHasErrors(['email', 'token', 'password']);
    }

    public function test_password_changed_notice_is_not_sent_to_a_replaced_binding(): void
    {
        $admin = $this->admin();
        $binding = $admin->telegramBinding;
        app(TelegramPasswordRecovery::class)->notifyChanged($admin->id, $binding->id, $binding->generation);
        Http::assertSentCount(1);
        $binding->increment('generation');
        app(TelegramPasswordRecovery::class)->notifyChanged($admin->id, $binding->id, $binding->generation - 1);
        Http::assertSentCount(1);
    }

    public function test_new_request_replaces_old_token_but_fast_repeats_are_throttled(): void
    {
        $admin = $this->admin();
        $old = $this->link($admin);
        app(TelegramPasswordRecovery::class)->deliver($admin->email, now()->getTimestamp());
        Http::assertSentCount(1);
        $this->travel(61)->seconds();
        $new = $this->link($admin);
        Http::assertSentCount(2);
        $this->submit($admin, $old)->assertSessionHasErrors('email');
        $this->submit($admin, $new)->assertSessionHasNoErrors();
    }

    public function test_invalid_confirmation_does_not_consume_link(): void
    {
        $admin = $this->admin();
        $token = $this->link($admin);
        $this->post(route('password.update'), [
            'email' => $admin->email, 'token' => $token, 'password' => 'NewPassword123!', 'password_confirmation' => 'different',
        ])->assertSessionHasErrors('password');
        $this->submit($admin, $token)->assertSessionHasNoErrors();
    }

    public function test_old_session_is_rejected_after_recovery_and_new_login_works(): void
    {
        $admin = $this->admin();
        $this->post(route('login.store'), ['email' => $admin->email, 'password' => 'password'])->assertSessionHas('admin_session_version', 0);
        $this->get(route('dashboard'))->assertOk();
        $token = $this->link($admin);
        $this->assertSame(Password::PASSWORD_RESET, app(TelegramPasswordRecovery::class)->reset($this->input($admin, $token)));
        $this->actingAs($admin->fresh());
        $this->get(route('profile.edit'))->assertRedirect(route('login'));
        $this->assertGuest();
        $this->post(route('login.store'), ['email' => $admin->email, 'password' => 'NewPassword123!'])->assertSessionHas('admin_session_version', 1);
        $this->get(route('dashboard'))->assertOk();
    }

    public function test_request_and_reset_rate_limits_apply(): void
    {
        Queue::fake();
        for ($i = 0; $i < 5; $i++) {
            $this->post(route('password.email'), ['email' => 'absent@example.com'])->assertRedirect();
        }
        $this->post(route('password.email'), ['email' => 'absent@example.com'])->assertTooManyRequests();
        $admin = User::factory()->create();
        for ($i = 0; $i < 5; $i++) {
            $this->submit($admin, 'bad')->assertSessionHasErrors('email');
        }
        $this->submit($admin, 'bad')->assertTooManyRequests();
    }

    public function test_envelope_is_bound_to_identity_and_stored_token_is_hashed(): void
    {
        $admin = $this->admin();
        $token = $this->link($admin);
        $payload = json_decode(Crypt::decryptString(strtr($token, '-_', '+/')), true);
        $this->assertSame($admin->id, $payload['admin_id']);
        $this->assertSame(1, $payload['generation']);
        $this->assertTrue(Password::broker()->tokenExists($admin, $payload['token']));
        $this->assertStringNotContainsString($payload['token'], DB::table('password_reset_tokens')->value('token'));
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        AdminTelegramBinding::query()->create([
            'admin_user_id' => $admin->id, 'telegram_user_id' => '700301', 'telegram_chat_id' => '700301',
            'generation' => 1, 'locale' => 'ru', 'connected_at' => now(),
        ]);

        return $admin;
    }

    private function link(User $admin): string
    {
        app(TelegramPasswordRecovery::class)->deliver($admin->email, now()->getTimestamp());
        $record = Http::recorded()->last();
        $this->assertNotNull($record);
        $url = $record[0]['reply_markup']['inline_keyboard'][0][0]['url'];

        return rawurldecode(basename(parse_url($url, PHP_URL_PATH)));
    }

    /** @return array{email: string, token: string, password: string, password_confirmation: string} */
    private function input(User $admin, string $token): array
    {
        return ['email' => $admin->email, 'token' => $token, 'password' => 'NewPassword123!', 'password_confirmation' => 'NewPassword123!'];
    }

    private function submit(User $admin, string $token): TestResponse
    {
        return $this->post(route('password.update'), $this->input($admin, $token));
    }
}
