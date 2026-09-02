<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Schema\Exception;

/**
 * Tipo estruturalmente impossível: escala maior que a precisão, tamanho
 * negativo, ENUM sem valores, nome de tipo inexistente.
 *
 * Não cobre "este dialeto não suporta este tipo" — isso é decisão de dialeto e
 * sai por SchemaValidator, com o nome da tabela e da coluna junto.
 */
final class InvalidTypeException extends SchemaException
{
}
