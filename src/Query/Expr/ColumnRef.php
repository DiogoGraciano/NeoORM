<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Query\Expr;

use Diogodg\Neoorm\Schema\Naming\IdentifierValidator;
use Diogodg\Neoorm\Schema\Type\TypeSpec;

/**
 * Referência a uma coluna de uma tabela — o `users.email` do Drizzle.
 *
 * Carrega o `TypeSpec` junto com o nome, e é o que separa isto de uma string: o `Binder`
 * o usa para decidir como o valor comparado vira bind — a data no formato da coluna, o
 * booleano na forma que cada banco espera.
 *
 * O parâmetro de tipo é FANTASMA: nada em runtime o usa. Existe para o analisador
 * estático ligar a coluna ao tipo do seu valor, e é o que abre caminho para uma
 * assinatura `eq(ColumnRef<T>, T)` recusar `eq($u->age, 'texto')` antes de rodar.
 *
 * Covariante porque `ColumnRef<UsersStatus>` tem que servir onde se espera
 * `ColumnRef<mixed>` — o compilador trata todas as colunas do mesmo jeito.
 *
 * @template-covariant TValue
 *
 * Os nomes são normalizados no construtor. Isso é o que permite ao compilador citar
 * sem revalidar: se um `ColumnRef` existe, o nome dele já passou pelo mesmo filtro
 * que o IR usa, e não há caminho por onde um identificador arbitrário chegue ao SQL.
 */
final readonly class ColumnRef implements Expression
{
    /** Nome da tabela, ou o alias quando a tabela foi apelidada. */
    public string $qualifier;

    public string $name;

    public function __construct(
        string $qualifier,
        string $name,
        public TypeSpec $type,
        public bool $notNull = false,
    ) {
        $this->qualifier = IdentifierValidator::normalize($qualifier, 'Qualificador de coluna');
        $this->name = IdentifierValidator::normalize($name, 'Nome de coluna');
    }

    /**
     * Como a coluna aparece em mensagem de erro: `users.email`.
     *
     * Sem citação: é texto para humano, não SQL. Quem monta SQL usa
     * `Dialect::quoteIdentifier()` nas duas partes separadamente.
     */
    public function qualified(): string
    {
        return $this->qualifier . '.' . $this->name;
    }

    /**
     * A mesma coluna vista por outro qualificador.
     *
     * Usado quando a tabela ganha alias: `Tables::users()->as('autor')` produz
     * colunas apontando para `autor`, e é isso que faz um self-join funcionar sem
     * as duas pontas colidirem.
     *
     * @return self<TValue>
     */
    public function withQualifier(string $qualifier): self
    {
        return new self($qualifier, $this->name, $this->type, $this->notNull);
    }
}
