<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Migrations\Introspection;

use Diogodg\Neoorm\Schema\SchemaDefinition;

/**
 * Lê o catálogo de um banco e devolve o MESMO IR que os builders produzem.
 *
 * "O mesmo IR" é a exigência inteira. Se a introspecção devolvesse uma forma
 * ligeiramente diferente — `INTEGER` onde o builder diz `INT`, `varchar` sem tamanho
 * onde o builder diz `VARCHAR(120)` —, então `differ(introspectado, declarado)`
 * estaria sempre acusando diferença, e o comando que deveria dizer "está tudo em
 * ordem" nunca diria isso.
 *
 * Por isso TODA normalização mora nas implementações desta interface, e nunca num
 * teste. Um teste que massageia um valor antes de comparar está escondendo uma
 * diferença que o usuário vai receber como migração espúria — foi assim que o
 * sistema antigo produzia um ALTER de collation em toda execução.
 */
interface CatalogReader
{
    /**
     * Nomes das tabelas que existem, ordenados.
     *
     * @return list<string>
     */
    public function tableNames(): array;

    /**
     * @param list<string>|null $onlyTables null lê todas
     */
    public function read(?array $onlyTables = null): SchemaDefinition;
}
