<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class AuditSecurityEvents
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = (string) Str::uuid();
        $status = 500;

        try {
            $response = $next($request);
            $status = $response->getStatusCode();
            $response->headers->set('X-Request-ID', $requestId);

            return $response;
        } finally {
            if (! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
                $this->store($request, $requestId, $status);
            }
        }
    }

    private function store(Request $request, string $requestId, int $status): void
    {
        try {
            if (! Schema::hasTable('auditorias_seguridad')) {
                return;
            }

            DB::table('auditorias_seguridad')->insert([
                'evento_uuid' => $requestId,
                'usuario_id' => $request->user()?->id,
                'rol' => $request->user()?->role,
                'metodo' => $request->method(),
                'ruta' => Str::limit('/'.$request->path(), 255, ''),
                'estado_http' => $status,
                'ip' => Str::limit((string) $request->ip(), 45, ''),
                'agente' => Str::limit((string) $request->userAgent(), 500, ''),
                'creado_en' => now(),
            ]);
        } catch (Throwable $exception) {
            Log::error('No se pudo registrar la auditoria de seguridad.', [
                'request_id' => $requestId,
                'exception' => $exception,
            ]);
        }
    }
}
