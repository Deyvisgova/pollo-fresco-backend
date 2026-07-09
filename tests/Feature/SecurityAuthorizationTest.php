<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SecurityAuthorizationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_public_registration_is_closed(): void
    {
        $this->postJson('/api/auth/register', [])->assertNotFound();
    }

    public function test_protected_routes_reject_anonymous_requests(): void
    {
        $this->getJson('/api/usuarios')->assertUnauthorized();
        $this->getJson('/api/frontis/archivo.webp')->assertUnauthorized();
    }

    public function test_vendor_cannot_access_administration_or_finances(): void
    {
        $vendor = $this->createUser(2, 'vendor');
        Sanctum::actingAs($vendor, ['role:vendor']);

        $this->getJson('/api/usuarios')->assertForbidden();
        $this->getJson('/api/gastos/resumen')->assertForbidden();
        $this->getJson('/api/mantenimiento')->assertForbidden();
    }

    public function test_delivery_cannot_access_users_or_sales(): void
    {
        $delivery = $this->createUser(3, 'delivery');
        Sanctum::actingAs($delivery, ['role:delivery']);

        $this->getJson('/api/usuarios')->assertForbidden();
        $this->getJson('/api/ventas')->assertForbidden();
        $this->getJson('/api/proveedores')->assertForbidden();
    }

    public function test_inactive_accounts_are_blocked_even_with_a_token(): void
    {
        $user = $this->createUser(2, 'inactive', false);
        Sanctum::actingAs($user, ['role:vendor']);

        $this->getJson('/api/clientes')->assertForbidden();
    }

    public function test_admin_can_reach_user_administration(): void
    {
        $admin = $this->createUser(1, 'admin');
        Sanctum::actingAs($admin, ['role:admin']);

        $this->getJson('/api/usuarios')->assertOk();
    }

    public function test_admin_login_sends_and_validates_an_email_code(): void
    {
        $admin = $this->createUser(1, 'mfa');
        $password = 'Temporal-Segura-2026!';
        $code = null;
        Mail::shouldReceive('raw')
            ->once()
            ->withArgs(function (string $body, callable $callback) use (&$code): bool {
                preg_match('/\b(\d{6})\b/', $body, $matches);
                $code = $matches[1] ?? null;
                return true;
            });

        $response = $this->postJson('/api/auth/login', [
            'usuario' => $admin->usuario,
            'password' => $password,
            'role' => 'admin',
        ])->assertStatus(202)->assertJsonPath('requires_email_code', true);

        $this->postJson('/api/auth/email-code/verify', [
            'challenge' => $response->json('challenge'),
            'code' => $code,
            'trust_device' => true,
        ])->assertOk()->assertJsonStructure(['token'])->assertCookie('pollo_fresco_trusted_device');
    }

    public function test_user_selects_role_with_a_temporary_challenge_without_repeating_password(): void
    {
        $user = $this->createUser(2, 'multi_role');
        $user->forceFill(['roles_permitidos' => [2, 3]])->save();

        $login = $this->postJson('/api/auth/login', [
            'usuario' => $user->usuario,
            'password' => 'Temporal-Segura-2026!',
        ])->assertOk()
            ->assertJsonPath('requires_role_selection', true)
            ->assertJsonStructure(['role_challenge', 'roles']);

        $this->postJson('/api/auth/select-role', [
            'challenge' => $login->json('role_challenge'),
            'role' => 'delivery',
        ])->assertOk()
            ->assertJsonPath('user.role', 'delivery')
            ->assertJsonStructure(['token']);

        $this->postJson('/api/auth/select-role', [
            'challenge' => $login->json('role_challenge'),
            'role' => 'vendor',
        ])->assertStatus(422);
    }

    private function createUser(int $roleId, string $suffix, bool $active = true): User
    {
        return User::create([
            'rol_id' => $roleId,
            'roles_permitidos' => [$roleId],
            'nombres' => 'Prueba',
            'apellidos' => 'Seguridad',
            'usuario' => 'security_'.$suffix.'_'.bin2hex(random_bytes(4)),
            'email' => 'security_'.$suffix.'_'.bin2hex(random_bytes(4)).'@example.test',
            'telefono' => null,
            'password_hash' => Hash::make('Temporal-Segura-2026!'),
            'activo' => $active,
        ]);
    }
}
