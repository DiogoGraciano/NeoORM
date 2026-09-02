<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Query\State;

use Diogodg\Neoorm\Schema\Naming\IdentifierValidator;

/**
 * A tabela como o compilador precisa vê-la: um nome e, talvez, um alias.
 *
 * Deliberadamente menos do que a tabela gerada, que também sabe hidratar linhas e
 * conhece a classe do DTO. O compilador não precisa de nada disso — e se precisasse,
 * a camada de compilação passaria a depender do código gerado, o que impediria
 * testá-la sem antes rodar o gerador.
 */
final readonly class TableRef
{
    public string $name;

    public ?string $alias;

    public function __construct(string $name, ?string $alias = null)
    {
        $this->name = IdentifierValidator::normalize($name, 'Nome de tabela');
        $this->alias = $alias === null
            ? null
            : IdentifierValidator::normalize($alias, 'Alias de tabela');
    }

    /**
     * O nome pelo qual as colunas desta tabela são qualificadas.
     *
     * Depois de `FROM users AS autor`, quem qualifica é `autor` — usar `users` ali
     * é erro no PostgreSQL e ambiguidade no MySQL.
     */
    public function qualifier(): string
    {
        return $this->alias ?? $this->name;
    }
}
