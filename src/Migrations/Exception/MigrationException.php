<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Migrations\Exception;

use RuntimeException;

/**
 * Base de tudo que pode dar errado no sistema de migrações.
 *
 * Existir como tipo é o ponto: o sistema antigo enterrava quase toda falha em
 * `try { } catch { /* ignora *\/ }`, e o resultado era um migrate que sempre
 * dizia ter funcionado. Aqui a falha propaga, com tipo e mensagem, e quem
 * escolhe engolir alguma coisa escolhe explicitamente qual.
 */
class MigrationException extends RuntimeException
{
}
