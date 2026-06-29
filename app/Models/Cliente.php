<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Cliente extends Model
{
    use HasFactory;

    protected $table = 'clientes';
    protected $primaryKey = 'cliente_id';
    public $incrementing = true;
    protected $keyType = 'int';

    public const CREATED_AT = 'creado_en';
    public const UPDATED_AT = 'actualizado_en';

    /**
     * Atributos asignables en masa.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'dni',
        'ruc',
        'nombres',
        'apellidos',
        'nombre_empresa',
        'celular',
        'direccion',
        'direccion_fiscal',
        'referencias',
        'latitud',
        'longitud',
        'foto_frontis_url',
        'ubicacion_actualizada_por',
        'ubicacion_actualizada_en',
    ];

    /**
     * Conversiones de atributos.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'creado_en' => 'datetime',
        'actualizado_en' => 'datetime',
        'latitud' => 'decimal:7',
        'longitud' => 'decimal:7',
        'ubicacion_actualizada_en' => 'datetime',
    ];
}
