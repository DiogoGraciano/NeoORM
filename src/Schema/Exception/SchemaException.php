<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Schema\Exception;

use RuntimeException;

/**
 * Base de tudo que o IR recusa.
 *
 * O sistema antigo engolia erros de definição de schema dentro de
 * `try { } catch { /* ignora *\/ }` e seguia com um schema incompleto. Aqui
 * cada recusa é tipada e carrega o contexto de onde ocorreu.
 */
class SchemaException extends RuntimeException
{
}
