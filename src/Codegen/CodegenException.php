<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Codegen;

use RuntimeException;

/**
 * O gerador se recusa a emitir.
 *
 * Sempre preferível a emitir algo sutilmente errado: os arquivos são commitados, então
 * um DTO com a coluna errada entra no repositório e passa a ser lido como verdade.
 */
final class CodegenException extends RuntimeException
{
}
