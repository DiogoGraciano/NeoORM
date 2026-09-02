<?php

declare(strict_types=1);

namespace Tests\Unit\Command;

use Diogodg\Neoorm\DatabaseConfig;
use Diogodg\Neoorm\Migrations\Command\Generate;
use Diogodg\Neoorm\Migrations\Command\MigrationContext;
use Diogodg\Neoorm\Dialect\DialectFactory;
use Diogodg\Neoorm\Migrations\Diff\MapRenameResolver;
use Diogodg\Neoorm\Migrations\Exception\MigrationException;
use Diogodg\Neoorm\Migrations\Runner\SqlFileParser;
use Diogodg\Neoorm\Schema\ModelSchemaLoader;
use Diogodg\Neoorm\Schema\SchemaRegistry;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Doubles\BufferedOutput;
use Tests\Support\TempMigrations;
use Tests\Support\UnitTestCase;

/**
 * O `generate` SEM banco nenhum.
 *
 * Este arquivo é a prova executável de que gerar migração é operação offline: ele roda na
 * suíte unitária, onde `UnitTestCase` derruba qualquer teste que abra socket. Não é
 * detalhe de desempenho — é o que permite gerar e revisar migração num CI sem serviço de
 * banco, e o que o sistema antigo não podia oferecer porque comparava o model contra
 * tabelas dentro do banco de destino.
 */
final class GenerateTest extends UnitTestCase
{
    private TempMigrations $temp;

    private BufferedOutput $output;

    private string $root = '';

    private string $namespace = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->temp = new TempMigrations('pgsql');
        $this->output = new BufferedOutput();
        $this->root = dirname(__DIR__, 2) . '/App/ConvergenceModels';
        $this->namespace = 'Tests\\App\\ConvergenceModels';

        SchemaRegistry::flush();
    }

    protected function tearDown(): void
    {
        $this->temp->cleanup();
        SchemaRegistry::flush();

        parent::tearDown();
    }

    private function context(string $dialect = 'pgsql'): MigrationContext
    {
        return new MigrationContext(
            // Uma configuração de banco que NUNCA é usada: nenhum caminho deste teste abre
            // conexão, e o `UnitTestCase` falha se algum abrir.
            new DatabaseConfig('pgsql', 'inexistente', '5432', 'inexistente', 'ninguem', '', 'utf8'),
            DialectFactory::for($dialect),
            $this->temp->directory(),
            new ModelSchemaLoader($this->root, $this->namespace),
            output: $this->output,
        );
    }

    #[Test]
    public function theFirstMigrationIsIndexZero(): void
    {
        $result = (new Generate($this->context()))->execute('initial');

        $this->assertSame(0, $result->index);
        $this->assertSame('initial', $result->tag);
        $this->assertFalse($result->nothingToDo());
        $this->assertCount(3, $result->writtenFiles, 'sql, snapshot e journal');
    }

    /**
     * O snapshot da primeira migração é o índice 0, e não o 1.
     *
     * A baseline vazia já ocupa o 0, então `baseline->next()` daria 1 e o `up` seguinte
     * procuraria um `meta/0000_snapshot.json` que não existiria. É a única assimetria da
     * numeração, e por isso tem teste próprio.
     */
    #[Test]
    public function theFirstSnapshotHasIndexZero(): void
    {
        (new Generate($this->context()))->execute('initial');

        $snapshot = $this->temp->directory()->readSnapshot(0);

        $this->assertSame(0, $snapshot->index());
        $this->assertNull($snapshot->prevId);
        $this->assertSame(['zz_box', 'zz_item'], $snapshot->schema->tableNames());
    }

    #[Test]
    public function theSecondMigrationChainsOnTheFirst(): void
    {
        (new Generate($this->context()))->execute('initial');

        $this->root = dirname(__DIR__, 2) . '/App/ConvergenceModelsV2';
        $this->namespace = 'Tests\\App\\ConvergenceModelsV2';
        SchemaRegistry::flush();

        $result = (new Generate($this->context()))->execute('add_label');

        $this->assertSame(1, $result->index);

        $snapshot = $this->temp->directory()->readSnapshot(1);

        $this->assertSame(1, $snapshot->index());
        $this->assertSame('0000', $snapshot->prevId);
    }

    /**
     * Rodar `generate` duas vezes sem mudar nada não escreve nada.
     *
     * É a convergência vista sem banco: o segundo diff é contra o snapshot que o primeiro
     * escreveu, e tem que sair vazio. É aqui que um snapshot não determinístico apareceria —
     * antes de qualquer servidor estar envolvido.
     */
    #[Test]
    public function generatingTwiceWritesNothingTheSecondTime(): void
    {
        (new Generate($this->context()))->execute('initial');

        $second = (new Generate($this->context()))->execute();

        $this->assertTrue($second->nothingToDo());
        $this->assertFalse($second->wroteFiles());
        $this->assertSame(['initial'], $this->temp->directory()->journal()->tags());
    }

    /**
     * E o mesmo vale para os dois dialetos, em diretórios separados.
     */
    #[Test]
    public function eachDialectHasItsOwnDirectoryAndConverges(): void
    {
        (new Generate($this->context()))->execute('initial');

        $mysqlTemp = new TempMigrations('mysql');

        try {
            $mysql = new MigrationContext(
                new DatabaseConfig('mysql', 'inexistente', '3306', 'inexistente', 'ninguem', '', 'utf8mb4'),
                DialectFactory::mysql(),
                $mysqlTemp->directory(),
                new ModelSchemaLoader($this->root, $this->namespace),
                output: $this->output,
            );

            $first = (new Generate($mysql))->execute('initial');

            $this->assertFalse($first->nothingToDo());
            $this->assertStringContainsString('mysql', $mysqlTemp->directory()->path());
            $this->assertStringContainsString('`', $first->sql, 'O SQL do MySQL usa crase.');

            $this->assertTrue((new Generate($mysql))->execute()->nothingToDo());
        } finally {
            $mysqlTemp->cleanup();
        }
    }

    #[Test]
    public function theGeneratedSqlIsSplittableBackIntoStatements(): void
    {
        $result = (new Generate($this->context()))->execute('initial');

        $this->assertSame($result->statements, (new SqlFileParser())->parse($result->sql));
    }

    /**
     * O nome sai das operações quando ninguém dá um.
     */
    #[Test]
    public function theTagIsDerivedFromTheOperations(): void
    {
        $result = (new Generate($this->context()))->execute();

        $this->assertSame(
            'initial',
            $result->tag,
            'Uma migração que só cria tabelas, em mais de uma tabela, chama-se initial.',
        );
    }

    #[Test]
    public function aFreeNameIsSlugified(): void
    {
        $result = (new Generate($this->context()))->execute('Criação do índice!!');

        $this->assertSame('criacao_do_indice', $result->tag);
    }

    /**
     * Dry run não escreve nada, e diz o que faria.
     */
    #[Test]
    public function aDryRunWritesNothing(): void
    {
        $result = (new Generate($this->context()))->execute('initial', dryRun: true);

        $this->assertTrue($result->dryRun);
        $this->assertNotSame('', $result->sql);
        $this->assertSame([], $result->writtenFiles);
        $this->assertTrue($this->temp->directory()->journal()->isEmpty());
    }

    /**
     * `--empty` escreve um arquivo vazio e AVISA do que isso custa.
     *
     * O snapshot repete o estado anterior porque ninguém interpreta o SQL escrito à mão — o
     * differ não pode saber o que ele fez. Quem usa a escotilha precisa saber disso, e o aviso
     * é o que garante que saiba.
     */
    #[Test]
    public function anEmptyMigrationWarnsAboutTheSnapshot(): void
    {
        $result = (new Generate($this->context()))->execute('corrige_a_mao', empty: true);

        $this->assertSame('corrige_a_mao', $result->tag);
        $this->assertSame('', $this->temp->readSql(0, 'corrige_a_mao'));
        $this->assertNotSame([], $this->output->warnings);
        $this->assertStringContainsString('não aparecerá nos diffs futuros', $this->output->warnings[0]);
    }

    /**
     * Apagar uma tabela exige confirmação, e a mensagem diz por quê.
     */
    #[Test]
    public function droppingATableIsRefusedWithoutConfirmation(): void
    {
        (new Generate($this->context()))->execute('initial');

        // Um root com só `Box`: `zz_item` some e nada aparece no lugar. A remoção PURA
        // importa aqui — se algo fosse criado junto, a guarda de ambiguidade dispararia
        // primeiro e este teste passaria pela razão errada.
        $this->root = dirname(__DIR__, 2) . '/App/DropModels';
        $this->namespace = 'Tests\\App\\DropModels';
        SchemaRegistry::flush();

        try {
            (new Generate($this->context()))->execute('remove');
            $this->fail('Esperava MigrationException.');
        } catch (MigrationException $e) {
            $this->assertStringContainsString('apaga dados', $e->getMessage());
            $this->assertStringContainsString('Não há down', $e->getMessage());
        }

        $this->assertSame(['initial'], $this->temp->directory()->journal()->tags(), 'Nada foi escrito.');
    }

    #[Test]
    public function droppingATableIsAllowedWithConfirmation(): void
    {
        (new Generate($this->context()))->execute('initial');

        $this->root = dirname(__DIR__, 2) . '/App/DropModels';
        $this->namespace = 'Tests\\App\\DropModels';
        SchemaRegistry::flush();

        $result = (new Generate($this->context()))->execute('remove', allowDestructive: true);

        $this->assertSame(1, $result->index);
        $this->assertNotSame([], $result->warnings);
    }

    /**
     * Criar tabela com restrição de unicidade NÃO exige confirmação.
     *
     * `AddUniqueConstraint` é classificado como arriscado — falha numa tabela com duplicatas —,
     * mas não apaga nada. Se o gate olhasse "arriscado" em vez de "apaga", a PRIMEIRA migração
     * de qualquer projeto pediria `--allow-destructive`, e uma confirmação que se dá sempre é
     * uma que ninguém lê.
     */
    #[Test]
    public function creatingTablesNeedsNoDestructiveConfirmation(): void
    {
        $result = (new Generate($this->context()))->execute('initial');

        $this->assertFalse($result->nothingToDo());
        $this->assertTrue(
            $result->isDestructive(),
            'A lista TEM operações arriscadas — a restrição de unicidade de zz_box.',
        );
        $this->assertFalse(
            $result->operations->discardsData(),
            'Mas nenhuma delas apaga dados, então nada precisa ser confirmado.',
        );
    }

    /**
     * Forma ambígua não escreve nada sem decisão explícita.
     *
     * Um `DropColumn` mais um `AddColumn` na mesma tabela pode ser um rename ou pode ser
     * exatamente o que parece. A diferença é invisível no diff e total no banco: renomear
     * preserva os dados, remover e criar deixa a coluna nova vazia.
     */
    #[Test]
    public function anAmbiguousShapeRefusesToGuess(): void
    {
        (new Generate($this->context('pgsql')))->execute('initial');

        $this->root = dirname(__DIR__, 2) . '/App/RenameModels';
        $this->namespace = 'Tests\\App\\RenameModels';
        SchemaRegistry::flush();

        try {
            (new Generate($this->context()))->execute('renomeia');
            $this->fail('Esperava MigrationException.');
        } catch (MigrationException $e) {
            $this->assertStringContainsString('forma ambígua', $e->getMessage());
            $this->assertStringContainsString('--rename', $e->getMessage());
            $this->assertStringContainsString('Nada foi escrito', $e->getMessage());
        }

        $this->assertSame(['initial'], $this->temp->directory()->journal()->tags());
    }

    /**
     * E com a decisão dada, sai um RENAME — não um drop mais um add.
     */
    #[Test]
    public function withTheDecisionGivenItRenames(): void
    {
        (new Generate($this->context()))->execute('initial');

        $this->root = dirname(__DIR__, 2) . '/App/RenameModels';
        $this->namespace = 'Tests\\App\\RenameModels';
        SchemaRegistry::flush();

        $result = (new Generate($this->context()))->execute(
            'renomeia',
            renames: new MapRenameResolver(['zz_item.quantity:amount']),
        );

        $descriptions = implode("\n", $result->describe());

        $this->assertStringContainsString('renomeia', $descriptions);
        $this->assertStringNotContainsString('remove a coluna', $descriptions);

        // E a decisão fica gravada, para o próximo diff não perguntar de novo.
        $snapshot = $this->temp->directory()->readSnapshot(1);

        $this->assertSame(['zz_item.quantity' => 'amount'], $snapshot->meta->renamedColumns);
    }
}
