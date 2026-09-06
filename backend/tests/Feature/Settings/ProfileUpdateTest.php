<?php

namespace Tests\Feature\Settings;

use App\Models\AdminTelegramBinding;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get(route('profile.edit'));

        $response->assertOk();
    }

    public function test_profile_information_can_be_updated()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'));

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => 'Test User',
                'email' => $user->email,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'));

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_user_can_delete_their_account()
    {
        $user = User::factory()->create();
        User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->delete(route('profile.destroy'), [
                'password' => 'password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('home'));

        $this->assertGuest();
        $this->assertNull($user->fresh());
    }

    public function test_last_active_administrator_cannot_delete_their_account(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->delete(route('profile.destroy'), ['password' => 'password'])
            ->assertSessionHasErrors('password');

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh());
    }

    public function test_deleting_one_of_two_administrators_keeps_the_last_account_active(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();

        $this->actingAs($first)
            ->delete(route('profile.destroy'), ['password' => 'password'])
            ->assertSessionHasNoErrors();

        $this->actingAs($second)
            ->delete(route('profile.destroy'), ['password' => 'password'])
            ->assertSessionHasErrors('password');

        $this->assertNull($first->fresh());
        $this->assertNotNull($second->fresh());
        $this->assertSame(1, User::query()->where('is_active', true)->count());
    }

    public function test_deleting_an_administrator_scrubs_their_telegram_binding_but_keeps_a_tombstone(): void
    {
        $user = User::factory()->create();
        User::factory()->create();
        $binding = AdminTelegramBinding::query()->create([
            'admin_user_id' => $user->id,
            'telegram_user_id' => '700099',
            'telegram_chat_id' => '700099',
            'username' => 'former_admin',
            'first_name' => 'Former',
            'locale' => 'ru',
            'generation' => 3,
            'connected_at' => now(),
        ]);

        $this->actingAs($user)
            ->delete(route('profile.destroy'), ['password' => 'password'])
            ->assertSessionHasNoErrors();

        $binding->refresh();
        $this->assertNull($binding->admin_user_id);
        $this->assertNull($binding->telegram_user_id);
        $this->assertNull($binding->telegram_chat_id);
        $this->assertNull($binding->username);
        $this->assertSame(4, $binding->generation);
        $this->assertNotNull($binding->disconnected_at);
    }

    public function test_direct_model_deletion_also_scrubs_a_telegram_binding(): void
    {
        $user = User::factory()->create();
        $binding = AdminTelegramBinding::query()->create([
            'admin_user_id' => $user->id,
            'telegram_user_id' => '700098',
            'telegram_chat_id' => '700098',
            'username' => 'direct_delete_admin',
            'locale' => 'en',
            'generation' => 1,
            'connected_at' => now(),
        ]);

        $user->delete();

        $binding->refresh();
        $this->assertNull($binding->admin_user_id);
        $this->assertNull($binding->telegram_user_id);
        $this->assertNull($binding->telegram_chat_id);
        $this->assertNull($binding->username);
        $this->assertSame(2, $binding->generation);
    }

    public function test_correct_password_must_be_provided_to_delete_account()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from(route('profile.edit'))
            ->delete(route('profile.destroy'), [
                'password' => 'wrong-password',
            ]);

        $response
            ->assertSessionHasErrors('password')
            ->assertRedirect(route('profile.edit'));

        $this->assertNotNull($user->fresh());
    }
}
