<?php

declare(strict_types=1);

namespace Tests\Unit\Runner;

use Diogodg\Neoorm\Migrations\Exception\MigrationException;
use Diogodg\Neoorm\Migrations\Snapshot\Snapshot;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\TempMigrations;
use Tests\Support\UnitTestCase;

/**
 * O diretório de artefatos, contra um sistema de arquivos de verdade.
 *
 * De verdade porque é disso que a classe trata: escrita atômica por `tempnam` + `rename`,
 * recusa de sobrescrever, criação de `meta/`. Um dublê em memória afirmaria que o dublê
 * funciona. Disco não é banco — não abre socket, e a suíte segue sem servidor.
 */
final class MigrationDirectoryTest extends UnitTestCase
{
    private TempMigrations $temp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->temp = new TempMigrations('pgsql');
    }

    protected function tearDown(): void
    {
        $this->temp->cleanup();

        parent::tearDown();
    }

    /**
     * Um diretório que ainda não existe é o estado NORMAL de um projeto novo, não erro.
     */
    #[Test]
    public function anAbsentDirectoryReadsAsAnEmptyHistory(): void
    {
        $directory = $this->temp->directory();

        $this->assertFalse($directory->exists());
        $this->assertTrue($directory->journal()->isEmpty());
        $this->assertSame([], $directory->sqlFiles());
        $this->assertTrue($directory->latestSnapshot()->isEmpty());
    }

    #[Test]
    public function ensureCreatesThePathAndMeta(): void
    {
        $directory = $this->temp->directory();
        $directory->ensure();

        $this->assertDirectoryExists($directory->path());
        $this->assertDirectoryExists($directory->metaPath());
        $this->assertStringEndsWith('pgsql', $directory->path());
    }

    #[Test]
    public function ensureIsIdempotent(): void
    {
        $directory = $this->temp->directory();
        $directory->ensure();
        $directory->ensure();

        $this->assertDirectoryExists($directory->metaPath());
    }

    #[Test]
    public function writingProducesTheThreeFiles(): void
    {
        $directory = $this->temp->directory();

        // A baseline JÁ é o índice 0: o snapshot de índice N descreve o estado depois da
        // migração N, e a primeira migração é `diff(baseline, snapshot 0)`.
        $directory->write(0, 'initial', "CREATE TABLE a (id INT);\n", Snapshot::baseline('pgsql'));

        $this->assertFileExists($directory->sqlPath(0, 'initial'));
        $this->assertFileExists($directory->snapshotPath(0));
        $this->assertFileExists($directory->journalPath());
        $this->assertSame(['initial'], $directory->journal()->tags());
        $this->assertSame(['0000_initial.sql'], $directory->sqlFiles());
    }

    /**
     * Índice do snapshot e índice da migração são o mesmo número, e escrever um como o
     * outro é recusado.
     *
     * Se passasse, o `generate` seguinte leria o snapshot `0001` acreditando que ele
     * descreve o estado depois da migração 1, quando descreve outro — e o diff sairia
     * contra um schema que nunca existiu.
     */
    #[Test]
    public function aSnapshotWithTheWrongIndexIsRefused(): void
    {
        $directory = $this->temp->directory();
        $snapshot = Snapshot::baseline('pgsql');

        $this->expectException(MigrationException::class);
        $this->expectExceptionMessageMatches('/índice 0.*escrito como 1/s');

        $directory->write(1, 'segunda', 'SELECT 1', $snapshot);
    }

    #[Test]
    public function writingOutOfSequenceIsRefused(): void
    {
        $this->temp->add(0, 'initial', "SELECT 1;\n");

        $directory = $this->temp->directory();
        $snapshot = Snapshot::baseline('pgsql');

        for ($i = 0; $i < 2; $i++) {
            $snapshot = $snapshot->next($snapshot->schema);
        }

        $this->expectException(MigrationException::class);
        $this->expectExceptionMessageMatches('/journal espera a migração 1.*esta é a 2/s');

        $directory->write(2, 'terceira', 'SELECT 1', $snapshot);
    }

    /**
     * Sobrescrever um arquivo de migração é perder história, e a mensagem diz o que fazer.
     */
    #[Test]
    public function overwritingAnExistingMigrationIsRefused(): void
    {
        $this->temp->add(0, 'initial', "SELECT 1;\n");

        // O journal já foi para o índice 1; forjo um journal vazio para que a guarda de
        // sequência passe e a de sobrescrita seja a que dispara.
        file_put_contents(
            $this->temp->directory()->journalPath(),
            \Diogodg\Neoorm\Migrations\Snapshot\Journal::empty('pgsql')->encode(),
        );

        $this->expectException(MigrationException::class);
        $this->expectExceptionMessageMatches('/já existe.*perder história/s');

        $this->temp->directory()->write(0, 'initial', 'SELECT 2', Snapshot::baseline('pgsql'));
    }

    #[Test]
    public function theLatestSnapshotIsTheLastOneWritten(): void
    {
        $this->temp->add(0, 'initial', "SELECT 1;\n")->add(1, 'segunda', "SELECT 2;\n");

        $this->assertSame(1, $this->temp->directory()->latestSnapshot()->index());
    }

    /**
     * Sem nenhuma migração, o "anterior" é a baseline vazia.
     *
     * É o que faz a PRIMEIRA migração ser um diff como qualquer outra, em vez de um caminho
     * de código separado — e caminho especial que roda uma vez por projeto é onde bug se
     * esconde por anos.
     */
    #[Test]
    public function withoutMigrationsThePreviousStateIsTheEmptyBaseline(): void
    {
        $snapshot = $this->temp->directory()->latestSnapshot();

        $this->assertTrue($snapshot->isEmpty());
        $this->assertSame('pgsql', $snapshot->dialect);
    }

    #[Test]
    public function aMissingSnapshotSaysItIsSourceCode(): void
    {
        $this->temp->add(0, 'initial', "SELECT 1;\n");
        unlink($this->temp->directory()->snapshotPath(0));

        $this->expectException(MigrationException::class);
        $this->expectExceptionMessageMatches('/código-fonte.*commitados/s');

        $this->temp->directory()->readSnapshot(0);
    }

    #[Test]
    public function aMissingSqlSaysDeletingUndoesNothing(): void
    {
        $this->temp->add(0, 'initial', "SELECT 1;\n")->removeSql(0, 'initial');

        $this->expectException(MigrationException::class);
        $this->expectExceptionMessageMatches('/apagá-lo não desfaz nada/');

        $this->temp->directory()->readSql(0, 'initial');
    }

    #[Test]
    public function theHashMatchesTheParserHash(): void
    {
        $this->temp->add(0, 'initial', "CREATE TABLE a (id INT);\n");

        $this->assertSame(
            \Diogodg\Neoorm\Migrations\Runner\SqlFileParser::hash("CREATE TABLE a (id INT);\n"),
            $this->temp->directory()->hashOf(0, 'initial'),
        );
    }

    #[Test]
    public function sqlFilesListsWhatIsOnDisk(): void
    {
        $this->temp
            ->add(0, 'initial', "SELECT 1;\n")
            ->add(1, 'segunda', "SELECT 2;\n")
            ->addOrphanSql('0002_de_outro_branch.sql', "SELECT 3;\n");

        $this->assertSame(
            ['0000_initial.sql', '0001_segunda.sql', '0002_de_outro_branch.sql'],
            $this->temp->directory()->sqlFiles(),
        );
    }

    /**
     * @return iterable<string,array{string,string}>
     */
    public static function slugs(): iterable
    {
        yield 'simples' => ['initial', 'initial'];
        yield 'espaços viram sublinhado' => ['add index to city', 'add_index_to_city'];
        yield 'maiúsculas caem' => ['AddIndex', 'addindex'];
        yield 'pontuação sai' => ['add-index!!', 'add_index'];
        yield 'sublinhados das pontas saem' => ['  __add index__  ', 'add_index'];
        yield 'acento é transliterado, não descartado' => ['criação de índice', 'criacao_de_indice'];
        yield 'sequência de separadores colapsa' => ['a---b   c', 'a_b_c'];
    }

    /**
     * Acento é transliterado e não descartado.
     *
     * `criação` tem que virar `criacao`, não `cria_o`: perder a letra muda a palavra, e o
     * nome do arquivo é o que alguém vai digitar em `--to <tag>`.
     */
    #[Test]
    #[DataProvider('slugs')]
    public function slugMakesAFileName(string $input, string $expected): void
    {
        $this->assertSame(
            $expected,
            \Diogodg\Neoorm\Migrations\Snapshot\MigrationDirectory::slug($input),
        );
    }

    #[Test]
    public function aVeryLongNameIsTruncatedWithoutATrailingUnderscore(): void
    {
        $slug = \Diogodg\Neoorm\Migrations\Snapshot\MigrationDirectory::slug(str_repeat('ab ', 60));

        $this->assertLessThanOrEqual(80, strlen($slug));
        $this->assertStringEndsNotWith('_', $slug);
    }

    #[Test]
    public function theFileNameCarriesTheFourDigitIndex(): void
    {
        $directory = $this->temp->directory();

        $this->assertSame('0000_initial.sql', $directory->sqlFileName(0, 'initial'));
        $this->assertSame('0042_add_index.sql', $directory->sqlFileName(42, 'add_index'));
        $this->assertStringEndsWith('0007_snapshot.json', $directory->snapshotPath(7));
    }
}
