<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Pedido extends Model
{
    use HasFactory;

    protected $table = 'pedidos';
    protected $primaryKey = 'pedido_id';
    public $timestamps = false;

    protected $fillable = [
        'cliente_id',
        'vendedor_usuario_id',
        'delivery_usuario_id',
        'estado_id',
        'fecha_hora_creacion',
        'fecha_hora_entrega',
        'motivo_cancelacion',
        'latitud',
        'longitud',
        'foto_frontis_url',
        'total',
        'tipo_pedido',
        'mesa',
    ];

    protected $casts = [
        'fecha_hora_creacion' => 'datetime',
        'fecha_hora_entrega' => 'datetime',
        'latitud' => 'decimal:7',
        'longitud' => 'decimal:7',
        'total' => 'decimal:2',
    ];

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class, 'cliente_id', 'cliente_id');
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(PedidoDetalle::class, 'pedido_id', 'pedido_id');
    }

    public function pagos(): HasMany
    {
        return $this->hasMany(PedidoPago::class, 'pedido_id', 'pedido_id');
    }

    public function vendedor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'vendedor_usuario_id');
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delivery_usuario_id');
    }
}
