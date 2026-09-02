<?php

declare(strict_types=1);

namespace Tests\Unit\Sql;

use Diogodg\Neoorm\Dialect\SqlWriter;
use Tests\Support\UnitTestCase;

final class SqlWriterTest extends UnitTestCase
{
    public function testStatementsAreSeparatedByTheBreakpointMarker(): void
    {
        $written = (new SqlWriter())->write(['CREATE TABLE a (id INT)', 'CREATE TABLE b (id INT)']);

        $this->assertSame(
            "CREATE TABLE a (id INT);\n"
            . "--> statement-breakpoint\n"
            . "CREATE TABLE b (id INT);\n",
            $written,
        );
    }

    public function testASingleStatementGetsNoBreakpoint(): void
    {
        $written = (new SqlWriter())->write(['DROP TABLE a']);

        $this->assertSame("DROP TABLE a;\n", $written);
        $this->assertStringNotContainsString(SqlWriter::BREAKPOINT, $written);
    }

    /**
     * O dialeto devolve statements sem `;` e o writer é quem o acrescenta. Se um
     * ponto e vírgula chegar de qualquer jeito, ele não pode ser duplicado: o
     * arquivo é lido de volta e o hash dele entra na tabela de controle.
     */
    public function testTrailingSemicolonsAreNotDuplicated(): void
    {
        $this->assertSame("DROP TABLE a;\n", (new SqlWriter())->write(['DROP TABLE a;']));
        $this->assertSame("DROP TABLE a;\n", (new SqlWriter())->write(["DROP TABLE a ;  \n"]));
    }

    public function testEmptyStatementsAreDropped(): void
    {
        $written = (new SqlWriter())->write(['DROP TABLE a', '', '   ', 'DROP TABLE b']);

        $this->assertSame(
            "DROP TABLE a;\n--> statement-breakpoint\nDROP TABLE b;\n",
            $written,
        );
    }

    /**
     * Uma migração vazia é um arquivo vazio — nem um comentário dizendo que está
     * vazia. O hash do arquivo entra na tabela de controle, e conteúdo decorativo é
     * conteúdo que alguém edita.
     */
    public function testAnEmptyMigrationIsAnEmptyFile(): void
    {
        $this->assertSame('', (new SqlWriter())->write([]));
        $this->assertSame('', (new SqlWriter())->write(['', '  ']));
    }

    /**
     * O marcador é comentário de SQL de propósito: quem abrir o arquivo num cliente
     * e rodar tudo de uma vez obtém o mesmo resultado que o runner.
     */
    public function testTheMarkerIsAValidSqlComment(): void
    {
        $this->assertStringStartsWith('--', SqlWriter::BREAKPOINT);
    }

    /**
     * Statement com quebra de linha no meio — um comentário multilinha, por exemplo
     * — continua sendo um statement. É exatamente por isso que a divisão é por
     * marcador em linha própria e não por `;` nem por linha em branco.
     */
    public function testAStatementMayContainNewlinesWithoutBeingSplit(): void
    {
        $multiline = "CREATE TABLE a (\n\tid INT\n)";
        $written = (new SqlWriter())->write([$multiline, 'DROP TABLE b']);

        $this->assertSame(
            "CREATE TABLE a (\n\tid INT\n);\n--> statement-breakpoint\nDROP TABLE b;\n",
            $written,
        );
        $this->assertSame(1, substr_count($written, SqlWriter::BREAKPOINT));
    }

    public function testTheOutputAlwaysEndsWithExactlyOneNewline(): void
    {
        $written = (new SqlWriter())->write(['DROP TABLE a', 'DROP TABLE b']);

        $this->assertStringEndsWith(";\n", $written);
        $this->assertStringEndsNotWith(";\n\n", $written);
    }
}
