<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class AuthController extends Controller
{
    /**
     * Registrar un usuario nuevo y emitir un token de API.
     */
    public function register(Request $request)
    {
        // Validar datos de registro y limitar los roles permitidos.
        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'nombres' => ['nullable', 'string', 'max:255'],
            'apellidos' => ['nullable', 'string', 'max:255'],
            'usuario' => ['required', 'string', 'max:255', 'unique:usuarios,usuario'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:usuarios,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'role' => ['required', Rule::in(User::allowedRoles())],
        ]);

        $nombres = $validated['nombres'] ?? null;
        $apellidos = $validated['apellidos'] ?? null;

        if (! $nombres) {
            $nameParts = preg_split('/\s+/', trim((string) ($validated['name'] ?? '')), 2);
            $nombres = $nameParts[0] ?? 'Usuario';
            $apellidos = $apellidos ?? ($nameParts[1] ?? '');
        }

        // Crear el usuario con contraseña cifrada.
        $user = User::create([
            'nombres' => $nombres,
            'apellidos' => $apellidos ?? '',
            'usuario' => $validated['usuario'],
            'email' => $validated['email'],
            'password_hash' => Hash::make($validated['password']),
            'rol_id' => User::roleIdFromName($validated['role']),
            'roles_permitidos' => [User::roleIdFromName($validated['role'])],
        ]);

        $this->ensurePersonalAccessTokensTableExists();

        // Emitir un token de Sanctum para autenticación API.
        $token = $user->createToken('api-token', ["role:{$user->role}"])->plainTextToken;

        return response()->json([
            'message' => 'Usuario registrado correctamente.',
            'user' => $user,
            'token' => $token,
        ], 201);
    }

    /**
     * Autenticar un usuario y emitir un nuevo token de API.
     */
    public function login(Request $request)
    {
        // Validar credenciales de acceso.
        $validated = $request->validate([
            'usuario' => ['required', 'string'],
            'password' => ['required', 'string'],
            'role' => ['nullable', Rule::in(User::allowedRoles())],
        ]);

        // Buscar el usuario y verificar la contraseña.
        $identifier = $validated['usuario'];
        $user = User::where('usuario', $identifier)
            ->orWhere('email', $identifier)
            ->first();

        if (! $user) {
            return response()->json([
                'message' => 'Credenciales inválidas.',
            ], 422);
        }

        $storedPassword = $user->getAuthPassword();
        $passwordMatches = Hash::check($validated['password'], $storedPassword);
        $passwordEsPlano = hash_equals($validated['password'], $storedPassword);

        if (! $passwordMatches && ! $passwordEsPlano) {
            return response()->json([
                'message' => 'Credenciales inválidas.',
            ], 422);
        }

        if ($passwordEsPlano) {
            $user->forceFill([
                'password_hash' => Hash::make($validated['password']),
            ])->save();
        }

        $this->ensurePersonalAccessTokensTableExists();

        // Emitir un nuevo token para la sesión.
        $rolesPermitidos = $user->roles_disponibles;
        $rolSolicitadoId = !empty($validated['role'])
            ? User::roleIdFromName($validated['role'])
            : null;

        if ($rolSolicitadoId && !in_array($rolSolicitadoId, $user->rolesPermitidosIds(), true)) {
            return response()->json([
                'message' => 'Este usuario no tiene permiso para ingresar con ese rol.',
            ], 403);
        }

        if (!$rolSolicitadoId && count($rolesPermitidos) > 1) {
            return response()->json([
                'message' => 'Selecciona el rol con el que deseas ingresar.',
                'requires_role_selection' => true,
                'user' => $user,
                'roles' => $rolesPermitidos,
            ]);
        }

        $rolActivoId = $rolSolicitadoId ?: (int) ($rolesPermitidos[0]['id'] ?? $user->rol_id);
        $rolesPorId = array_column($user->roles_disponibles, 'role', 'id');
        $rolActivo = $rolesPorId[$rolActivoId] ?? $user->role;
        $user->setAttribute('rol_activo_id', $rolActivoId);

        $token = $user->createToken('api-token', ["role:{$rolActivo}"])->plainTextToken;

        return response()->json([
            'message' => 'Inicio de sesión exitoso.',
            'user' => $user,
            'token' => $token,
        ]);
    }

    /**
     * Garantizar que exista la tabla de tokens de Sanctum.
     */
    private function ensurePersonalAccessTokensTableExists(): void
    {
        if (Schema::hasTable('personal_access_tokens')) {
            return;
        }

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Devolver el perfil del usuario autenticado.
     */
    public function me(Request $request)
    {
        // Devolver el usuario autenticado actual.
        return response()->json([
            'user' => $request->user(),
        ]);
    }

    /**
     * Revocar el token de API actual.
     */
    public function logout(Request $request)
    {
        // Revocar únicamente el token de acceso actual.
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Sesión cerrada correctamente.',
        ]);
    }

    /**
     * Enviar el enlace de restablecimiento al correo del usuario.
     */
    public function forgotPassword(Request $request)
    {
        // Validar el correo para recuperación de contraseña.
        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
        ]);

        try {
            Password::sendResetLink($validated);
        } catch (\Throwable $exception) {
            Log::error('No se pudo enviar el correo de recuperacion.', [
                'exception' => $exception,
            ]);

            return response()->json([
                'message' => 'No pudimos enviar el correo en este momento. Intenta nuevamente.',
            ], 503);
        }

        // Evita revelar si un correo esta registrado en el sistema.
        return response()->json([
            'message' => 'Si el correo pertenece a una cuenta, recibiras un enlace para crear una nueva contrasena.',
        ]);
    }

    /**
     * Restablecer la contraseña con el token de recuperación.
     */
    public function resetPassword(Request $request)
    {
        // Validar datos del restablecimiento.
        $validated = $request->validate([
            'token' => ['required'],
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        // Ejecutar el restablecimiento con el broker de Laravel.
        $status = Password::reset(
            $validated,
            function (User $user, string $password) {
                // Actualizar la contraseña y el remember token.
                $user->forceFill([
                    'password_hash' => Hash::make($password),
                ])->save();

                event(new PasswordReset($user));
            }
        );

        return $status === Password::PASSWORD_RESET
            ? response()->json(['message' => 'Tu contrasena fue actualizada correctamente.'])
            : response()->json([
                'message' => $status === Password::INVALID_TOKEN
                    ? 'El enlace vencio o ya fue utilizado. Solicita uno nuevo.'
                    : 'No pudimos restablecer la contrasena con esos datos.',
            ], 422);
    }
}
