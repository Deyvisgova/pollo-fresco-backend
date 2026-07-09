<?php

return [
    'directorio_respaldos' => env('BACKUP_DIRECTORY', 'backups/base-datos'),
    'disco_externo' => env('BACKUP_OFFSITE_DISK'),
    'directorio_externo' => env('BACKUP_OFFSITE_DIRECTORY', 'pollo-fresco/base-datos'),
    'retencion' => [
        'diario' => 7,
        'semanal' => 4,
        'mensual' => 6,
        'pre-restauracion' => 5,
        'pre-reinicio' => 5,
    ],
];
