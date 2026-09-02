<?php

declare(strict_types=1);

namespace Tests\Unit\Runner;

use Diogodg\Neoorm\Dialect\SqlWriter;
use Diogodg\Neoorm\Migrations\Runner\SqlFileParser;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\UnitTestCase;

/**
 * O divisor de statements, nos dois caminhos.
 *
 * O caminho do marcador é o que roda sempre e é trivial. O do scanner é o que roda quando
 * alguém editou o arquivo à mão e perdeu o marcador — e é onde vive todo o risco, porque
 * `explode(';')` acerta o caso fácil e corrompe o resto em silêncio. "Em silêncio" aqui
 * significa mandar meio comando para o banco.
 */
final class SqlFileParserTest extends UnitTestCase
{
    private SqlFileParser $parser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new SqlFileParser();
    }

    #[Test]
    public function itSplitsOnTheBreakpoint(): void
    {
        $sql = (new SqlWriter())->write([
            'CREATE TABLE a (id INT)',
            'CREATE TABLE b (id INT)',
        ]);

        $this->assertSame(
            ['CREATE TABLE a (id INT)', 'CREATE TABLE b (id INT)'],
            $this->parser->parse($sql),
        );
    }

    /**
     * O que o `SqlWriter` escreve, o parser lê de volta — inclusive quando o statement
     * contém ponto e vírgula.
     *
     * As duas classes são um par, e este é o teste que as amarra: se o marcador mudar de
     * um lado e não do outro, o arquivo continua parecendo válido e o parser passa a
     * devolver um statement gigante.
     */
    #[Test]
    public function whatTheWriterWritesTheParserReads(): void
    {
        $statements = [
            "INSERT INTO t (a) VALUES ('tem ; ponto e vírgula')",
            'CREATE TABLE b (id INT)',
            "COMMENT ON TABLE b IS 'um; dois'",
        ];

        $this->assertSame($statements, $this->parser->parse((new SqlWriter())->write($statements)));
    }

    #[Test]
    public function anEmptyFileHasNoStatements(): void
    {
        $this->assertSame([], $this->parser->parse(''));
        $this->assertSame([], $this->parser->parse("\n\n  \n"));
    }

    /**
     * CRLF de um checkout no Windows não pode virar parte do statement.
     */
    #[Test]
    public function crlfIsNormalized(): void
    {
        $sql = "CREATE TABLE a (id INT);\r\n" . SqlWriter::BREAKPOINT . "\r\nCREATE TABLE b (id INT);\r\n";

        $this->assertSame(
            ['CREATE TABLE a (id INT)', 'CREATE TABLE b (id INT)'],
            $this->parser->parse($sql),
        );
    }

    // ---- daqui para baixo, o caminho do scanner: arquivos sem marcador ----

    #[Test]
    public function withoutTheMarkerItSplitsOnSemicolons(): void
    {
        $sql = "CREATE TABLE a (id INT);\nCREATE TABLE b (id INT);\n";

        $this->assertSame(
            ['CREATE TABLE a (id INT)', 'CREATE TABLE b (id INT)'],
            $this->parser->parse($sql),
        );
    }

    #[Test]
    public function theLastStatementNeedsNoSemicolon(): void
    {
        $this->assertSame(
            ['CREATE TABLE a (id INT)', 'CREATE TABLE b (id INT)'],
            $this->parser->parse("CREATE TABLE a (id INT);\nCREATE TABLE b (id INT)"),
        );
    }

    /**
     * Ponto e vírgula dentro de literal de string é conteúdo, não separador.
     */
    #[Test]
    public function aSemicolonInsideAStringIsContent(): void
    {
        $sql = "INSERT INTO t (a) VALUES ('um; dois');\nSELECT 1";

        $this->assertSame(
            ["INSERT INTO t (a) VALUES ('um; dois')", 'SELECT 1'],
            $this->parser->parse($sql),
        );
    }

    /**
     * Aspa dobrada é a aspa em si, e não o fim do literal.
     *
     * Se o scanner achar que o literal terminou na primeira das duas, ele passa a ler o
     * resto do texto como comando — e o `;` seguinte, que é conteúdo, vira separador.
     */
    #[Test]
    public function aDoubledQuoteDoesNotEndTheString(): void
    {
        $sql = "INSERT INTO t (a) VALUES ('d''água; e sal');\nSELECT 1";

        $this->assertSame(
            ["INSERT INTO t (a) VALUES ('d''água; e sal')", 'SELECT 1'],
            $this->parser->parse($sql),
        );
    }

    /**
     * A contrabarra escapa no MySQL, que é o default do servidor.
     *
     * É por isso que o runner tira `NO_BACKSLASH_ESCAPES` do `sql_mode` da sessão: o mesmo
     * texto significa duas coisas conforme a configuração, e o arquivo commitado tem que
     * ter um significado só.
     */
    #[Test]
    public function aBackslashEscapesTheQuote(): void
    {
        $sql = "INSERT INTO t (a) VALUES ('d\\'água; e sal');\nSELECT 1";

        $this->assertSame(
            ["INSERT INTO t (a) VALUES ('d\\'água; e sal')", 'SELECT 1'],
            $this->parser->parse($sql),
        );
    }

    /**
     * Identificador citado também protege: uma coluna pode se chamar `a;b`.
     */
    #[Test]
    public function quotedIdentifiersProtectTheSemicolon(): void
    {
        $this->assertSame(
            ['CREATE TABLE "a;b" (id INT)', 'SELECT 1'],
            $this->parser->parse("CREATE TABLE \"a;b\" (id INT);\nSELECT 1"),
        );

        $this->assertSame(
            ['CREATE TABLE `a;b` (id INT)', 'SELECT 1'],
            $this->parser->parse("CREATE TABLE `a;b` (id INT);\nSELECT 1"),
        );
    }

    #[Test]
    public function aSemicolonInALineCommentIsNotASeparator(): void
    {
        $sql = "CREATE TABLE a (\n  -- tem ; aqui\n  id INT\n);\nSELECT 1";

        $statements = $this->parser->parse($sql);

        $this->assertCount(2, $statements);
        $this->assertStringContainsString('-- tem ; aqui', $statements[0]);
        $this->assertSame('SELECT 1', $statements[1]);
    }

    #[Test]
    public function aSemicolonInAHashCommentIsNotASeparator(): void
    {
        $statements = $this->parser->parse("CREATE TABLE a (\n  # tem ; aqui\n  id INT\n);\nSELECT 1");

        $this->assertCount(2, $statements);
        $this->assertSame('SELECT 1', $statements[1]);
    }

    #[Test]
    public function aSemicolonInABlockCommentIsNotASeparator(): void
    {
        $statements = $this->parser->parse("CREATE /* um ; dois */ TABLE a (id INT);\nSELECT 1");

        $this->assertCount(2, $statements);
        $this->assertStringContainsString('/* um ; dois */', $statements[0]);
    }

    /**
     * Dollar-quoting do PostgreSQL: corpo de função é feito de ponto e vírgula.
     *
     * É o caso em que dividir por `;` não erra num detalhe — erra em tudo, quebrando a
     * função em pedaços que nenhum deles é SQL válido.
     */
    #[Test]
    public function dollarQuotingProtectsAFunctionBody(): void
    {
        $sql = <<<'SQL'
        CREATE FUNCTION bump() RETURNS trigger AS $$
        BEGIN
          NEW.updated_at = now();
          RETURN NEW;
        END;
        $$ LANGUAGE plpgsql;
        SELECT 1
        SQL;

        $statements = $this->parser->parse($sql);

        $this->assertCount(2, $statements);
        $this->assertStringContainsString('RETURN NEW;', $statements[0]);
        $this->assertStringContainsString('LANGUAGE plpgsql', $statements[0]);
        $this->assertSame('SELECT 1', $statements[1]);
    }

    #[Test]
    public function dollarQuotingWithATagAlsoWorks(): void
    {
        $sql = "CREATE FUNCTION f() RETURNS int AS \$corpo\$ SELECT 1; \$corpo\$ LANGUAGE sql;\nSELECT 2";

        $statements = $this->parser->parse($sql);

        $this->assertCount(2, $statements);
        $this->assertStringContainsString('SELECT 1;', $statements[0]);
        $this->assertSame('SELECT 2', $statements[1]);
    }

    /**
     * Um `$` que não abre bloco não pode engolir o resto do arquivo.
     *
     * `$1` é placeholder de parâmetro no PostgreSQL. Tratado como abertura de
     * dollar-quoting, ele procuraria um `$1` de fechamento que não existe e consumiria
     * tudo até o fim — o arquivo inteiro viraria um statement só.
     */
    #[Test]
    public function aLoneDollarIsNotADelimiter(): void
    {
        $statements = $this->parser->parse("SELECT \$1;\nSELECT 2");

        $this->assertSame(['SELECT $1', 'SELECT 2'], $statements);
    }

    /**
     * Um chunk que é só comentário não é statement.
     *
     * Mandá-lo ao banco não daria erro, mas contaria na tabela de controle — e aí o número
     * de statements aplicados deixaria de casar com o do arquivo, que é exatamente o número
     * de onde a retomada parte.
     */
    #[Test]
    public function aCommentOnlyChunkIsNotAStatement(): void
    {
        $this->assertSame([], $this->parser->parse("-- só um comentário\n"));
        $this->assertSame([], $this->parser->parse("/* nada aqui */\n;\n"));
        $this->assertSame(
            ['SELECT 1'],
            $this->parser->parse("-- cabeçalho\n;\nSELECT 1;\n/* rodapé */"),
        );
    }

    /**
     * Uma citação que nunca fecha não pode virar laço infinito nem sumir com o resto.
     *
     * O arquivo é inválido, e a resposta certa é entregá-lo ao banco para que o ERRO venha
     * de quem sabe SQL, com o texto na mensagem — não adivinhar onde o literal terminaria.
     */
    #[Test]
    public function anUnterminatedQuoteIsHandedOverAsIs(): void
    {
        $statements = $this->parser->parse("SELECT 'nunca fecha");

        $this->assertSame(["SELECT 'nunca fecha"], $statements);
    }

    // ---- hash ----

    /**
     * O hash detecta edição de CONTEÚDO, e só isso.
     *
     * Um checkout com `core.autocrlf` ligado, ou um editor que apara a última linha, não
     * pode invalidar o histórico de migrações de um projeto inteiro — seria transformar
     * uma configuração de Git num incidente.
     */
    #[Test]
    public function theHashIgnoresLineEndingsAndTrailingSpace(): void
    {
        $base = "CREATE TABLE a (id INT);\n";

        $this->assertSame(
            SqlFileParser::hash($base),
            SqlFileParser::hash("CREATE TABLE a (id INT);\r\n"),
        );
        $this->assertSame(SqlFileParser::hash($base), SqlFileParser::hash($base . "\n\n  "));
    }

    #[Test]
    public function theHashChangesWithTheContent(): void
    {
        $this->assertNotSame(
            SqlFileParser::hash('CREATE TABLE a (id INT)'),
            SqlFileParser::hash('CREATE TABLE a (id BIGINT)'),
        );
    }
}
