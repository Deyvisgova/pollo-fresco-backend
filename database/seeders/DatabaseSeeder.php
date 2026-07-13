<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::updateOrCreate(
            ['usuario' => 'deyvisgova'],
            [
                'nombres' => 'Deyvis',
                'apellidos' => 'Gova',
                'email' => 'deyvisgova@gmail.com',
                'password_hash' => Hash::make('Deyvis260995##'),
                'rol_id' => User::roleIdFromName(User::ROLE_ADMIN),
            ]
        );

        foreach ([
            1 => 'PENDIENTE',
            2 => 'ENTREGADO',
            3 => 'CANCELADO',
            4 => 'EN_RUTA',
            5 => 'NO_ENTREGADO',
        ] as $estadoId => $nombre) {
            DB::table('pedido_estados')->updateOrInsert(
                ['estado_id' => $estadoId],
                ['nombre' => $nombre]
            );
        }
    }
}
