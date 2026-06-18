<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Boot de traits de teste (RefreshDatabase, etc.) — mantido do pai.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // A imagem Docker de produção (Dockerfile) fixa LOG_CHANNEL=stderr
        // como variável de container, e o Dotenv (modo imutável) dá precedência
        // ao env real — portanto o <env> do phpunit.xml não consegue sobrescrever.
        // Para manter a saída do phpunit limpa, redirecionamos o canal padrão de
        // log para o canal nulo durante os testes. A geração de logs estruturados
        // (stderr JSON) permanece validada por test_webhook_logs_each_received_update.
        config(['logging.default' => 'null']);
    }
}
