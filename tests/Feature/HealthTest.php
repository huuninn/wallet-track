<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthTest extends TestCase
{
    /**
     * CT-M0-01 (M0 smoke test): endpoint /health retorna 200 e JSON válido.
     *
     * Garante que o health check mínimo exigido pelo plano de implementação
     * (§M0.8) está acessível e retorna a estrutura esperada para uptime
     * checks do orquestrador (VPS/Octane worker).
     */
    public function test_health_endpoint_returns_ok_status(): void
    {
        $response = $this->getJson('/health');

        $response->assertOk();
        $response->assertJsonStructure([
            'status',
            'timestamp',
            'version',
            'app',
        ]);
        $response->assertJson(['status' => 'ok']);
    }
}
