<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureTwoFactorEnabled;
use App\Http\Middleware\RestrictWeekendAccess;
use App\Models\MagicLinkSecret;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MagicLinkTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $sds;
    private User $asds;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([EnsureTwoFactorEnabled::class, RestrictWeekendAccess::class]);

        foreach (['admin', 'sds', 'asds', 'originator'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');

        $this->sds = User::factory()->create();
        $this->sds->assignRole('sds');

        $this->asds = User::factory()->create();
        $this->asds->assignRole('asds');
    }

    public function test_admin_can_generate_and_consume_a_magic_link(): void
    {
        $response = $this->actingAs($this->admin)->post(route('users.magic-link', $this->sds));

        $response->assertRedirect();
        $url = session('magic_link');
        $this->assertNotNull($url);

        // The SDS's magic link jumps straight to her approval queue, not the
        // general dashboard.
        $this->get($url)->assertRedirect(route('documents.index', ['tab' => 'for_approval']));
        $this->assertAuthenticatedAs($this->sds);
    }

    public function test_admin_can_generate_and_consume_a_magic_link_for_asds(): void
    {
        $response = $this->actingAs($this->admin)->post(route('users.magic-link', $this->asds));

        $response->assertRedirect();
        $url = session('magic_link');
        $this->assertNotNull($url);

        $this->get($url)->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($this->asds);
    }

    public function test_non_admin_cannot_generate_a_magic_link(): void
    {
        $originator = User::factory()->create();
        $originator->assignRole('originator');

        $this->actingAs($originator)
            ->post(route('users.magic-link', $this->sds))
            ->assertForbidden();
    }

    public function test_cannot_generate_a_magic_link_for_a_user_who_is_not_sds_or_asds(): void
    {
        $originator = User::factory()->create();
        $originator->assignRole('originator');

        $this->actingAs($this->admin)
            ->post(route('users.magic-link', $originator))
            ->assertForbidden();
    }

    public function test_non_admin_cannot_rotate_the_secret(): void
    {
        $originator = User::factory()->create();
        $originator->assignRole('originator');

        $this->actingAs($originator)
            ->post(route('magic-link-secret.rotate'))
            ->assertForbidden();
    }

    public function test_rotating_the_secret_invalidates_a_previously_generated_link(): void
    {
        $this->actingAs($this->admin)->post(route('users.magic-link', $this->sds));
        $url = session('magic_link');
        $this->assertNotNull($url);

        $this->actingAs($this->admin)->post(route('magic-link-secret.rotate'))->assertRedirect();

        $this->get($url)->assertForbidden();
    }

    public function test_consuming_with_a_tampered_signature_is_rejected(): void
    {
        $signature = hash_hmac('sha256', (string) $this->sds->id, MagicLinkSecret::current());

        $this->get('/magic-login?uid='.$this->sds->id.'&signature=deadbeef')->assertForbidden();
        $this->get('/magic-login?uid='.($this->sds->id + 999).'&signature='.$signature)->assertForbidden();
    }
}
