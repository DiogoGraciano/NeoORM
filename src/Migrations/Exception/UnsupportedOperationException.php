<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Migrations\Exception;

/**
 * O dialeto não sabe traduzir esta operação.
 *
 * Com o conjunto de 20 operações fechado e todos os `compile*` abstratos, isto
 * só acontece se alguém implementar `SchemaOperation` fora do conjunto — e é
 * melhor falhar dizendo isso do que devolver lista vazia e produzir uma migração
 * que silenciosamente não faz parte do que foi pedido.
 */
final class UnsupportedOperationException extends MigrationException
{
}
