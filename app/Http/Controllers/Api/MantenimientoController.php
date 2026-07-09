<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Mantenimiento\RespaldoBaseDatosService;
use Illuminate\Support\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use RuntimeException;
use Throwable;

class MantenimientoController extends Controller
{
    public function __construct(private readonly RespaldoBaseDatosService $respaldos)
    {
    }

    public function index(Request $request)
    {
        $this->autorizarAdministrador($request);

        return response()->json([
            'respaldos' => $this->respaldos->listar(),
            'auditoria' => DB::table('mantenimiento_auditorias')
                ->orderByDesc('creado_en')
                ->limit(30)
                ->get()
                ->map(function (object $registro) {
                    $registro->creado_en = Carbon::parse($registro->creado_en, 'UTC')->toIso8601String();
                    return $registro;
                }),
            'programacion' => [
                'diario' => '02:00',
                'semanal' => 'Domingo 02:30',
                'mensual' => 'Dia 1 a las 03:00',
            ],
        ]);
    }

    public function crearRespaldo(Request $request)
    {
        $this->autorizarAdministrador($request);

        try {
            $respaldo = $this->respaldos->crear('manual');
            $this->auditar($request, 'crear_respaldo', $respaldo['archivo']);

            return response()->json(['message' => 'Respaldo cifrado creado correctamente.', 'respaldo' => $respaldo], 201);
        } catch (Throwable $e) {
            $this->auditar($request, 'crear_respaldo', null, 'fallido', $e->getMessage());
            throw $e;
        }
    }

    public function descargar(Request $request, string $archivo)
    {
        $this->autorizarAdministrador($request);
        $ruta = $this->respaldos->rutaDescarga($archivo);
        $this->auditar($request, 'descargar_respaldo', $archivo);

        return response()->download($ruta, $archivo, ['Content-Type' => 'application/octet-stream']);
    }

    public function restaurar(Request $request, string $archivo)
    {
        $this->autorizarAdministrador($request);
        $datos = $request->validate([
            'password' => ['required', 'string', 'max:255'],
            'confirmacion' => ['required', Rule::in(['RESTAURAR'])],
        ]);
        $this->validarPassword($request, $datos['password']);

        try {
            $resultado = $this->respaldos->restaurar($archivo, $request->user());
            $this->auditar($request, 'restaurar_respaldo', $archivo, 'completado', 'Respaldo de seguridad: '.$resultado['respaldo_seguridad']);

            return response()->json([
                'message' => 'Base de datos restaurada. Inicia sesion nuevamente.',
                'resultado' => $resultado,
                'cerrar_sesion' => true,
            ]);
        } catch (Throwable $e) {
            $this->auditar($request, 'restaurar_respaldo', $archivo, 'fallido', $e->getMessage());
            throw $e;
        }
    }

    public function reiniciar(Request $request)
    {
        $this->autorizarAdministrador($request);
        $datos = $request->validate([
            'password' => ['required', 'string', 'max:255'],
            'confirmacion' => ['required', Rule::in(['REINICIAR'])],
        ]);
        $this->validarPassword($request, $datos['password']);

        try {
            $resultado = $this->respaldos->reiniciarDatos($request->user());
            $this->auditar($request, 'reiniciar_datos', $resultado['respaldo_seguridad'], 'completado', 'Administrador conservado: '.$resultado['administrador_conservado']);

            return response()->json([
                'message' => 'Datos operativos eliminados. El administrador fue conservado.',
                'resultado' => $resultado,
                'cerrar_sesion' => true,
            ]);
        } catch (Throwable $e) {
            $this->auditar($request, 'reiniciar_datos', null, 'fallido', $e->getMessage());
            throw $e;
        }
    }

    private function autorizarAdministrador(Request $request): void
    {
        abort_unless($request->user()?->role === 'admin', 403, 'Solo un administrador puede usar el mantenimiento.');
    }

    private function validarPassword(Request $request, string $password): void
    {
        abort_unless(Hash::check($password, (string) $request->user()?->password_hash), 422, 'La contrasena del administrador no es correcta.');
    }

    private function auditar(Request $request, string $accion, ?string $archivo, string $estado = 'completado', ?string $detalle = null): void
    {
        try {
            DB::table('mantenimiento_auditorias')->insert([
                'usuario_id' => $request->user()?->usuario_id,
                'usuario' => $request->user()?->usuario,
                'accion' => $accion,
                'archivo' => $archivo,
                'estado' => $estado,
                'detalle' => $detalle,
                'ip' => $request->ip(),
                'creado_en' => now(),
            ]);
        } catch (Throwable) {
            // La operacion principal no debe quedar oculta por un fallo secundario de auditoria.
        }
    }
}
