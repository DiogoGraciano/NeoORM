<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Migrations\Diff;

use Diogodg\Neoorm\Migrations\Operation\SchemaOperation;

/**
 * O resultado de comparar uma tabela com ela mesma numa versão anterior.
 *
 * Carrega mais que a lista de operações porque o escudo de foreign keys precisa
 * saber, depois, coisas que só a comparação por tabela descobriu: quais colunas
 * mudaram de TIPO (e não apenas mudaram), se a chave primária foi mexida, e quais
 * foreign keys já vão sair e voltar por conta própria.
 *
 * Recalcular isso no escudo, comparando as definições outra vez, criaria duas
 * respostas para a mesma pergunta — e duas respostas que podem discordar é o
 * mecanismo exato pelo qual o sistema antigo produzia migração espúria.
 */
final readonly class TableDiff
{
    /**
     * @param list<SchemaOperation> $operations
     * @param list<string>          $typeChangedColumns nomes já no espaço de nomes novo
     * @param list<string>          $rebuiltForeignKeys FKs que a comparação normal já derruba e recria
     */
    public function __construct(
        public array $operations = [],
        public array $typeChangedColumns = [],
        public bool $primaryKeyChanged = false,
        public array $rebuiltForeignKeys = [],
    ) {
    }
}
