<?php

declare(strict_types=1);

namespace Tests\Unit\Sql;

use Diogodg\Neoorm\Dialect\DialectFactory;
use Diogodg\Neoorm\Migrations\Exception\MigrationException;
use Diogodg\Neoorm\Migrations\Exception\UnsupportedOperationException;
use Diogodg\Neoorm\Migrations\Operation\OperationList;
use Diogodg\Neoorm\Migrations\Operation\SchemaOperation;
use Diogodg\Neoorm\Schema\Exception\InvalidIdentifierException;
use Diogodg\Neoorm\Schema\Naming\ConstraintNamer;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Tests\Support\Concerns\DialectProviders;
use Tests\Support\Factory\Ops;
use Tests\Support\UnitTestCase;

/**
 * As invariantes que valem para qualquer dialeto, presente ou futuro.
 *
 * Nenhum SQL específico é afirmado aqui — isso é assunto de `MysqlDialectTest` e
 * `PgsqlDialectTest`. Aqui está o contrato: quem implementa `Dialect` traduz todas
 * as operações, cita tudo, e não deixa passar identificador perigoso.
 */
final class DialectContractTest extends UnitTestCase
{
    use DialectProviders;

    /**
     * A guarda de completude do catálogo.
     *
     * Varre o diretório em vez de conferir contra uma constante 20: acrescentar
     * uma operação nova sem acrescentá-la ao catálogo derruba este teste, e é o
     * que impede o conjunto de testes de dialeto de virar uma amostra que
     * envelhece a cada operação nova.
     */
    public function testTheCatalogCoversEverySchemaOperationThatExists(): void
    {
        $catalogClasses = array_map(
            static fn (SchemaOperation $operation): string => $operation::class,
            Ops::catalog(),
        );

        sort($catalogClasses);
        $declared = self::declaredOperationClasses();

        $this->assertSame(
            $declared,
            array_values($catalogClasses),
            'Tests\Support\Factory\Ops::catalog() precisa ter exatamente uma entrada por operação. '
            . 'Faltando: ' . implode(', ', array_diff($declared, $catalogClasses))
            . ' | Sobrando: ' . implode(', ', array_diff($catalogClasses, $declared)),
        );
    }

    /**
     * O conjunto é fechado em 20. O número está escrito aqui porque é uma decisão
     * de design, não um acidente de contagem: cada operação nova é um caso novo em
     * cada dialeto, em cada golden file e no differ.
     */
    public function testTheOperationSetIsClosedAtTwenty(): void
    {
        $this->assertCount(20, self::declaredOperationClasses());
    }

    #[DataProvider('dialects')]
    public function testEveryDialectCompilesEveryOperation(string $dialect): void
    {
        $compiler = DialectFactory::for($dialect);

        foreach (Ops::catalog() as $label => $operation) {
            $statements = $compiler->compile($operation);

            foreach ($statements as $sql) {
                $this->assertNotSame('', trim($sql), "{$dialect}/{$label} produziu statement vazio");
                $this->assertStringNotContainsString(
                    ';',
                    $sql,
                    "{$dialect}/{$label}: o dialeto devolve statements sem ';'. Quem acrescenta o "
                    . 'ponto e vírgula é o SqlWriter, e o runner manda um statement por exec().',
                );
            }
        }
    }

    /**
     * `SetTableOptions` no PostgreSQL é o único caso legítimo de lista vazia — e é
     * uma lista vazia por não haver o conceito, não por falta de implementação.
     */
    #[DataProvider('dialects')]
    public function testOnlyTableOptionsMayCompileToNothing(string $dialect): void
    {
        $compiler = DialectFactory::for($dialect);

        foreach (Ops::catalog() as $label => $operation) {
            if ($compiler->compile($operation) === []) {
                $this->assertSame(
                    'set_table_options',
                    $label,
                    "{$dialect}/{$label} não gerou nenhum statement. Operação que compila para nada "
                    . 'desaparece da migração sem aviso.',
                );
            }
        }
    }

    #[DataProvider('dialects')]
    public function testCompileAllConcatenatesInOrder(string $dialect): void
    {
        $compiler = DialectFactory::for($dialect);
        $catalog = array_values(Ops::catalog());

        $expected = [];

        foreach ($catalog as $operation) {
            foreach ($compiler->compile($operation) as $sql) {
                $expected[] = $sql;
            }
        }

        $this->assertSame($expected, $compiler->compileAll(new OperationList($catalog)));
    }

    /**
     * Nomes entram na DDL, onde não há como parametrizar. Esta lista é o que
     * separa configuração de injeção — e o motivo de a citação ser centralizada em
     * dois métodos em vez de espalhada por cada gerador.
     */
    #[DataProvider('dangerousIdentifiers')]
    public function testDangerousIdentifiersNeverReachTheGeneratedSql(string $identifier): void
    {
        foreach (DialectFactory::all() as $name => $dialect) {
            try {
                $quoted = $dialect->quoteIdentifier($identifier);
                $this->fail("{$name} aceitou o identificador '{$identifier}' e produziu {$quoted}");
            } catch (InvalidIdentifierException $e) {
                $this->assertStringContainsString($identifier, $e->getMessage());
            }
        }
    }

    /**
     * @return iterable<string,array{string}>
     */
    public static function dangerousIdentifiers(): iterable
    {
        yield 'drop table' => ['users; DROP TABLE x'];
        yield 'comentário' => ['users -- '];
        yield 'aspa dupla' => ['us"ers'];
        yield 'crase' => ['us`ers'];
        yield 'aspa simples' => ["us'ers"];
        yield 'espaço' => ['minha tabela'];
        yield 'parêntese' => ['count(*)'];
        yield 'ponto' => ['schema.tabela'];
        yield 'vazio' => [''];
    }

    #[DataProvider('dialects')]
    public function testIdentifiersAreAlwaysQuoted(string $dialect): void
    {
        $compiler = DialectFactory::for($dialect);

        // Uma palavra reservada nas duas engines. Nada de lista de exceções: como
        // todo identificador é citado sempre, `order` e `select` funcionam sem
        // ninguém precisar prever que alguém os usaria.
        $quoted = $compiler->quoteIdentifier('order');

        $this->assertNotSame('order', $quoted);
        $this->assertStringContainsString('order', $quoted);
    }

    #[DataProvider('dialects')]
    public function testIdentifiersBeyondTheEngineLimitAreRejected(string $dialect): void
    {
        $compiler = DialectFactory::for($dialect);
        $tooLong = str_repeat('a', $compiler->maxIdentifierLength() + 1);

        $this->expectException(InvalidIdentifierException::class);
        $compiler->quoteIdentifier($tooLong);
    }

    /**
     * O limite do `ConstraintNamer` é 63, o do PostgreSQL. Se ele fosse maior que o
     * de algum dialeto, um nome gerado passaria pelo namer e seria recusado (ou,
     * pior, truncado em silêncio) na hora de virar SQL.
     */
    #[DataProvider('dialects')]
    public function testGeneratedNamesAlwaysFitTheEngineLimit(string $dialect): void
    {
        $compiler = DialectFactory::for($dialect);

        $this->assertLessThanOrEqual(
            $compiler->maxIdentifierLength(),
            ConstraintNamer::MAX_LENGTH,
            "ConstraintNamer::MAX_LENGTH não cabe no limite de {$dialect}.",
        );

        $name = ConstraintNamer::foreignKey(
            str_repeat('t', 60),
            [str_repeat('c', 60)],
            str_repeat('r', 60),
            ['id'],
        );

        // Não lança: é a asserção.
        $compiler->quoteIdentifier($name);
        $this->addToAssertionCount(1);
    }

    #[DataProvider('dialects')]
    public function testStringLiteralsEscapeTheQuoteCharacter(string $dialect): void
    {
        $compiler = DialectFactory::for($dialect);

        $quoted = $compiler->quoteLiteral("d'água");

        $this->assertStringStartsWith("'", $quoted);
        $this->assertStringEndsWith("'", $quoted);
        $this->assertStringNotContainsString("d'á", $quoted, 'a aspa simples não foi escapada');
    }

    #[DataProvider('dialects')]
    public function testNullAndNumbersAreLiteralsNotStrings(string $dialect): void
    {
        $compiler = DialectFactory::for($dialect);

        $this->assertSame('NULL', $compiler->quoteLiteral(null));
        $this->assertSame('42', $compiler->quoteLiteral(42));
        $this->assertSame('-7', $compiler->quoteLiteral(-7));
        $this->assertSame('0.5', $compiler->quoteLiteral(0.5));
    }

    /**
     * INF e NAN não têm literal em SQL. Deixá-los virar a string "INF" produziria
     * um DEFAULT que o banco recusa, com a mensagem do banco em vez de uma nossa.
     */
    #[DataProvider('dialects')]
    public function testNonFiniteFloatsAreRejected(string $dialect): void
    {
        $compiler = DialectFactory::for($dialect);

        $this->expectException(MigrationException::class);
        $compiler->quoteLiteral(INF);
    }

    /**
     * `var_export` em vez de cast para string: nenhum locale transforma o ponto
     * decimal em vírgula, e o valor sobrevive exato.
     */
    #[DataProvider('dialects')]
    public function testFloatLiteralsNeverUseALocaleDecimalSeparator(string $dialect): void
    {
        $compiler = DialectFactory::for($dialect);

        $this->assertStringNotContainsString(',', $compiler->quoteLiteral(1234.5));
    }

    #[DataProvider('dialects')]
    public function testTheMigrationsTableDdlIsASingleIdempotentStatement(string $dialect): void
    {
        $statements = DialectFactory::for($dialect)->migrationsTableDdl('_neoorm_migrations');

        $this->assertCount(1, $statements);
        $this->assertStringContainsString('IF NOT EXISTS', $statements[0]);
        $this->assertStringContainsString('_neoorm_migrations', $statements[0]);

        // Roda antes de qualquer bookkeeping existir para dizer se já rodou, então
        // ter que ser idempotente é a razão de `tag` ser a chave primária: sem
        // surrogate não sobra unique separado para criar num segundo statement.
        foreach (['tag', 'hash', 'statements', 'applied_index', 'status', 'error'] as $column) {
            $this->assertStringContainsString($column, $statements[0]);
        }
    }

    #[DataProvider('dialects')]
    public function testTheMigrationsTableNameIsValidated(string $dialect): void
    {
        $this->expectException(InvalidIdentifierException::class);
        DialectFactory::for($dialect)->migrationsTableDdl('migrations; DROP TABLE users');
    }

    public function testTheFactoryIsTheOnlyPlaceThatKnowsDriverSpellings(): void
    {
        foreach (['mysql', 'MySQL', 'mariadb'] as $spelling) {
            $this->assertSame('mysql', DialectFactory::for($spelling)->name());
        }

        foreach (['pgsql', 'postgres', 'postgresql', 'PgSQL'] as $spelling) {
            $this->assertSame('pgsql', DialectFactory::for($spelling)->name());
        }
    }

    public function testAnUnknownDriverFailsWithTheSupportedList(): void
    {
        $this->expectException(MigrationException::class);
        $this->expectExceptionMessageMatches('/sqlite.*mysql, pgsql/s');

        DialectFactory::for('sqlite');
    }

    public function testSupportsAnswersWithoutConstructing(): void
    {
        $this->assertTrue(DialectFactory::supports('mysql'));
        $this->assertTrue(DialectFactory::supports('postgres'));
        $this->assertFalse(DialectFactory::supports('sqlite'));
    }

    // ------------------------------------------------------------------- DML

    /**
     * Sem limite e sem offset não há cláusula. Parece óbvio, e é o caso que uma
     * implementação distraída resolve devolvendo `'LIMIT '` com placeholder nulo.
     */
    #[DataProvider('dialects')]
    public function testNoPaginationMeansNoClause(string $dialect): void
    {
        $this->assertSame('', DialectFactory::for($dialect)->limitOffsetClause(null, null));
    }

    /**
     * A invariante que importa: OFFSET sozinho tem que sair em TODO dialeto.
     *
     * O MySQL não sabe dizer isso diretamente e precisa de um limite sentinela.
     * Sem este teste, a saída natural seria devolver string vazia — e uma consulta
     * paginada leria a primeira página em vez da terceira, sem erro nenhum.
     */
    #[DataProvider('dialects')]
    public function testOffsetWithoutLimitIsExpressedInEveryDialect(string $dialect): void
    {
        $clause = DialectFactory::for($dialect)->limitOffsetClause(null, ':p0');

        $this->assertNotSame('', $clause, "{$dialect} engoliu o offset");
        $this->assertStringContainsString(':p0', $clause);
        $this->assertStringContainsString('OFFSET', $clause);
    }

    /**
     * Placeholder é texto que o PDO procura literalmente. Se o dialeto o citasse
     * como identificador — `:p0` virando `` `:p0` `` — o bind nunca casaria, e o
     * erro apareceria como "número de parâmetros inválido", longe da causa.
     */
    #[DataProvider('dialects')]
    public function testPlaceholdersReachTheClauseVerbatim(string $dialect): void
    {
        $clause = DialectFactory::for($dialect)->limitOffsetClause(':lim', ':off');

        $this->assertStringContainsString(':lim', $clause);
        $this->assertStringContainsString(':off', $clause);
        $this->assertLessThan(
            strpos($clause, ':off'),
            strpos($clause, ':lim'),
            "{$dialect} inverteu a ordem: LIMIT vem antes de OFFSET nos dois bancos.",
        );
    }

    #[DataProvider('dialects')]
    public function testCaseInsensitiveLikeUsesBothOperands(string $dialect): void
    {
        $sql = DialectFactory::for($dialect)->caseInsensitiveLike('"nome"', ':p0');

        $this->assertStringContainsString('"nome"', $sql);
        $this->assertStringContainsString(':p0', $sql);
    }

    /**
     * O nome do savepoint sai da profundidade, um inteiro. É o que garante que não
     * existe caminho por onde texto de chamador chegue a um comando que, por ser
     * DDL de transação, não aceita bind.
     */
    #[DataProvider('dialects')]
    public function testSavepointNamesComeFromTheDepth(string $dialect): void
    {
        $compiler = DialectFactory::for($dialect);

        $this->assertStringContainsString('neoorm_sp1', $compiler->savepoint(1));
        $this->assertStringContainsString('neoorm_sp2', $compiler->savepoint(2));

        $this->assertStringStartsWith('SAVEPOINT ', $compiler->savepoint(1));
        $this->assertStringStartsWith('RELEASE SAVEPOINT ', $compiler->releaseSavepoint(1));
        $this->assertStringStartsWith('ROLLBACK TO SAVEPOINT ', $compiler->rollbackToSavepoint(1));
    }

    /**
     * A profundidade 0 é a transação em si, aberta com BEGIN. Aceitar `savepoint(0)`
     * produziria um `neoorm_sp0` que o gerenciador de transação jamais libera — um
     * savepoint órfão segurando recurso até o fim da conexão.
     */
    #[DataProvider('dialects')]
    public function testDepthZeroIsRejected(string $dialect): void
    {
        $this->expectException(UnsupportedOperationException::class);
        DialectFactory::for($dialect)->savepoint(0);
    }

    /**
     * @return list<class-string>
     */
    private static function declaredOperationClasses(): array
    {
        $directory = dirname(__DIR__, 3) . '/src/Migrations/Operation';
        $classes = [];

        foreach ((array) glob($directory . '/*.php') as $file) {
            $class = 'Diogodg\\Neoorm\\Migrations\\Operation\\' . basename((string) $file, '.php');

            if (!class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if ($reflection->implementsInterface(SchemaOperation::class) && !$reflection->isAbstract()) {
                $classes[] = $class;
            }
        }

        sort($classes);

        return $classes;
    }
}
