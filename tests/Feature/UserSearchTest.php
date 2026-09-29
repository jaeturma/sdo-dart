<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureTwoFactorEnabled;
use App\Http\Middleware\RestrictWeekendAccess;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserSearchTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([EnsureTwoFactorEnabled::class, RestrictWeekendAccess::class]);

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
    }

    public function test_search_matches_by_name(): void
    {
        User::factory()->create(['name' => 'Florence Alcazaren']);
        User::factory()->create(['name' => 'Roy Enriquez']);

        $response = $this->actingAs($this->admin)->get(route('users.index', ['search' => 'Florence']));

        $response->assertInertia(fn ($page) => $page
            ->where('users.data', fn ($users) => collect($users)->pluck('name')->contains('Florence Alcazaren')
                && ! collect($users)->pluck('name')->contains('Roy Enriquez')));
    }

    public function test_search_matches_by_email(): void
    {
        User::factory()->create(['name' => 'Someone', 'email' => 'unique.match@deped.gov.ph']);
        User::factory()->create(['name' => 'Someone Else', 'email' => 'other@example.com']);

        $response = $this->actingAs($this->admin)->get(route('users.index', ['search' => 'unique.match']));

        $response->assertInertia(fn ($page) => $page
            ->where('users.data', fn ($users) => collect($users)->pluck('email')->contains('unique.match@deped.gov.ph')
                && count($users) === 1));
    }

    public function test_blank_search_returns_all_users(): void
    {
        User::factory()->count(3)->create();

        $response = $this->actingAs($this->admin)->get(route('users.index'));

        $response->assertInertia(fn ($page) => $page->where('search', ''));
    }
}
