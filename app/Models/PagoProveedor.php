<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PagoProveedor extends Model
{
    use HasFactory;

    protected $table = 'pagos_proveedor';
    protected $primaryKey = 'pago_id';
    public $incrementing = true;
    protected $keyType = 'int';

    public const CREATED_AT = 'creado_en';
    public const UPDATED_AT = null;

    protected $fillable = [
        'usuario_id',
        'proveedor_id',
        'total',
        'monto_transferencia',
        'monto_efectivo',
        'saldo',
        'estado',
        'fecha_desde',
        'fecha_hasta',
        'cantidad_entregas',
        'proveedor_pagado',
    ];

    protected $casts = [
        'proveedor_id' => 'integer',
        'total' => 'decimal:2',
        'monto_transferencia' => 'decimal:2',
        'monto_efectivo' => 'decimal:2',
        'saldo' => 'decimal:2',
        'fecha_desde' => 'date',
        'fecha_hasta' => 'date',
        'cantidad_entregas' => 'integer',
        'proveedor_pagado' => 'string',
        'creado_en' => 'datetime',
    ];

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class, 'proveedor_id', 'proveedor_id');
    }
}
