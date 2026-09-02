<?php

declare(strict_types=1);

namespace Tests\App\SchemaModels\Loader;

use Diogodg\Neoorm\Migrations\Table;

/**
 * Classe base abstrata com `table()`.
 *
 * Um projeto real tem dessas — uma base que declara a assinatura e deixa a implementação
 * para as filhas. O loader não pode tratá-la como tabela: chamar `table()` aqui daria
 * erro, ou pior, criaria uma tabela chamada como a base.
 */
abstract class AbstractBase
{
    abstract public static function table(): Table;
}
