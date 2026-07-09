<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Cookie\CookieJar;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password as PasswordRule;

class AuthController extends Controller
{
    private const TRUSTED_DEVICE_COOKIE = 'pollo_fresco_trusted_device';

    public function login(Request $request)
    {
        $validated = $request->validate([
            'usuario' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'role' => ['nullable', Rule::in(User::allowedRoles())],
        ]);

        $identifier = trim($validated['usuario']);
        $user = User::where('usuario', $identifier)->orWhere('email', $identifier)->first();
        if (! $user || ! Hash::check($validated['password'], $user->getAuthPassword())) {
            return response()->json(['message' => 'Credenciales invalidas.'], 401);
        }

        if (! $user->activo) {
            $user->revokeAccessTokens();
            return response()->json(['message' => 'La cuenta esta inactiva. Solicita acceso al administrador.'], 403);
        }

        $roles = $user->roles_disponibles;
        $requestedRoleId = ! empty($validated['role']) ? User::roleIdFromName($validated['role']) : null;
        if ($requestedRoleId && ! in_array($requestedRoleId, $user->rolesPermitidosIds(), true)) {
            return response()->json(['message' => 'Este usuario no tiene permiso para ingresar con ese rol.'], 403);
        }

        if (! $requestedRoleId && count($roles) > 1) {
            $challengeId = (string) Str::uuid();
            Cache::put('auth:role-selection:'.$challengeId, [
                'user_id' => $user->id,
                'expires_at' => time() + 300,
            ], now()->addMinutes(5));

            return response()->json([
                'message' => 'Selecciona el rol con el que deseas ingresar.',
                'requires_role_selection' => true,
                'role_challenge' => $challengeId,
                'roles' => $roles,
                'expires_in' => 300,
            ]);
        }

        $activeRoleId = $requestedRoleId ?: (int) ($roles[0]['id'] ?? $user->rol_id);
        $rolesById = array_column($user->roles_disponibles, 'role', 'id');
        $activeRole = $rolesById[$activeRoleId] ?? $user->role;
        $user->setAttribute('rol_activo_id', $activeRoleId);

        if ($activeRole === User::ROLE_ADMIN && ! $this->isTrustedDevice($request, $user)) {
            return $this->createEmailChallenge($user, $activeRole);
        }

        return $this->issueSession($user, $activeRole);
    }

    public function selectRole(Request $request)
    {
        $validated = $request->validate([
            'challenge' => ['required', 'uuid'],
            'role' => ['required', Rule::in(User::allowedRoles())],
        ]);
        $cacheKey = 'auth:role-selection:'.$validated['challenge'];
        $challenge = Cache::get($cacheKey);

        if (! is_array($challenge) || ($challenge['expires_at'] ?? 0) < time()) {
            Cache::forget($cacheKey);
            return response()->json(['message' => 'La seleccion de rol vencio. Inicia sesion nuevamente.'], 422);
        }

        $user = User::find($challenge['user_id'] ?? 0);
        if (! $user || ! $user->activo) {
            Cache::forget($cacheKey);
            return response()->json(['message' => 'No se pudo validar el acceso.'], 403);
        }

        $roleId = User::roleIdFromName($validated['role']);
        if (! $roleId || ! in_array($roleId, $user->rolesPermitidosIds(), true)) {
            return response()->json(['message' => 'Este usuario no tiene permiso para ingresar con ese rol.'], 403);
        }

        Cache::forget($cacheKey);
        $user->setAttribute('rol_activo_id', $roleId);

        if ($validated['role'] === User::ROLE_ADMIN && ! $this->isTrustedDevice($request, $user)) {
            return $this->createEmailChallenge($user, $validated['role']);
        }

        return $this->issueSession($user, $validated['role']);
    }

    public function verifyEmailCode(Request $request, CookieJar $cookies)
    {
        $validated = $request->validate([
            'challenge' => ['required', 'uuid'],
            'code' => ['required', 'digits:6'],
            'trust_device' => ['sometimes', 'boolean'],
        ]);
        $cacheKey = 'auth:email-code:'.$validated['challenge'];
        $challenge = Cache::get($cacheKey);

        if (! is_array($challenge) || ($challenge['expires_at'] ?? 0) < time()) {
            Cache::forget($cacheKey);
            return response()->json(['message' => 'El codigo vencio. Solicita uno nuevo.'], 422);
        }

        $user = User::find($challenge['user_id'] ?? 0);
        if (! $user || ! $user->activo) {
            Cache::forget($cacheKey);
            return response()->json(['message' => 'No se pudo validar el acceso.'], 403);
        }

        $expected = hash_hmac('sha256', $validated['challenge'].'|'.$validated['code'], (string) config('app.key'));
        $valid = hash_equals((string) ($challenge['code_hash'] ?? ''), $expected);

        if (! $valid) {
            $challenge['attempts'] = ((int) ($challenge['attempts'] ?? 0)) + 1;
            if ($challenge['attempts'] >= 5) {
                Cache::forget($cacheKey);
            } else {
                Cache::put($cacheKey, $challenge, now()->addSeconds(max(1, $challenge['expires_at'] - time())));
            }
            return response()->json(['message' => 'El codigo de seguridad no es valido.'], 422);
        }

        Cache::forget($cacheKey);
        $user->setAttribute('rol_activo_id', User::roleIdFromName(User::ROLE_ADMIN));
        $payload = $this->issueSessionPayload($user, User::ROLE_ADMIN);
        $response = response()->json($payload);

        if (! empty($validated['trust_device'])) {
            $response->withCookie($cookies->make(
                self::TRUSTED_DEVICE_COOKIE,
                $this->trustedDeviceValue($user),
                60 * 24 * 30,
                '/',
                null,
                $request->isSecure() || (bool) config('session.secure'),
                true,
                false,
                'lax'
            ));
        }

        return $response;
    }

    public function me(Request $request)
    {
        return response()->json(['user' => $request->user()]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Sesion cerrada correctamente.']);
    }

    public function forgotPassword(Request $request)
    {
        $validated = $request->validate(['email' => ['required', 'string', 'email']]);
        try {
            Password::sendResetLink($validated);
        } catch (\Throwable $exception) {
            Log::error('No se pudo enviar el correo de recuperacion.', ['exception' => $exception]);
            return response()->json(['message' => 'No pudimos enviar el correo en este momento. Intenta nuevamente.'], 503);
        }

        return response()->json([
            'message' => 'Si el correo pertenece a una cuenta, recibiras un enlace para crear una nueva contrasena.',
        ]);
    }

    public function resetPassword(Request $request)
    {
        $validated = $request->validate([
            'token' => ['required'],
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(12)->mixedCase()->letters()->numbers()->symbols()],
        ]);

        $status = Password::reset($validated, function (User $user, string $password) {
            $user->forceFill(['password_hash' => Hash::make($password)])->save();
            $user->revokeAccessTokens();
            event(new PasswordReset($user));
        });

        return $status === Password::PASSWORD_RESET
            ? response()->json(['message' => 'Tu contrasena fue actualizada correctamente.'])
            : response()->json([
                'message' => $status === Password::INVALID_TOKEN
                    ? 'El enlace vencio o ya fue utilizado. Solicita uno nuevo.'
                    : 'No pudimos restablecer la contrasena con esos datos.',
            ], 422);
    }

    private function createEmailChallenge(User $user, string $role)
    {
        $challengeId = (string) Str::uuid();
        $code = (string) random_int(100000, 999999);
        Cache::put('auth:email-code:'.$challengeId, [
            'user_id' => $user->id,
            'role' => $role,
            'code_hash' => hash_hmac('sha256', $challengeId.'|'.$code, (string) config('app.key')),
            'attempts' => 0,
            'expires_at' => time() + 600,
        ], now()->addMinutes(10));

        try {
            Mail::raw(
                "Tu codigo de acceso a Pollo Fresco es: {$code}\n\nVence en 10 minutos. Si no intentaste ingresar, cambia tu contrasena.",
                fn ($message) => $message->to($user->email)->subject('Codigo de acceso - Pollo Fresco')
            );
        } catch (\Throwable $exception) {
            Cache::forget('auth:email-code:'.$challengeId);
            Log::error('No se pudo enviar el codigo de acceso.', ['user_id' => $user->id, 'exception' => $exception]);
            return response()->json(['message' => 'No pudimos enviar el codigo al correo. Intenta nuevamente.'], 503);
        }

        return response()->json([
            'message' => 'Enviamos un codigo de acceso a tu correo.',
            'requires_email_code' => true,
            'challenge' => $challengeId,
            'masked_email' => $this->maskEmail($user->email),
            'expires_in' => 600,
        ], 202);
    }

    private function issueSession(User $user, string $role)
    {
        return response()->json($this->issueSessionPayload($user, $role));
    }

    private function issueSessionPayload(User $user, string $role): array
    {
        $user->revokeAccessTokens();
        $token = $user->createToken(
            'api-token',
            ["role:{$role}"],
            now()->addMinutes((int) config('sanctum.expiration', 480))
        )->plainTextToken;

        return ['message' => 'Inicio de sesion exitoso.', 'user' => $user, 'token' => $token];
    }

    private function isTrustedDevice(Request $request, User $user): bool
    {
        $value = $request->cookie(self::TRUSTED_DEVICE_COOKIE);
        if (! is_string($value) || $value === '') {
            return false;
        }

        try {
            $payload = json_decode(Crypt::decryptString($value), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return false;
        }

        return (int) ($payload['user_id'] ?? 0) === (int) $user->id
            && (int) ($payload['expires_at'] ?? 0) >= time()
            && hash_equals((string) ($payload['password_fingerprint'] ?? ''), hash('sha256', $user->getAuthPassword()));
    }

    private function trustedDeviceValue(User $user): string
    {
        return Crypt::encryptString(json_encode([
            'user_id' => $user->id,
            'expires_at' => time() + (60 * 60 * 24 * 30),
            'password_fingerprint' => hash('sha256', $user->getAuthPassword()),
        ], JSON_THROW_ON_ERROR));
    }

    private function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        $visible = mb_substr($local, 0, min(2, mb_strlen($local)));
        return $visible.str_repeat('*', max(3, mb_strlen($local) - mb_strlen($visible))).'@'.$domain;
    }
}
