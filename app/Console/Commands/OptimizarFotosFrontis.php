<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class OptimizarFotosFrontis extends Command
{
    protected $signature = 'mantenimiento:optimizar-frontis';
    protected $description = 'Reduce las fotos privadas antiguas del frontis sin cambiar sus URLs';

    public function handle(): int
    {
        $directory = storage_path('app/private/frontis');
        if (! File::isDirectory($directory)) {
            $this->info('No hay fotos para optimizar.');
            return self::SUCCESS;
        }

        $optimized = 0;
        foreach (File::files($directory) as $file) {
            $content = File::get($file->getPathname());
            $source = @imagecreatefromstring($content);
            if ($source === false) {
                continue;
            }

            $width = imagesx($source);
            $height = imagesy($source);
            $scale = min(1, 1600 / $width, 1200 / $height);
            $newWidth = max(1, (int) round($width * $scale));
            $newHeight = max(1, (int) round($height * $scale));
            $target = imagecreatetruecolor($newWidth, $newHeight);
            $white = imagecolorallocate($target, 255, 255, 255);
            imagefill($target, 0, 0, $white);
            imagecopyresampled($target, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

            $extension = strtolower($file->getExtension());
            $temporary = $file->getPathname().'.tmp';
            $saved = match ($extension) {
                'png' => imagepng($target, $temporary, 8),
                'webp' => imagewebp($target, $temporary, 78),
                default => imagejpeg($target, $temporary, 78),
            };
            imagedestroy($source);
            imagedestroy($target);

            if ($saved && filesize($temporary) < $file->getSize()) {
                File::move($temporary, $file->getPathname());
                $optimized++;
            } else {
                File::delete($temporary);
            }
        }

        $this->info("Fotos optimizadas: {$optimized}");
        return self::SUCCESS;
    }
}
