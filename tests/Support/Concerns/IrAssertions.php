<?php

declare(strict_types=1);

namespace Tests\Support\Concerns;

use Diogodg\Neoorm\Migrations\Operation\OperationList;
use Diogodg\Neoorm\Migrations\Snapshot\Snapshot;
use Diogodg\Neoorm\Schema\SchemaDefinition;
use Diogodg\Neoorm\Schema\TableDefinition;
use Tests\Support\ArrayPath;

trait IrAssertions
{
    /**
     * Compara dois schemas pelas MESMAS regras que o differ usa.
     *
     * `toComparableArray()` é código de produção, não massagem de teste: a única
     * diferença em relação a `toArray()` é que a expressão de cada CHECK vem
     * normalizada, porque o PostgreSQL reescreve expressões ao armazená-las e essa
     * reescrita não é reversível. Afirmar sobre a forma crua faria o teste exigir do
     * banco algo que ele nunca devolve.
     */
    final protected function assertSchemaEquals(
        SchemaDefinition $expected,
        SchemaDefinition $actual,
        string $message = '',
    ): void {
        [$left, $right] = self::alignUnspecifiedOptions(
            $expected->toComparableArray(),
            $actual->toComparableArray(),
        );

        $this->assertIrArraysEqual($left, $right, $message);
    }

    /**
     * Opções de tabela têm semântica de TRÊS valores.
     *
     * `null` em `engine` ou `collation` não é um valor: quer dizer "não escolhido", e é
     * essa regra que impede o diff fantasma de collation (o bug B2). Comparar "não
     * escolhido" com "o valor que o servidor acabou usando" por igualdade é erro de
     * categoria, não divergência.
     *
     * A afirmação correta é mais estreita, e é esta: onde o lado esperado diz algo, o banco
     * tem que concordar; onde ele não diz nada, não há o que afirmar. É o mesmo critério
     * que `SchemaDiffer::optionsChange()` aplica.
     *
     * @param array<string,mixed> $expected
     * @param array<string,mixed> $actual
     * @return array{array<string,mixed>,array<string,mixed>}
     */
    private static function alignUnspecifiedOptions(array $expected, array $actual): array
    {
        foreach ($expected as $table => $definition) {
            foreach (['engine', 'collation'] as $option) {
                if (($definition['options'][$option] ?? null) === null && isset($actual[$table])) {
                    $actual[$table]['options'][$option] = null;
                }
            }
        }

        return [$expected, $actual];
    }

    /**
     * Compara dois schemas ignorando o TEXTO das expressões de CHECK.
     *
     * Isto não é normalização escondendo um bug — é a afirmação exata do que fecha o
     * round-trip e do que não fecha. O PostgreSQL não guarda a expressão que recebeu: ele
     * a reescreve. `status in ('novo','usado')` volta do catálogo como
     * `status = ANY (ARRAY['novo','usado'])`. Não é formatação, é outra árvore sintática
     * com o mesmo significado, e reverter isso exigiria um otimizador de consultas.
     *
     * O que este método continua afirmando é tudo o mais, inclusive que o CONJUNTO de
     * CHECKs e seus nomes voltaram iguais. O que ele deixa de afirmar é o texto — e a
     * consequência disso está encapsulada no differ, que trata divergência de expressão de
     * CHECK como advisória quando um dos lados veio de um banco, e por isso nunca gera um
     * ALTER a partir de um falso positivo conhecido.
     */
    final protected function assertSchemaEqualsIgnoringCheckExpressions(
        SchemaDefinition $expected,
        SchemaDefinition $actual,
        string $message = '',
    ): void {
        $blank = static function (SchemaDefinition $schema): array {
            $data = $schema->toComparableArray();

            foreach ($data as $table => $definition) {
                foreach (array_keys($definition['checks']) as $check) {
                    $data[$table]['checks'][$check]['expression'] = '<expressão não comparada>';
                }
            }

            return $data;
        };

        [$left, $right] = self::alignUnspecifiedOptions($blank($expected), $blank($actual));

        $this->assertIrArraysEqual($left, $right, $message);
    }

    final protected function assertTableEquals(
        TableDefinition $expected,
        TableDefinition $actual,
        string $message = '',
    ): void {
        $this->assertIrArraysEqual($expected->toComparableArray(), $actual->toComparableArray(), $message);
    }

    final protected function assertSnapshotSchemaEquals(
        Snapshot $expected,
        Snapshot $actual,
        string $message = '',
    ): void {
        $this->assertSchemaEquals($expected->schema, $actual->schema, $message);
    }

    /**
     * @param array<array-key,mixed> $expected
     * @param array<array-key,mixed> $actual
     */
    final protected function assertIrArraysEqual(array $expected, array $actual, string $message = ''): void
    {
        $differences = ArrayPath::describeDifferences($expected, $actual);

        if ($differences !== '') {
            $this->fail(($message !== '' ? $message . "\n" : '') . $differences);
        }

        $this->addToAssertionCount(1);
    }

    /**
     * A lista de operações lida como texto.
     *
     * Comparar `describe()` em vez dos objetos é deliberado: a expectativa do teste
     * fica legível, e a legibilidade é o que torna revisável um comportamento com
     * tantos casos quanto o do differ.
     *
     * @param list<string> $expected
     */
    final protected function assertOperationsAre(
        array $expected,
        OperationList $operations,
        string $message = '',
    ): void {
        $this->assertSame(
            $expected,
            $operations->describe(),
            $message !== '' ? $message : "Operações geradas:\n  " . implode("\n  ", $operations->describe()),
        );
    }

    final protected function assertNoOperations(OperationList $operations, string $message = ''): void
    {
        $this->assertSame(
            [],
            $operations->describe(),
            ($message !== '' ? $message . "\n" : '')
            . "Esperava nenhuma operação, veio:\n  " . implode("\n  ", $operations->describe()),
        );
    }
}
