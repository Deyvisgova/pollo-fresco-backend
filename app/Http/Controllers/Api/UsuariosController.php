<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password as PasswordRule;

class UsuariosController extends Controller
{
    /**
     * Mostrar listado de usuarios.
     */
    public function index()
    {
        return User::orderByDesc('usuario_id')->get();
    }

    /**
     * Registrar un nuevo usuario.
     */
    public function store(Request $request)
    {
        $data = $this->validarDatos($request);
        $data['roles_permitidos'] = $this->normalizarRolesPermitidos($data['rol_id'], $data['roles_permitidos'] ?? null);
        $data['password_hash'] = Hash::make($data['password']);
        unset($data['password']);

        $usuario = User::create($data);

        return response()->json($usuario, 201);
    }

    /**
     * Mostrar un usuario específico.
     */
    public function show(User $usuario)
    {
        return $usuario;
    }

    /**
     * Actualizar un usuario existente.
     */
    public function update(Request $request, User $usuario)
    {
        $data = $this->validarDatos($request, $usuario->usuario_id);
        if ((int) $request->user()->id === (int) $usuario->id && ! $data['activo']) {
            return response()->json(['message' => 'No puedes desactivar tu propia cuenta.'], 422);
        }

        if ($this->remueveUltimoAdministrador($usuario, (int) $data['rol_id'])) {
            return response()->json(['message' => 'Debe permanecer al menos un administrador activo.'], 422);
        }

        $data['roles_permitidos'] = $this->normalizarRolesPermitidos($data['rol_id'], $data['roles_permitidos'] ?? null);
        if (!empty($data['password'])) {
            $usuario->password_hash = Hash::make($data['password']);
        }
        unset($data['password']);

        $usuario->fill($data);
        $usuario->save();
        $usuario->revokeAccessTokens();

        return $usuario;
    }

    /**
     * Eliminar un usuario.
     */
    public function destroy(User $usuario)
    {
        if ((int) request()->user()->id === (int) $usuario->id) {
            return response()->json(['message' => 'No puedes eliminar tu propia cuenta.'], 422);
        }

        if ($this->remueveUltimoAdministrador($usuario, 0)) {
            return response()->json(['message' => 'No puedes eliminar al ultimo administrador.'], 422);
        }

        $usuario->revokeAccessTokens();
        $usuario->delete();

        return response()->json(['message' => 'Usuario eliminado.']);
    }

    /**
     * Validar datos de usuario.
     */
    private function validarDatos(Request $request, ?int $usuarioId = null): array
    {
        $reglas = [
            'rol_id' => ['required', 'integer', Rule::in([1, 2, 3])],
            'roles_permitidos' => ['nullable', 'array'],
            'roles_permitidos.*' => ['integer', Rule::in([1, 2, 3])],
            'nombres' => ['required', 'string', 'max:80'],
            'apellidos' => ['required', 'string', 'max:80'],
            'usuario' => [
                'required',
                'string',
                'max:60',
                Rule::unique('usuarios', 'usuario')->ignore($usuarioId, 'usuario_id'),
            ],
            'email' => [
                'required',
                'email',
                'max:120',
                Rule::unique('usuarios', 'email')->ignore($usuarioId, 'usuario_id'),
            ],
            'telefono' => ['nullable', 'string', 'max:9'],
            'password' => [
                $usuarioId ? 'nullable' : 'required',
                PasswordRule::min(12)->mixedCase()->letters()->numbers()->symbols(),
                'confirmed',
            ],
            'activo' => ['required', 'boolean'],
        ];

        return $request->validate($reglas);
    }

    private function remueveUltimoAdministrador(User $usuario, int $nuevoRolId): bool
    {
        if ((int) $usuario->rol_id !== 1 || $nuevoRolId === 1) {
            return false;
        }

        return User::where('rol_id', 1)
            ->where('activo', true)
            ->where('usuario_id', '!=', $usuario->id)
            ->doesntExist();
    }

    /**
     * Garantizar que el rol principal siempre este entre los roles habilitados.
     *
     * @param  array<int, int>|null  $rolesPermitidos
     * @return array<int, int>
     */
    private function normalizarRolesPermitidos(int $rolPrincipal, ?array $rolesPermitidos): array
    {
        $roles = array_map('intval', $rolesPermitidos ?? []);
        $roles[] = (int) $rolPrincipal;

        return array_values(array_unique(array_filter($roles, fn (int $rolId) => in_array($rolId, [1, 2, 3], true))));
    }
}
