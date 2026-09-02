<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Query\Compiler;

use PDOStatement;

/**
 * O resultado da compilação: SQL e binds, como dado.
 *
 * Existir como valor — em vez de a compilação já executar — é o que cumpre a
 * restrição central do projeto: `Compiler::compileSelect($state, $dialect)` roda na
 * suíte unitária, sem banco, e o `UnitTestCase` prova isso reprovando qualquer teste
 * que abra conexão. No sistema antigo não havia como olhar o SQL gerado sem executá-lo,
 * porque as cláusulas eram montadas em métodos privados e nenhum método público
 * devolvia texto.
 *
 * É também o que faz `->toSql()` ser útil para quem usa a biblioteca: dá para inspecionar
 * a query antes de mandar, com os valores à vista.
 */
final readonly class CompiledQuery
{
    /**
     * @param array<string,array{mixed,int}> $binds placeholder sem `:` => [valor, tipo PDO]
     */
    public function __construct(public string $sql, public array $binds)
    {
    }

    /**
     * Só os valores, para asserção em teste e para depuração.
     *
     * @return array<string,mixed>
     */
    public function values(): array
    {
        return array_map(static fn (array $bind): mixed => $bind[0], $this->binds);
    }

    /**
     * Aplica os binds com o tipo declarado.
     *
     * `bindValue()` e não `execute($array)`: passar o array ao `execute()` manda tudo
     * como string, e com `ATTR_EMULATE_PREPARES` desligado o servidor leva o tipo a
     * sério — um `LIMIT` com string é erro no MySQL.
     */
    public function bindTo(PDOStatement $statement): void
    {
        foreach ($this->binds as $placeholder => [$value, $type]) {
            $statement->bindValue(':' . $placeholder, $value, $type);
        }
    }
}
