<?php

declare(strict_types=1);

namespace Tests\Support;

use Diogodg\Neoorm\Connection;
use PHPUnit\Framework\TestCase;

/**
 * Base de todo teste que não pode tocar o banco.
 *
 * A guarda do tearDown é o que dá dente à regra "o banco é opt-in": a
 * arquitetura antiga abria conexão dentro do construtor de Table, e por isso
 * nada de definição de schema era testável isoladamente. Aqui, se algum caminho
 * novo voltar a abrir socket, o teste que causou isso falha — não um teste
 * distante, depois, por efeito colateral.
 */
abstract class UnitTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Connection::close();
    }

    protected function tearDown(): void
    {
        $opened = Connection::isOpen();

        Connection::close();

        $this->assertFalse(
            $opened,
            'Teste unitário abriu conexão com o banco. Definir schema, serializar '
            . 'snapshot, diferenciar e gerar SQL têm que funcionar offline.',
        );

        parent::tearDown();
    }
}
