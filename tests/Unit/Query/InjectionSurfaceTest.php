<?php

declare(strict_types=1);

namespace Tests\Unit\Query;

use Diogodg\Neoorm\Dialect\DialectFactory;
use Diogodg\Neoorm\Query\Compiler\Compiler;
use Diogodg\Neoorm\Query\Exception\QueryException;
use Diogodg\Neoorm\Query\Expr\Aliased;
use Diogodg\Neoorm\Query\State\SelectState;
use Diogodg\Neoorm\Schema\Exception\InvalidIdentifierException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Generated\Tables;
use Tests\Support\UnitTestCase;

use function Diogodg\Neoorm\Query\eq;
use function Diogodg\Neoorm\Query\inArray;
use function Diogodg\Neoorm\Query\like;
use function Diogodg\Neoorm\Query\sql;

/**
 * A superfície de injeção da camada de consulta, e o que a fecha.
 *
 * Herda o propósito do `NeoOrmSecurityTest` da 1.x, que morreu com o `Db`: lá o operador
 * chegava como STRING (`addFilter('name', $operador, $valor)`), o ramo `IS` interpolava
 * o valor cru na query, e a defesa era uma allowlist de operadores — uma allowlist que
 * precisava estar certa em quatro métodos diferentes.
 *
 * Aqui a defesa é estrutural, e é por isso que este arquivo testa tipos e não strings:
 *
 * - **Operador** é enum (`ComparisonOp`). Não existe assinatura pública que receba um
 *   operador como texto, então "operador fora da allowlist" deixou de ser um estado
 *   possível.
 * - **Identificador** — tabela, coluna, alias — passa por `IdentifierValidator` no
 *   construtor do nó. Se um `ColumnRef` existe, o nome dele já é `^[a-z_][a-z0-9_]*$`.
 * - **Valor** vira bind, sempre, sem exceção e sem ramo.
 *
 * Roda offline: compilar não abre conexão, e é o que permite conferir o SQL emitido em
 * vez de inferir a segurança do resultado de uma consulta.
 */
final class InjectionSurfaceTest extends UnitTestCase
{
    /**
     * @return iterable<string,array{string}>
     */
    public static function vetores(): iterable
    {
        yield 'injeção via operador' => ['= 1 OR 1=1 --'];
        yield 'union' => ['= 1 UNION SELECT password FROM users --'];
        yield 'comentário' => ['=/**/'];
        yield 'ponto e vírgula' => ['=; DROP TABLE users; --'];
        yield 'espaço' => ['name x'];
        yield 'aspas' => ["name'"];
        yield 'from embutido' => ['alias FROM users; --'];
    }

    #[DataProvider('vetores')]
    public function testAnIdentifierThatCouldReachTheSqlIsRefusedAsAnAlias(string $vetor): void
    {
        $this->expectException(InvalidIdentifierException::class);

        new Aliased(Tables::users()->id, $vetor);
    }

    #[DataProvider('vetores')]
    public function testAnIdentifierThatCouldReachTheSqlIsRefusedAsATableAlias(string $vetor): void
    {
        $this->expectException(InvalidIdentifierException::class);

        Tables::users($vetor);
    }

    /**
     * Coluna inexistente falha nomeando a tabela, e não vira SQL.
     *
     * É o caminho que resta para quem monta o nome da coluna em runtime — o acesso
     * normal é `$u->email`, uma propriedade declarada, que o analisador já recusa.
     */
    public function testAnUnknownColumnIsRefusedByName(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage("não tem a coluna 'name; DROP TABLE users --'");

        Tables::users()->columnRef('name; DROP TABLE users --');
    }

    /**
     * O valor nunca aparece no SQL, em nenhum dos dois dialetos: vira placeholder.
     */
    #[DataProvider('vetores')]
    public function testAValueIsAlwaysABindAndNeverText(string $vetor): void
    {
        $u = Tables::users();

        $state = (new SelectState())
            ->withFrom($u->toRef())
            ->withWhere(eq($u->name, $vetor))
            ->withWhere(like($u->email, "%{$vetor}%"));

        foreach (['mysql', 'pgsql'] as $driver) {
            $query = (new Compiler())->compileSelect($state, DialectFactory::for($driver));

            $this->assertStringNotContainsString($vetor, $query->sql, "o valor vazou para o SQL do {$driver}");
            $this->assertContains($vetor, $query->values());
        }
    }

    /**
     * `sql()` é a única via de escape, e o contrato é explícito: o FRAGMENTO vai cru, os
     * VALORES continuam virando bind.
     *
     * Existe porque toda camada de consulta acaba precisando de algo que ela não modela
     * — e uma via de escape declarada é melhor que a alternativa, que é o usuário
     * concatenar por fora. O `Raw` da 1.x não aceitava bind nenhum, então quem precisava
     * de valor ali só tinha a concatenação.
     */
    public function testTheRawEscapeHatchStillBindsItsValues(): void
    {
        $u = Tables::users();

        $state = (new SelectState())
            ->withFrom($u->toRef())
            ->withWhere(sql('LENGTH(name) > ?', 10));

        $query = (new Compiler())->compileSelect($state, DialectFactory::for('pgsql'));

        $this->assertStringContainsString('LENGTH(name) >', $query->sql);
        $this->assertStringNotContainsString('10', $query->sql, 'o valor do fragmento deveria ter virado bind');
        $this->assertContains(10, $query->values());
    }

    /**
     * Um `IN` vazio gera SQL inválido; falhar cedo dá uma mensagem melhor que o erro de
     * sintaxe do servidor — e a mensagem nomeia a coluna.
     *
     * `InvalidArgumentException` e não `QueryException`: os nós validam o próprio
     * argumento na construção, antes de existir consulta alguma. `QueryException` é do
     * compilador e dos builders, onde já há uma consulta para nomear.
     */
    public function testAnEmptyInListIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('users.id');

        inArray(Tables::users()->id, []);
    }
}
