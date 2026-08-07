<?php

namespace Tests;

use Diogodg\Neoorm\Enums\LogicalOperator;
use Exception;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\App\Models\Employee;

/**
 * Regressão dos vetores de injeção de SQL.
 *
 * O ramo `IS` de addFilter() interpolava o valor cru na query, e o operador
 * lógico nunca era validado em addFilter/addHaving/addJoin — qualquer um dos
 * dois permitia injetar SQL arbitrário.
 */
class NeoOrmSecurityTest extends TestCase
{
    /**
     * As validações acontecem antes de qualquer query, mas instanciar um model
     * já exige conexão e schema, então a classe monta o banco como o NeoOrmTest.
     */
    public static function setUpBeforeClass(): void
    {
        (new \Diogodg\Neoorm\Migrations\Migrate)->execute(true);
    }

    public static function operadoresInvalidos(): array
    {
        return [
            'injeção via operador' => ["= 1 OR 1=1 --"],
            'union' => ["= 1 UNION SELECT password FROM users --"],
            'comentário' => ["=/**/"],
            'ponto e vírgula' => ["=; DROP TABLE users; --"],
            'operador inexistente' => ["~~"],
            'is null como operador' => ["IS"],
        ];
    }

    #[DataProvider('operadoresInvalidos')]
    public function testOperadorForaDaAllowlistEhRecusado(string $operador): void
    {
        $this->expectException(Exception::class);

        (new Employee)->addFilter("name", $operador, "x");
    }

    #[DataProvider('operadoresInvalidos')]
    public function testOperadorInvalidoEmHavingEhRecusado(string $operador): void
    {
        $this->expectException(Exception::class);

        (new Employee)->addHaving("name", $operador, "x");
    }

    public function testOperadorInvalidoEmJoinEhRecusado(): void
    {
        $this->expectException(Exception::class);

        (new Employee)->addJoin("users", "users.id", "employee.user_id", "INNER", "= 1 OR 1=1 --");
    }

    public function testTipoDeJoinInvalidoEhRecusado(): void
    {
        $this->expectException(Exception::class);

        (new Employee)->addJoin("users", "users.id", "employee.user_id", "INNER JOIN users; DROP TABLE users --");
    }

    public function testOperadoresValidosSaoAceitos(): void
    {
        $db = new Employee;

        foreach (['=', '!=', '<>', '>', '>=', '<', '<=', 'LIKE', 'NOT LIKE'] as $operador) {
            $db->addFilter("name", $operador, "x");
        }

        // Normalização: caixa e espaçamento não deveriam importar.
        $db->addFilter("name", "  not   like  ", "x");
        $db->addFilter("name", LogicalOperator::EQUAL, "x");

        // A query precisa ser montada e executada sem erro; quantas linhas ela
        // devolve não importa aqui.
        $this->assertIsInt($db->count(true));
    }

    public function testOperadoresDeListaEIntervaloSaoAceitos(): void
    {
        $db = new Employee;

        $db->addFilter("id", "IN", [1, 2, 3]);
        $db->addFilter("id", "NOT IN", [99]);
        $db->addFilter("id", "BETWEEN", [1, 10]);

        $this->assertIsInt($db->count(true));
    }

    public function testIdentificadorInvalidoEhRecusado(): void
    {
        $this->expectException(Exception::class);

        (new Employee)->addFilter("name; DROP TABLE users --", "=", "x");
    }

    public function testAliasDeColunaEhValidado(): void
    {
        // O atalho [coluna, alias] não passava por validateIdentifier.
        $this->expectException(Exception::class);

        (new Employee)->selectColumns(["name", "alias FROM users; --"]);
    }

    public function testFiltroDeNulidadeNaoAceitaValorDoChamador(): void
    {
        $db = new Employee;

        // addFilterNull monta o predicado a partir de constante interna: não há
        // parâmetro por onde injetar.
        $resultado = $db->addFilterNull("name")->count(true);

        $this->assertIsInt($resultado);
    }

    public function testInExigeArray(): void
    {
        $this->expectException(Exception::class);

        (new Employee)->addFilter("id", "IN", "1,2,3");
    }

    public function testInComArrayVazioEhRecusado(): void
    {
        // Um IN vazio gera SQL inválido; falhar cedo dá uma mensagem melhor.
        $this->expectException(Exception::class);

        (new Employee)->addFilter("id", "IN", []);
    }

    public function testBetweenExigeDoisValores(): void
    {
        $this->expectException(Exception::class);

        (new Employee)->addFilter("id", "BETWEEN", [1]);
    }

    public function testValorNaoEhInterpretadoComoSql(): void
    {
        // O valor sempre vira bind, então uma string com sintaxe SQL é apenas
        // um texto que não casa com nada.
        $total = (new Employee)
            ->addFilter("name", "=", "' OR '1'='1")
            ->count(true);

        $this->assertSame(0, $total);
    }
}
