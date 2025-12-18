<?php

namespace Tests\Feature;

use App\Models\RefreshToken;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    // ==================== REGISTER TESTS ====================

    public function test_register_successfully(): void
    {
        $userData = [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password123',
        ];

        $response = $this->postJson('/api/register', $userData);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'user' => [
                    'id',
                    'name',
                    'email',
                    'role',
                    'permissions',
                ],
            ])
            ->assertJson([
                'user' => [
                    'name' => 'Test User',
                    'email' => 'test@example.com',
                    'role' => 'super_admin',
                ],
            ]);

        $this->assertDatabaseHas('users', [
            'email' => 'test@example.com',
        ]);

        $user = User::where('email', 'test@example.com')->first();
        $this->assertTrue(Hash::check('password123', $user->password));
        $this->assertTrue($user->hasRole('super_admin'));
    }

    public function test_register_requires_name(): void
    {
        $response = $this->postJson('/api/register', [
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_register_requires_email(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Test User',
            'password' => 'password123',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_register_requires_valid_email(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Test User',
            'email' => 'invalid-email',
            'password' => 'password123',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_register_requires_password(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_register_requires_minimum_password_length(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => '12345',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_register_name_max_length(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => str_repeat('a', 256),
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_register_email_max_length(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Test User',
            'email' => str_repeat('a', 250).'@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    // ==================== LOGIN TESTS ====================

    public function test_login_successfully(): void
    {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => Hash::make('password123'),
        ]);
        $user->assignRole('super_admin');

        $response = $this->postJson('/api/login', [
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'message',
                'user' => [
                    'id',
                    'name',
                    'email',
                    'role',
                ],
            ])
            ->assertJson([
                'message' => 'Login exitoso',
                'user' => [
                    'email' => 'test@example.com',
                    'role' => 'super_admin',
                ],
            ]);

        $response->assertCookie('access_token');
        $response->assertCookie('refresh_token');

        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'tokenable_type' => User::class,
        ]);

        $this->assertDatabaseHas('refresh_tokens', [
            'user_id' => $user->id,
        ]);
    }

    public function test_login_with_invalid_email(): void
    {
        $response = $this->postJson('/api/login', [
            'email' => 'nonexistent@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'errors' => [
                    'email' => ['The provided credentials are incorrect.'],
                ],
            ]);
    }

    public function test_login_with_invalid_password(): void
    {
        User::factory()->create([
            'email' => 'test@example.com',
            'password' => Hash::make('password123'),
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'test@example.com',
            'password' => 'wrongpassword',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'errors' => [
                    'email' => ['The provided credentials are incorrect.'],
                ],
            ]);
    }

    public function test_login_requires_email(): void
    {
        $response = $this->postJson('/api/login', [
            'password' => 'password123',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_login_requires_password(): void
    {
        $response = $this->postJson('/api/login', [
            'email' => 'test@example.com',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_login_requires_valid_email_format(): void
    {
        $response = $this->postJson('/api/login', [
            'email' => 'invalid-email',
            'password' => 'password123',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_login_revokes_old_tokens(): void
    {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => Hash::make('password123'),
        ]);

        $oldAccessToken = $user->createToken('old_token')->plainTextToken;
        $oldRefreshToken = RefreshToken::createForUser($user);

        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $user->id,
        ]);
        $this->assertDatabaseHas('refresh_tokens', [
            'user_id' => $user->id,
            'id' => $oldRefreshToken->id,
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseMissing('refresh_tokens', [
            'id' => $oldRefreshToken->id,
        ]);
    }

    // ==================== ME TESTS ====================

    public function test_me_returns_authenticated_user(): void
    {
        $user = User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);
        $user->assignRole('super_admin');

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/me');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'user' => [
                    'id',
                    'name',
                    'email',
                    'role',
                    'permissions',
                ],
            ])
            ->assertJson([
                'user' => [
                    'name' => 'Test User',
                    'email' => 'test@example.com',
                    'role' => 'super_admin',
                ],
            ]);
    }

    public function test_me_requires_authentication(): void
    {
        $response = $this->getJson('/api/me');

        $response->assertStatus(401);
    }

    // ==================== LOGOUT TESTS ====================

    public function test_logout_successfully(): void
    {
        $user = User::factory()->create();
        $user->createToken('auth_token');

        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $user->id,
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/logout');

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Sesión cerrada correctamente.',
            ]);

        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $user->id,
        ]);
    }

    public function test_logout_requires_authentication(): void
    {
        $response = $this->postJson('/api/logout');

        $response->assertStatus(401);
    }

    public function test_logout_deletes_all_user_tokens(): void
    {
        $user = User::factory()->create();
        $user->createToken('token_1');
        $user->createToken('token_2');
        $user->createToken('token_3');

        $this->assertEquals(3, $user->tokens()->count());

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/logout');

        $response->assertStatus(200);

        $this->assertEquals(0, $user->fresh()->tokens()->count());
    }
}
