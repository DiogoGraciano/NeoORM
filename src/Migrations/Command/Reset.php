<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Migrations\Command;

use Diogodg\Neoorm\Connection;
use Diogodg\Neoorm\Migrations\DatabaseAdmin;
use Diogodg\Neoorm\Migrations\Exception\MigrationException;
use Diogodg\Neoorm\Migrations\Runner\UpResult;

/**
 * Derruba o banco, recria, e aplica todas as migrações.
 *
 * Como não há migração `down`, é isto que se usa em desenvolvimento quando o banco entra num
 * estado que não vale destrinchar. É deliberadamente a única forma de voltar atrás: `down`
 * automático dá uma sensação de reversibilidade que não existe — um `DROP COLUMN` desfeito
 * recria a coluna vazia, e o dado não volta.
 *
 * Recusa em produção, e o `--force` NÃO cobre isso: a confirmação de produção não é uma flag.
 */
final class Reset
{
    public function __construct(private readonly MigrationContext $context)
    {
    }

    /**
     * @param bool $force dispensa a confirmação interativa (não dispensa a checagem de ambiente)
     * @param string|null $confirmation o nome do banco, digitado por quem pediu
     */
    public function execute(bool $force = false, bool $seed = false, ?string $confirmation = null): UpResult
    {
        if ($this->context->isProduction()) {
            throw new MigrationException(
                'db:reset APAGA o banco inteiro e não roda em produção. Não há flag que libere isto: '
                . 'se é realmente o que você quer, faça à mão, com backup.',
            );
        }

        $database = $this->context->database->database;

        // Digitar o nome do banco é a confirmação. Um `--force` não serve sozinho porque é
        // exatamente o que se cola de um histórico de shell sem ler.
        if (!$force && $confirmation !== $database) {
            throw new MigrationException(
                "db:reset vai APAGAR o banco '{$database}' e recriá-lo do zero. Confirme digitando o "
                . 'nome do banco, ou passe --force.',
            );
        }

        $this->context->output->warning("Recriando o banco '{$database}'...");

        (new DatabaseAdmin($this->context->database))->recreate();

        // A conexão anterior aponta para um banco que não existe mais. Sem fechá-la, o primeiro
        // statement seguinte falharia com um erro sobre o banco ausente — a duas camadas de
        // distância da causa.
        Connection::close();
        $this->context->reconnect();

        $result = (new Up($this->context))->execute();

        if ($seed) {
            (new Seed($this->context))->execute();
        }

        $this->context->output->success("Banco '{$database}' recriado.");

        return $result;
    }
}
