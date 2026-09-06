<?php

namespace Tests\Feature;

use App\Models\AdminTelegramBinding;
use App\Models\AdminTelegramBindingCode;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PostgresAdminTelegramBindingLifecycleTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL lifecycle trigger test requires the pgsql driver.');
        }
    }

    public function test_bulk_lifecycle_changes_scrub_identity_and_advance_generation(): void
    {
        $deactivated = User::factory()->create();
        $deactivatedBinding = $this->binding($deactivated, '9100000001', 4);
        $deactivatedCode = $this->code($deactivated, str_repeat('1', 64));

        DB::table('admin_users')->where('id', $deactivated->id)->update([
            'is_active' => false,
            'updated_at' => now(),
        ]);

        $deactivatedBinding->refresh();
        $this->assertSame(5, $deactivatedBinding->generation);
        $this->assertNull($deactivatedBinding->telegram_user_id);
        $this->assertNull($deactivatedBinding->telegram_chat_id);
        $this->assertNotNull($deactivatedBinding->disconnected_at);
        $this->assertNotNull($deactivatedCode->refresh()->revoked_at);

        $unverified = User::factory()->create();
        $unverifiedBinding = $this->binding($unverified, '9100000003', 6);
        $unverifiedCode = $this->code($unverified, str_repeat('2', 64));

        DB::table('admin_users')->where('id', $unverified->id)->update([
            'email_verified_at' => null,
            'updated_at' => now(),
        ]);

        $unverifiedBinding->refresh();
        $this->assertSame(7, $unverifiedBinding->generation);
        $this->assertNull($unverifiedBinding->telegram_user_id);
        $this->assertNull($unverifiedBinding->telegram_chat_id);
        $this->assertNotNull($unverifiedCode->refresh()->revoked_at);

        $deleted = User::factory()->create();
        $deletedBinding = $this->binding($deleted, '9100000002', 8);

        DB::table('admin_users')->where('id', $deleted->id)->delete();

        $deletedBinding->refresh();
        $this->assertNull($deletedBinding->admin_user_id);
        $this->assertSame(9, $deletedBinding->generation);
        $this->assertNull($deletedBinding->telegram_user_id);
        $this->assertNull($deletedBinding->telegram_chat_id);
        $this->assertNull($deletedBinding->username);
        $this->assertNotNull($deletedBinding->disconnected_at);
    }

    private function binding(User $admin, string $telegramId, int $generation): AdminTelegramBinding
    {
        return AdminTelegramBinding::query()->create([
            'admin_user_id' => $admin->id,
            'telegram_user_id' => $telegramId,
            'telegram_chat_id' => $telegramId,
            'username' => 'postgres_lifecycle_admin',
            'first_name' => 'Lifecycle',
            'last_name' => 'Admin',
            'locale' => 'en',
            'generation' => $generation,
            'connected_at' => now(),
        ]);
    }

    private function code(User $admin, string $hash): AdminTelegramBindingCode
    {
        return AdminTelegramBindingCode::query()->create([
            'admin_user_id' => $admin->id,
            'code_hash' => $hash,
            'expires_at' => now()->addMinutes(10),
        ]);
    }
}
