<?php

declare(strict_types=1);

namespace Tests\Integration\RoundTrip;

use Diogodg\Neoorm\Connection;
use Diogodg\Neoorm\DatabaseConfig;
use Diogodg\Neoorm\Dialect\DialectFactory;
use Diogodg\Neoorm\Migrations\Diff\SchemaDiffer;
use Diogodg\Neoorm\Migrations\Introspection\Introspector;
use Diogodg\Neoorm\Migrations\Runner\PdoExecutor;
use Diogodg\Neoorm\Migrations\SchemaApplier;
use Diogodg\Neoorm\Migrations\Snapshot\Snapshot;
use Diogodg\Neoorm\Schema\SchemaDefinition;
use Diogodg\Neoorm\Schema\SchemaValidator;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Concerns\IrAssertions;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\EdgeCaseSchemas;
use Tests\Support\SchemaFixture;
use Throwable;

/**
 * P3 — o teste-manchete.
 *
 * Aplica um schema num banco vazio, lê o banco de volta, e afirma duas coisas sobre o
 * resultado. As duas, e não uma:
 *
 * `assertSchemaEquals` pega assimetria de REPRESENTAÇÃO — `INT` contra `INTEGER`,
 * `VARCHAR` sem tamanho contra `VARCHAR(120)`, comentário que sumiu.
 *
 * `differ(introspectado, declarado)` vazio pega assimetria SEMÂNTICA que o normalizador
 * pode ter encoberto: um campo que o differ ignora e não deveria passaria pela primeira
 * asserção sem ser notado.
 *
 * Este é o teste que teria pegado praticamente todos os bugs do sistema antigo. B7 —
 * comentário e tamanho de coluna perdidos em TODA coluna dos dez models no PostgreSQL —
 * falharia na primeira asserção. B1 — índices nunca criados — falharia na segunda. B5 —
 * três das quatro foreign keys de `appointment` sumindo do rastreamento — falharia nas
 * duas.
 */
#[Group('roundtrip')]
final class RoundTripTest extends DatabaseTestCase
{
    use IrAssertions;

    /**
     * Schemas que o dialeto corrente consegue expressar.
     *
     * `type_variants` tem JSON, que os dois têm; nada aqui usa tipo específico de um
     * banco só. Um schema que o dialeto recusa é filtrado pelo validador em vez de
     * falhar — e o teste diz qual foi filtrado, para a filtragem não crescer sem
     * ninguém ver.
     *
     * @return iterable<string,array{string}>
     */
    public static function schemaNames(): iterable
    {
        foreach (array_keys(EdgeCaseSchemas::all()) as $name) {
            if ($name === 'empty') {
                continue;
            }

            yield $name => [$name];
        }
    }

    #[DataProvider('schemaNames')]
    public function testASchemaAppliedToAnEmptyDatabaseReadsBackIdentical(string $name): void
    {
        $schema = EdgeCaseSchemas::get($name);
        $dialect = DialectFactory::for($this->driver());

        $errors = (new SchemaValidator())->validate($schema, $dialect->name());

        if ($errors !== []) {
            self::markTestSkipped(
                "O schema '{$name}' não é válido em {$dialect->name()}: " . implode('; ', $errors),
            );
        }

        $this->dropTables($schema);

        try {
            $operations = (new SchemaDiffer())->diff(
                Snapshot::baseline($dialect->name()),
                Snapshot::initial($dialect->name(), $schema),
            );

            (new SchemaApplier($dialect, new PdoExecutor($this->pdo())))->apply($operations);

            $introspected = (new Introspector($this->pdo(), DatabaseConfig::fromConfig()))
                ->introspect($schema->tableNames());

            // Primeira asserção: a forma. Reporta só os caminhos divergentes, senão a
            // falha de um IR de dez tabelas é ilegível.
            //
            // O lado esperado passa por `normalizeForStorage`, que descarta o que o
            // dialeto não sabe guardar — `engine` e `collation` de tabela no PostgreSQL.
            // Não é concessão do teste: é o mesmo passo que `migration:generate` aplica
            // antes de escrever o snapshot, justamente para que os dois bancos convirjam
            // a partir dos mesmos models.
            $this->assertSchemaEqualsIgnoringCheckExpressions(
                $dialect->normalizeForStorage($schema),
                $introspected->schema,
                "O schema '{$name}' não voltou igual do banco {$dialect->name()}.",
            );

            // Segunda asserção: a semântica. Não é redundante — pega o campo que o
            // differ ignora e não deveria.
            $this->assertNoOperations(
                (new SchemaDiffer())->diff(
                    $introspected,
                    Snapshot::initial($dialect->name(), $dialect->normalizeForStorage($schema)),
                ),
                "O differ acusa diferença entre o schema '{$name}' e o banco onde ele acabou de ser aplicado.",
            );
        } finally {
            $this->dropTables($schema);
        }
    }

    /**
     * O ciclo incremental, que a criação do zero não cobre.
     *
     * Um round-trip só de "criar tudo de uma vez" passaria mesmo com B2 e B4 presentes:
     * o diff fantasma de collation só aparece na SEGUNDA comparação, e o
     * `ADD CONSTRAINT` sem idempotência só estoura na SEGUNDA aplicação. Aqui o schema
     * evolui — v1, aplica, compara (tem que estar vazio), v2, aplica, compara.
     */
    public function testAnIncrementalChangeConvergesAndStaysConverged(): void
    {
        $dialect = DialectFactory::for($this->driver());
        $applier = new SchemaApplier($dialect, new PdoExecutor($this->pdo()));
        $differ = new SchemaDiffer();

        $version1 = EdgeCaseSchemas::get('geography');
        $version2 = $this->geographyWithAnExtraColumnAndIndex();

        $this->dropTables($version2);

        try {
            $applier->apply($differ->diff(
                Snapshot::baseline($dialect->name()),
                Snapshot::initial($dialect->name(), $version1),
            ));

            $afterFirst = $this->introspect($version1);
            $this->assertNoOperations(
                $differ->diff($afterFirst, Snapshot::initial($dialect->name(), $version1)),
                'A primeira aplicação não convergiu.',
            );

            $operations = $differ->diff($afterFirst, Snapshot::initial($dialect->name(), $version2));
            $this->assertFalse($operations->isEmpty(), 'A segunda versão do schema não gerou operação nenhuma.');

            $applier->apply($operations);

            $afterSecond = $this->introspect($version2);
            $this->assertSchemaEquals($version2, $afterSecond->schema);
            $this->assertNoOperations(
                $differ->diff($afterSecond, Snapshot::initial($dialect->name(), $version2)),
                'A mudança incremental não convergiu.',
            );
        } finally {
            $this->dropTables($version2);
        }
    }

    /**
     * O texto da expressão não fecha o round-trip, mas o CONJUNTO de CHECKs fecha.
     *
     * Vale afirmar separadamente: se a introspecção perdesse um CHECK inteiro, o teste de
     * forma — que ignora o texto — não notaria. Aqui a afirmação é sobre existência e
     * nome, que é a parte confiável.
     */
    public function testTheSetOfChecksRoundTripsEvenThoughTheExpressionTextDoesNot(): void
    {
        $schema = EdgeCaseSchemas::get('check_variants');
        $dialect = DialectFactory::for($this->driver());

        $this->dropTables($schema);

        try {
            (new SchemaApplier($dialect, new PdoExecutor($this->pdo())))->apply(
                (new SchemaDiffer())->diff(
                    Snapshot::baseline($dialect->name()),
                    Snapshot::initial($dialect->name(), $schema),
                ),
            );

            $introspected = $this->introspect($schema);

            // Ordenados: a afirmação é sobre o CONJUNTO. O lado declarado vem em ordem
            // de declaração e o catálogo devolve em ordem de nome, e nenhuma das duas é
            // significativa — só `columnOrder` é uma lista ordenada de verdade no IR.
            $declared = array_keys($schema->tables['product']->checks);
            $found = array_keys($introspected->table('product')?->checks ?? []);
            sort($declared);
            sort($found);

            $this->assertSame($declared, $found);
        } finally {
            $this->dropTables($schema);
        }
    }

    /**
     * O schema de domínio dos models, lido de volta.
     *
     * Diferente dos schemas sintéticos: este passa pelos builders, pelo DSL que os
     * models de verdade usam, com `isAutoIncrement()` no nível da tabela e
     * `addForeignKey()` chamado no meio da lista de colunas.
     */
    public function testTheDomainSchemaFromTheModelsReadsBackIdentical(): void
    {
        // Força a remontagem: os schemas sintéticos desta classe usam `city`, `state` e
        // `country`, que são também nomes de tabela do domínio, e cada caso os derruba no
        // finally. Sem isto, este teste passaria ou falharia conforme a ordem de execução
        // — que é exatamente o tipo de dependência que a ordem aleatória existe para
        // expor.
        SchemaFixture::forget();
        SchemaFixture::ensure();

        $declared = SchemaFixture::schema();
        $introspected = $this->introspect($declared);

        $this->assertSchemaEquals($declared, $introspected->schema);
        $this->assertNoOperations(
            (new SchemaDiffer())->diff(
                $introspected,
                Snapshot::initial($this->driver(), $declared),
            ),
        );
    }

    /**
     * Reaplicar o mesmo diff é no-op porque o diff fica vazio.
     *
     * É a regressão de B4: `addForeignKeytoTable()` rodava `ALTER TABLE ADD CONSTRAINT`
     * sem idempotência, para TODAS as tabelas, em toda execução — e a segunda execução
     * do migrate estourava com "constraint já existe".
     */
    public function testApplyingTwiceIsANoOpInsteadOfFailingOnDuplicateConstraints(): void
    {
        SchemaFixture::forget();
        SchemaFixture::ensure();

        $declared = SchemaFixture::schema();
        $dialect = DialectFactory::for($this->driver());

        for ($pass = 1; $pass <= 3; $pass++) {
            $operations = (new SchemaDiffer())->diff(
                $this->introspect($declared),
                Snapshot::initial($dialect->name(), $declared),
            );

            $this->assertNoOperations($operations, "A execução número {$pass} gerou operações.");
        }
    }

    /**
     * Deixa o banco utilizável para a classe seguinte.
     *
     * Os casos desta classe derrubam tabelas cujos nomes coincidem com os do schema de
     * domínio. Esquecer o fixture faz a próxima classe que precisar dele remontá-lo.
     */
    public static function tearDownAfterClass(): void
    {
        SchemaFixture::forget();
    }

    private function introspect(SchemaDefinition $schema): Snapshot
    {
        return (new Introspector($this->pdo(), DatabaseConfig::fromConfig()))
            ->introspect($schema->tableNames());
    }

    private function geographyWithAnExtraColumnAndIndex(): SchemaDefinition
    {
        $geography = EdgeCaseSchemas::get('geography');
        $city = $geography->tables['city'];

        return $geography->withTable(new \Diogodg\Neoorm\Schema\TableDefinition(
            name: 'city',
            columns: [
                ...array_values($city->getColumns()),
                \Tests\Support\Factory\Ir::column('population', 'INT', comment: 'Habitantes'),
            ],
            primaryKey: $city->primaryKey,
            uniqueConstraints: array_values($city->uniqueConstraints),
            // Índice de UMA coluna: era proibido pelo builder antigo, e nunca chegava a
            // ser emitido num schema já existente.
            indexes: [\Tests\Support\Factory\Ir::index('city', ['name'])],
            foreignKeys: array_values($city->foreignKeys),
            checks: array_values($city->checks),
            comment: $city->comment,
            options: $city->options,
        ));
    }

    /**
     * Limpeza tolerante. `IF EXISTS` aqui é legítimo: não é uma migração descrevendo uma
     * transição, é um teste garantindo que começa do zero.
     *
     * A ordem é a inversa da dependência, e o MySQL ainda precisa que a verificação de
     * foreign key esteja desligada por causa das tabelas com referência mútua.
     */
    private function dropTables(SchemaDefinition $schema): void
    {
        $pdo = $this->pdo();
        $dialect = DialectFactory::for($this->driver());
        $previous = $pdo->getAttribute(PDO::ATTR_ERRMODE);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);

        if ($this->isMysql()) {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        }

        try {
            foreach (array_reverse($schema->tableNames()) as $table) {
                $suffix = $this->isPgsql() ? ' CASCADE' : '';
                $pdo->exec('DROP TABLE IF EXISTS ' . $dialect->quoteIdentifier($table) . $suffix);
            }
        } finally {
            if ($this->isMysql()) {
                $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            }

            $pdo->setAttribute(PDO::ATTR_ERRMODE, $previous);
        }
    }
}
