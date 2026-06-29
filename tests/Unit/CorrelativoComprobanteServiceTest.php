<?php

namespace Tests\Unit;

use App\Services\Facturacion\CorrelativoComprobanteService;
use PHPUnit\Framework\TestCase;

class CorrelativoComprobanteServiceTest extends TestCase
{
    public function test_reconoce_los_tipos_de_notas_electronicas(): void
    {
        $servicio = new CorrelativoComprobanteService();

        $this->assertSame('07', $servicio->codigoTipo('nota-credito'));
        $this->assertSame('08', $servicio->codigoTipo('nota-debito'));
    }
}
