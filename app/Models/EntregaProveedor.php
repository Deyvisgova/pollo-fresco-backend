<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EntregaProveedor extends Model
{
    use HasFactory;

    protected $table = 'entregas_proveedor';
    protected $primaryKey = 'entrega_id';
    public $incrementing = true;
    protected $keyType = 'int';

    public const CREATED_AT = 'creado_en';
    public const UPDATED_AT = null;

    /**
     * Atributos asignables en masa.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'proveedor_id',
        'usuario_id',
        'fecha_hora',
        'cantidad_pollos',
        'peso_total_kg',
        'merma_kg',
        'costo_total',
        'tipo',
        'estado_pago',
    ];

    /**
     * Conversiones de atributos.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'fecha_hora' => 'datetime',
        'cantidad_pollos' => 'integer',
        'peso_total_kg' => 'decimal:2',
        'merma_kg' => 'decimal:2',
        'costo_total' => 'decimal:2',
        'estado_pago' => 'string',
        'creado_en' => 'datetime',
    ];

    /**
     * Relación con proveedor.
     */
    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class, 'proveedor_id', 'proveedor_id');
    }
}
