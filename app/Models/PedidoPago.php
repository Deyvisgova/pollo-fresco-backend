<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PedidoPago extends Model
{
    use HasFactory;

    protected $table = 'pedido_pagos';
    protected $primaryKey = 'pedido_pago_id';
    public $timestamps = false;

    protected $fillable = [
        'pedido_id',
        'registrado_por',
        'fecha_hora',
        'estado_pago',
        'pago_parcial',
        'vuelto',
    ];

    protected $casts = [
        'fecha_hora' => 'datetime',
        'pago_parcial' => 'decimal:2',
        'vuelto' => 'decimal:2',
    ];

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class, 'pedido_id', 'pedido_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }
}
