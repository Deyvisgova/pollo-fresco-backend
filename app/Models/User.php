<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Notifications\RestablecerContrasenaNotificacion;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $table = 'usuarios';
    protected $primaryKey = 'usuario_id';
    public $incrementing = true;
    protected $keyType = 'int';

    public const CREATED_AT = 'creado_en';
    public const UPDATED_AT = 'actualizado_en';

    public const ROLE_ADMIN = 'admin';
    public const ROLE_VENDOR = 'vendor';
    public const ROLE_CASHIER = 'cashier';
    public const ROLE_DELIVERY = 'delivery';

    private const ROLE_ID_MAP = [
        1 => self::ROLE_ADMIN,
        2 => self::ROLE_CASHIER,
        3 => self::ROLE_DELIVERY,
    ];

    /**
     * Atributos asignables en masa.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'rol_id',
        'roles_permitidos',
        'nombres',
        'apellidos',
        'usuario',
        'email',
        'telefono',
        'password_hash',
        'activo',
    ];

    /**
     * Atributos ocultos en la serialización.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password_hash',
        'rol_activo_id',
    ];

    /**
     * Conversiones de atributos.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'activo' => 'boolean',
        'roles_permitidos' => 'array',
    ];

    /**
     * Atributos agregados al serializar.
     *
     * @var array<int, string>
     */
    protected $appends = [
        'id',
        'name',
        'role',
        'roles_disponibles',
    ];

    /**
     * Obtener la lista de roles permitidos en el sistema.
     *
     * @return array<int, string>
     */
    public static function allowedRoles(): array
    {
        return [
            self::ROLE_ADMIN,
            self::ROLE_CASHIER,
            self::ROLE_VENDOR,
            self::ROLE_DELIVERY,
        ];
    }

    /**
     * Mapear el rol numérico del sistema al nombre esperado por el frontend.
     */
    public function getRoleAttribute(): string
    {
        $rolActivoId = $this->attributes['rol_activo_id'] ?? $this->obtenerRolActivoDesdeToken();

        return self::ROLE_ID_MAP[(int) ($rolActivoId ?: $this->rol_id)] ?? self::ROLE_CASHIER;
    }

    /**
     * Roles que el administrador habilito para este usuario.
     *
     * @return array<int, int>
     */
    public function rolesPermitidosIds(): array
    {
        $roles = $this->roles_permitidos;

        if (!is_array($roles) || count($roles) === 0) {
            return [(int) $this->rol_id];
        }

        $roles = array_map('intval', $roles);
        $roles[] = (int) $this->rol_id;

        return array_values(array_unique(array_filter($roles, fn (int $rolId) => in_array($rolId, [1, 2, 3], true))));
    }

    /**
     * Devolver roles disponibles para la seleccion al iniciar sesion.
     *
     * @return array<int, array{id:int,nombre:string,role:string}>
     */
    public function getRolesDisponiblesAttribute(): array
    {
        return array_map(fn (int $rolId) => [
            'id' => $rolId,
            'nombre' => self::roleLabelFromId($rolId),
            'role' => self::ROLE_ID_MAP[$rolId] ?? self::ROLE_CASHIER,
        ], $this->rolesPermitidosIds());
    }

    /**
     * Devolver el id de usuario en el formato esperado por el frontend.
     */
    public function getIdAttribute(): int
    {
        return (int) $this->usuario_id;
    }

    /**
     * Devolver el nombre completo esperado por el frontend.
     */
    public function getNameAttribute(): string
    {
        return trim("{$this->nombres} {$this->apellidos}");
    }

    /**
     * Obtener la contraseña para autenticar contra el hash almacenado.
     */
    public function getAuthPassword(): string
    {
        return $this->password_hash;
    }

    /**
     * Enviar el enlace de recuperacion con el formato del sistema.
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new RestablecerContrasenaNotificacion($token));
    }

    /**
     * Obtener el ID numérico del rol a partir del nombre proporcionado.
     */
    public static function roleIdFromName(string $role): int
    {
        return match (strtolower($role)) {
            self::ROLE_ADMIN => 1,
            self::ROLE_DELIVERY => 3,
            self::ROLE_VENDOR => 2,
            default => 2,
        };
    }

    public static function roleLabelFromId(int $rolId): string
    {
        return match ($rolId) {
            1 => 'Administrador',
            3 => 'Delivery',
            default => 'Vendedor',
        };
    }

    public function revokeAccessTokens(): void
    {
        $this->tokens()->delete();
    }

    private function obtenerRolActivoDesdeToken(): ?int
    {
        $token = $this->currentAccessToken();
        $abilities = $token?->abilities ?? [];

        foreach ($abilities as $ability) {
            if (str_starts_with((string) $ability, 'role:')) {
                return self::roleIdFromName(substr((string) $ability, 5));
            }
        }

        return null;
    }
}
