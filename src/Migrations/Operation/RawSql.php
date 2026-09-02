<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Migrations\Operation;

use Diogodg\Neoorm\Migrations\Exception\MigrationException;
use Diogodg\Neoorm\Schema\Naming\IdentifierValidator;

/**
 * SQL escrito à mão, passado adiante sem interpretação.
 *
 * A válvula de escape para o que o IR não sabe descrever: uma view, um trigger,
 * um backfill de dados no meio de uma mudança de coluna. O differ NUNCA produz
 * esta operação — ela só entra por `migration:generate --empty` seguido de edição
 * manual, ou por código de migração escrito à mão.
 *
 * Consequência assumida: o conteúdo não passa por `quoteIdentifier()` nem
 * `quoteLiteral()`, porque não há o que citar num SQL que já veio escrito. Quem
 * escreve responde pelo que escreveu, e é por isso que existe um único tipo de
 * operação assim em vez de a interpolação de SQL cru estar espalhada.
 */
final readonly class RawSql implements SchemaOperation
{
    public string $sql;

    public string $table;

    public function __construct(string $sql, string $table = '', public bool $destructive = false)
    {
        $trimmed = trim($sql);

        if ($trimmed === '') {
            throw new MigrationException('RawSql não pode ser vazio.');
        }

        // Um statement por operação. O runner manda um `exec()` por statement e
        // `MYSQL_ATTR_MULTI_STATEMENTS` fica desligado, então um bloco com `;` no
        // meio falharia no banco em vez de aqui, onde a mensagem é útil.
        if (str_contains(rtrim($trimmed, ';'), ';')) {
            throw new MigrationException(
                'RawSql aceita um único statement; use uma operação por statement. SQL recebido: '
                . $trimmed,
            );
        }

        $this->sql = rtrim($trimmed, "; \t\n\r\0\x0B");
        $this->table = $table === '' ? '' : IdentifierValidator::normalize($table, 'Nome de tabela');
    }

    public function tableName(): string
    {
        return $this->table;
    }

    public function isDestructive(): bool
    {
        return $this->destructive;
    }

    /**
     * Segue a flag que quem escreveu o SQL declarou.
     *
     * Ninguém aqui sabe o que o SQL faz — é uma string opaca, e é o único ponto do sistema
     * em que isso é verdade. O differ nunca produz `RawSql`; ela existe só para quem escreve
     * SQL à mão, e essa pessoa é a única que pode responder.
     */
    public function discardsData(): bool
    {
        return $this->destructive;
    }

    public function describe(): string
    {
        $preview = preg_replace('/\s+/', ' ', $this->sql) ?? $this->sql;

        if (mb_strlen($preview) > 60) {
            $preview = mb_substr($preview, 0, 57) . '...';
        }

        return "executa SQL cru: {$preview}";
    }
}
