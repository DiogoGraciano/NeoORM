<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Migrations\Exception;

/**
 * O dialeto não tem como escrever este tipo.
 *
 * O caminho normal para isso é `SchemaValidator`, que reúne TODOS os problemas
 * do schema com contexto `tabela.coluna` antes de qualquer SQL ser gerado. Esta
 * exceção é a rede embaixo: se um tipo impossível chegar até a renderização,
 * falha aqui em vez de produzir DDL inválido que só o banco vai recusar, com uma
 * mensagem de erro do banco em vez de uma nossa.
 */
final class UnsupportedTypeException extends MigrationException
{
}
