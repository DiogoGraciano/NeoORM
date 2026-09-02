<?php

declare(strict_types=1);

namespace Tests\Unit\Runner;

use Diogodg\Neoorm\Migrations\Exception\MigrationException;
use Diogodg\Neoorm\Migrations\Snapshot\Journal;
use Diogodg\Neoorm\Migrations\Snapshot\JournalEntry;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\UnitTestCase;

/**
 * O índice das migrações, que é commitado e por isso tem que ser byte-estável e recusar
 * estados impossíveis na entrada.
 */
final class JournalTest extends UnitTestCase
{
    #[Test]
    public function anEmptyJournalStartsAtZero(): void
    {
        $journal = Journal::empty('pgsql');

        $this->assertTrue($journal->isEmpty());
        $this->assertCount(0, $journal);
        $this->assertSame(0, $journal->nextIndex());
        $this->assertNull($journal->last());
    }

    #[Test]
    public function addingAppendsAtTheNextIndex(): void
    {
        $journal = Journal::empty('pgsql')->add('initial', 100)->add('add_index', 200);

        $this->assertSame(['initial', 'add_index'], $journal->tags());
        $this->assertSame(2, $journal->nextIndex());
        $this->assertSame(0, $journal->entry(0)?->idx);
        $this->assertSame('add_index', $journal->last()?->tag);
    }

    /**
     * `add()` devolve outro journal.
     *
     * Não é purismo: a escrita dos três arquivos de uma migração pode falhar no meio, e um
     * journal mutável já teria a entrada nova em memória enquanto o disco não tem. O
     * chamador ficaria com um objeto que descreve um estado que não existe.
     */
    #[Test]
    public function addDoesNotMutate(): void
    {
        $original = Journal::empty('pgsql')->add('initial', 100);
        $next = $original->add('segunda', 200);

        $this->assertSame(['initial'], $original->tags());
        $this->assertSame(['initial', 'segunda'], $next->tags());
    }

    #[Test]
    public function aRepeatedTagIsRefused(): void
    {
        $journal = Journal::empty('pgsql')->add('initial', 100);

        $this->expectException(MigrationException::class);
        $this->expectExceptionMessageMatches("/tag 'initial'/");

        $journal->add('initial', 200);
    }

    /**
     * Buraco de índice é merge mal resolvido, e a mensagem diz isso.
     *
     * Não é detalhe estético: significa que uma migração saiu do journal sem sair do banco
     * de quem já a aplicou. A partir daí a ordem que um projeto aplicou e a que outro vai
     * aplicar divergem, e nada no sistema volta a bater.
     */
    #[Test]
    public function aGapInTheIndicesIsRefused(): void
    {
        $this->expectException(MigrationException::class);
        $this->expectExceptionMessageMatches('/não contíguos.*merge/s');

        new Journal('pgsql', [
            new JournalEntry(0, 'initial', 100),
            new JournalEntry(2, 'terceira', 300),
        ]);
    }

    #[Test]
    public function entriesOutOfOrderAreSortedNotRefused(): void
    {
        $journal = new Journal('pgsql', [
            new JournalEntry(1, 'segunda', 200),
            new JournalEntry(0, 'initial', 100),
        ]);

        $this->assertSame(['initial', 'segunda'], $journal->tags());
    }

    #[Test]
    public function aDuplicatedTagInTheFileIsRefused(): void
    {
        $this->expectException(MigrationException::class);
        $this->expectExceptionMessageMatches('/tag repetida/');

        new Journal('pgsql', [
            new JournalEntry(0, 'initial', 100),
            new JournalEntry(1, 'initial', 200),
        ]);
    }

    #[Test]
    public function itRoundTripsThroughJson(): void
    {
        $journal = Journal::empty('mysql')->add('initial', 100)->add('add_index', 200);

        $decoded = Journal::decode($journal->encode(), 'mysql');

        $this->assertSame($journal->toArray(), $decoded->toArray());
        $this->assertSame($journal->encode(), $decoded->encode());
    }

    /**
     * O journal é commitado, então o encoding é fixo e termina em newline.
     *
     * Sem o newline final, todo `git diff` mostra a última linha como alterada; sem as
     * chaves em ordem, dois desenvolvedores geram arquivos diferentes para o mesmo estado.
     */
    #[Test]
    public function theEncodingIsStableAndEndsWithANewline(): void
    {
        $json = Journal::empty('pgsql')->add('initial', 100)->encode();

        $this->assertStringEndsWith("\n", $json);
        $this->assertStringContainsString('"createdAt": 100', $json);
        $this->assertSame(
            ['dialect', 'entries', 'version'],
            array_keys((array) json_decode($json, true)),
        );
    }

    #[Test]
    public function anEmptyFileDecodesToAnEmptyJournal(): void
    {
        $this->assertTrue(Journal::decode('', 'pgsql')->isEmpty());
        $this->assertTrue(Journal::decode("  \n", 'pgsql')->isEmpty());
    }

    #[Test]
    public function invalidJsonIsRefusedWithTheReason(): void
    {
        $this->expectException(MigrationException::class);
        $this->expectExceptionMessageMatches('/não é JSON válido/');

        Journal::decode('{isso não é json', 'pgsql');
    }

    /**
     * O dialeto do arquivo tem que casar com o do diretório.
     *
     * Aplicar SQL de MySQL num PostgreSQL não falharia no primeiro statement — falharia em
     * algum, mais tarde, com o banco já pela metade. Comparar os dois na leitura custa uma
     * linha e transforma isso num erro antes de qualquer conexão.
     */
    #[Test]
    public function aDialectMismatchIsRefused(): void
    {
        $mysql = Journal::empty('mysql')->add('initial', 100)->encode();

        $this->expectException(MigrationException::class);
        $this->expectExceptionMessageMatches("/dialeto 'mysql'.*diretório de 'pgsql'/s");

        Journal::decode($mysql, 'pgsql');
    }

    #[Test]
    public function aFutureFormatIsRefusedInsteadOfMisread(): void
    {
        $json = json_encode(['version' => 99, 'dialect' => 'pgsql', 'entries' => []]);

        $this->expectException(MigrationException::class);
        $this->expectExceptionMessageMatches('/formato 99.*Atualize a NeoORM/s');

        Journal::decode((string) $json, 'pgsql');
    }

    #[Test]
    public function anEntryWithoutRequiredFieldsIsRefused(): void
    {
        $json = json_encode([
            'version' => 1,
            'dialect' => 'pgsql',
            'entries' => [['idx' => 0, 'tag' => 'initial']],
        ]);

        $this->expectException(MigrationException::class);
        $this->expectExceptionMessageMatches("/sem 'createdAt'/");

        Journal::decode((string) $json, 'pgsql');
    }

    #[Test]
    public function lookupByTag(): void
    {
        $journal = Journal::empty('pgsql')->add('initial', 100)->add('segunda', 200);

        $this->assertTrue($journal->hasTag('segunda'));
        $this->assertFalse($journal->hasTag('terceira'));
        $this->assertSame(1, $journal->byTag('segunda')?->idx);
        $this->assertNull($journal->byTag('terceira'));
    }

    #[Test]
    public function anEntryWithoutATagIsRefused(): void
    {
        $this->expectException(MigrationException::class);
        $this->expectExceptionMessageMatches('/sem tag/');

        new JournalEntry(0, '  ', 100);
    }
}
