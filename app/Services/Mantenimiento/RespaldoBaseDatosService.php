<?php

namespace App\Services\Mantenimiento;

use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class RespaldoBaseDatosService
{
    private const EXTENSION = '.pfbak';

    public function crear(string $tipo = 'manual'): array
    {
        $tipos = ['manual', 'diario', 'semanal', 'mensual', 'pre-restauracion', 'pre-reinicio'];
        if (! in_array($tipo, $tipos, true)) {
            throw new RuntimeException('El tipo de respaldo no es valido.');
        }

        $temporal = tempnam(sys_get_temp_dir(), 'pollo-fresco-');
        if ($temporal === false) {
            throw new RuntimeException('No se pudo preparar el archivo temporal del respaldo.');
        }

        try {
            $this->ejecutarDump($temporal);
            $sql = file_get_contents($temporal);
            if ($sql === false || $sql === '') {
                throw new RuntimeException('MySQL genero un respaldo vacio.');
            }

            $comprimido = gzencode($sql, 9);
            if ($comprimido === false) {
                throw new RuntimeException('No se pudo comprimir el respaldo.');
            }

            $nombre = sprintf(
                'pollo-fresco_%s_%s_%s%s',
                $tipo,
                now()->format('Ymd_His'),
                bin2hex(random_bytes(3)),
                self::EXTENSION
            );
            $ruta = $this->directorio().'/'.$nombre;
            $cifrado = Crypt::encryptString($comprimido);
            Storage::disk('local')->put($ruta, $cifrado);
            $discoExterno = trim((string) config('mantenimiento.disco_externo'));
            if ($discoExterno !== '') {
                $directorioExterno = trim((string) config('mantenimiento.directorio_externo'), '/');
                Storage::disk($discoExterno)->put($directorioExterno.'/'.$nombre, $cifrado);
            }

            $this->aplicarRetencion($tipo);

            return $this->informacionArchivo($nombre);
        } finally {
            @unlink($temporal);
        }
    }

    public function listar(): array
    {
        $archivos = Storage::disk('local')->files($this->directorio());
        $respaldos = [];

        foreach ($archivos as $ruta) {
            if (! str_ends_with($ruta, self::EXTENSION)) {
                continue;
            }
            $respaldos[] = $this->informacionArchivo(basename($ruta));
        }

        usort($respaldos, fn (array $a, array $b) => strcmp($b['creado_en'], $a['creado_en']));

        return $respaldos;
    }

    public function rutaDescarga(string $archivo): string
    {
        $nombre = $this->validarNombre($archivo);
        $ruta = $this->directorio().'/'.$nombre;
        if (! Storage::disk('local')->exists($ruta)) {
            throw new RuntimeException('El respaldo solicitado no existe.');
        }

        return Storage::disk('local')->path($ruta);
    }

    public function restaurar(string $archivo, User $administrador): array
    {
        $nombre = $this->validarNombre($archivo);
        $ruta = $this->directorio().'/'.$nombre;
        if (! Storage::disk('local')->exists($ruta)) {
            throw new RuntimeException('El respaldo seleccionado no existe.');
        }

        $respaldoSeguridad = $this->crear('pre-restauracion');
        $temporal = tempnam(sys_get_temp_dir(), 'pollo-fresco-restore-');
        if ($temporal === false) {
            throw new RuntimeException('No se pudo preparar la restauracion.');
        }

        $atributosAdministrador = $administrador->getAttributes();
        unset($atributosAdministrador['rol_activo_id']);

        try {
            $cifrado = Storage::disk('local')->get($ruta);
            $comprimido = Crypt::decryptString($cifrado);
            $sql = gzdecode($comprimido);
            if ($sql === false || $sql === '') {
                throw new RuntimeException('El respaldo esta danado o no contiene datos validos.');
            }
            file_put_contents($temporal, $sql, LOCK_EX);
            unset($sql, $comprimido, $cifrado);

            $this->ejecutarRestauracion($temporal);
            DB::table('usuarios')
                ->where('usuario_id', '<>', $atributosAdministrador['usuario_id'])
                ->where(function ($consulta) use ($atributosAdministrador) {
                    $consulta->where('usuario', $atributosAdministrador['usuario'])
                        ->orWhere('email', $atributosAdministrador['email']);
                })
                ->delete();
            DB::table('usuarios')->updateOrInsert(
                ['usuario_id' => $atributosAdministrador['usuario_id']],
                $atributosAdministrador
            );
            if (DB::getSchemaBuilder()->hasTable('personal_access_tokens')) {
                DB::table('personal_access_tokens')->truncate();
            }

            return [
                'restaurado' => $nombre,
                'respaldo_seguridad' => $respaldoSeguridad['archivo'],
            ];
        } finally {
            @unlink($temporal);
        }
    }

    public function reiniciarDatos(User $administrador): array
    {
        $respaldoSeguridad = $this->crear('pre-reinicio');
        $idAdministrador = (int) $administrador->getKey();
        $tablasProtegidas = ['migrations', 'roles', 'usuarios', 'mantenimiento_auditorias', 'auditorias_seguridad'];
        $tablas = $this->tablasBaseDatos();

        DB::unprepared('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach ($tablas as $tabla) {
                if (in_array($tabla, $tablasProtegidas, true)) {
                    continue;
                }
                DB::statement('TRUNCATE TABLE '.$this->escaparIdentificador($tabla));
            }

            DB::table('usuarios')->where('usuario_id', '<>', $idAdministrador)->delete();
            DB::table('usuarios')->where('usuario_id', $idAdministrador)->update(['activo' => 1]);
            DB::statement('ALTER TABLE usuarios AUTO_INCREMENT = '.($idAdministrador + 1));
        } finally {
            DB::unprepared('SET FOREIGN_KEY_CHECKS=1');
        }

        return [
            'administrador_conservado' => $administrador->usuario,
            'respaldo_seguridad' => $respaldoSeguridad['archivo'],
        ];
    }

    private function ejecutarDump(string $destino): void
    {
        $archivo = fopen($destino, 'wb');
        if ($archivo === false) {
            throw new RuntimeException('No se pudo crear el archivo temporal del respaldo.');
        }

        $pdo = DB::connection()->getPdo();
        fwrite($archivo, "-- Pollo Fresco - respaldo seguro\n");
        fwrite($archivo, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");

        DB::beginTransaction();
        try {
            foreach ($this->tablasBaseDatos() as $tabla) {
                $identificador = $this->escaparIdentificador($tabla);
                $crear = DB::selectOne('SHOW CREATE TABLE '.$identificador);
                $valoresCrear = array_values((array) $crear);
                $sqlCrear = (string) ($valoresCrear[1] ?? '');
                if ($sqlCrear === '') {
                    throw new RuntimeException('No se pudo leer la estructura de la tabla '.$tabla.'.');
                }

                fwrite($archivo, 'DROP TABLE IF EXISTS '.$identificador.";\n");
                fwrite($archivo, $sqlCrear.";\n\n");

                $columnas = DB::select('SHOW COLUMNS FROM '.$identificador);
                $tipos = [];
                foreach ($columnas as $columna) {
                    $tipos[(string) $columna->Field] = (string) $columna->Type;
                }
                $nombresColumnas = array_keys($tipos);

                $consulta = $pdo->query('SELECT * FROM '.$identificador);
                $filas = [];
                while ($fila = $consulta->fetch(\PDO::FETCH_ASSOC)) {
                    $filas[] = $this->filaSql($fila, $tipos);
                    if (count($filas) >= 100) {
                        $this->escribirInsert($archivo, $identificador, $nombresColumnas, $filas);
                        $filas = [];
                    }
                }
                if ($filas !== []) {
                    $this->escribirInsert($archivo, $identificador, $nombresColumnas, $filas);
                }
                fwrite($archivo, "\n");
            }
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        } finally {
            fwrite($archivo, "SET FOREIGN_KEY_CHECKS=1;\n");
            fclose($archivo);
        }
    }

    private function ejecutarRestauracion(string $origen): void
    {
        $archivo = fopen($origen, 'rb');
        if ($archivo === false) {
            throw new RuntimeException('No se pudo leer el respaldo para restaurarlo.');
        }

        $sentencia = '';
        try {
            while (($linea = fgets($archivo)) !== false) {
                if ($sentencia === '' && (str_starts_with(trim($linea), '--') || trim($linea) === '')) {
                    continue;
                }

                $sentencia .= $linea;
                if (! preg_match('/;\s*$/', $sentencia)) {
                    continue;
                }

                DB::unprepared($sentencia);
                $sentencia = '';
            }

            if (trim($sentencia) !== '') {
                throw new RuntimeException('El respaldo contiene una sentencia SQL incompleta.');
            }
        } finally {
            fclose($archivo);
        }
    }

    private function filaSql(array $fila, array $tipos): string
    {
        $valores = [];
        foreach ($fila as $columna => $valor) {
            if ($valor === null) {
                $valores[] = 'NULL';
                continue;
            }

            $tipo = strtolower((string) ($tipos[$columna] ?? ''));
            $esNumerico = preg_match('/^(tinyint|smallint|mediumint|int|bigint|decimal|numeric|float|double|real|bit)/', $tipo) === 1;
            if ($esNumerico && is_numeric($valor)) {
                $valores[] = (string) $valor;
                continue;
            }

            $valores[] = '0x'.bin2hex((string) $valor);
        }

        return '('.implode(',', $valores).')';
    }

    private function escribirInsert(mixed $archivo, string $tabla, array $columnas, array $filas): void
    {
        $columnasSql = implode(',', array_map(fn (string $columna) => $this->escaparIdentificador($columna), $columnas));
        fwrite($archivo, 'INSERT INTO '.$tabla.' ('.$columnasSql.') VALUES '.implode(',', $filas).";\n");
    }

    private function conexion(): array
    {
        $conexion = config('database.connections.'.config('database.default'));
        if (($conexion['driver'] ?? null) !== 'mysql') {
            throw new RuntimeException('El mantenimiento automatico esta disponible solo para MySQL.');
        }

        return [
            'host' => (string) ($conexion['host'] ?? '127.0.0.1'),
            'port' => (string) ($conexion['port'] ?? '3306'),
            'database' => (string) ($conexion['database'] ?? ''),
            'username' => (string) ($conexion['username'] ?? ''),
            'password' => (string) ($conexion['password'] ?? ''),
        ];
    }

    private function informacionArchivo(string $nombre): array
    {
        $ruta = $this->directorio().'/'.$nombre;
        $tipo = 'manual';
        if (preg_match('/^pollo-fresco_([a-z-]+)_/', $nombre, $coincidencias)) {
            $tipo = $coincidencias[1];
        }

        return [
            'archivo' => $nombre,
            'tipo' => $tipo,
            'tamano_bytes' => Storage::disk('local')->size($ruta),
            'creado_en' => date(DATE_ATOM, Storage::disk('local')->lastModified($ruta)),
            'checksum' => hash_file('sha256', Storage::disk('local')->path($ruta)),
        ];
    }

    private function aplicarRetencion(string $tipo): void
    {
        $limite = config('mantenimiento.retencion.'.$tipo);
        if (! is_int($limite) || $limite < 1) {
            return;
        }

        $archivos = array_values(array_filter(
            Storage::disk('local')->files($this->directorio()),
            fn (string $ruta) => str_starts_with(basename($ruta), 'pollo-fresco_'.$tipo.'_')
        ));
        usort($archivos, fn (string $a, string $b) => Storage::disk('local')->lastModified($b) <=> Storage::disk('local')->lastModified($a));

        foreach (array_slice($archivos, $limite) as $archivo) {
            Storage::disk('local')->delete($archivo);
        }
    }

    private function tablasBaseDatos(): array
    {
        $baseDatos = $this->conexion()['database'];
        $filas = DB::select('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = ?', [$baseDatos, 'BASE TABLE']);

        return array_map(fn (object $fila) => (string) $fila->TABLE_NAME, $filas);
    }

    private function escaparIdentificador(string $nombre): string
    {
        return '`'.str_replace('`', '``', $nombre).'`';
    }

    private function validarNombre(string $archivo): string
    {
        $nombre = basename($archivo);
        if ($nombre !== $archivo || ! preg_match('/^pollo-fresco_[a-z-]+_[0-9]{8}_[0-9]{6}_[a-f0-9]{6}\.pfbak$/', $nombre)) {
            throw new RuntimeException('El nombre del respaldo no es valido.');
        }

        return $nombre;
    }

    private function directorio(): string
    {
        return trim((string) config('mantenimiento.directorio_respaldos', 'backups/base-datos'), '/');
    }
}
