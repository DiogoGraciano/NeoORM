<?php

declare(strict_types=1);

namespace Tests\Support\Factory;

use Diogodg\Neoorm\Schema\ColumnDefinition;
use Diogodg\Neoorm\Schema\PrimaryKeyDefinition;
use Diogodg\Neoorm\Schema\SchemaDefinition;
use Diogodg\Neoorm\Schema\TableDefinition;
use Diogodg\Neoorm\Schema\Type\TypeSpec;
use Diogodg\Neoorm\Schema\Value\DefaultValue;

/**
 * Schemas montados para exercitar cada braço do gerador.
 *
 * Não são schemas realistas: são a matriz de casos. Cada coluna existe porque uma
 * decisão do emissor depende dela — a nullable com DEFAULT existe para o marcador
 * `Unspecified`, a de ENUM para o enum PHP, a DECIMAL para `numeric-string`.
 */
final class CodegenSchemas
{
    private function __construct()
    {
    }

    /**
     * A matriz: uma coluna por decisão de emissão.
     */
    public static function matrix(): SchemaDefinition
    {
        return new SchemaDefinition([
            new TableDefinition(
                name: 'users',
                columns: [
                    // NOT NULL sem default: o único caso obrigatório no INSERT.
                    new ColumnDefinition('name', TypeSpec::parse('VARCHAR', 120), notNull: true, comment: 'Nome do usuário'),
                    // A PK vem depois de outra coluna de propósito: posição não significa
                    // identidade, e o read-back do MySQL precisa usar este metadado.
                    new ColumnDefinition('id', TypeSpec::parse('INT'), notNull: true, autoIncrement: true),
                    // Nullable sem default: omitir e gravar NULL dão no mesmo.
                    new ColumnDefinition('phone', TypeSpec::parse('VARCHAR', 20)),
                    // NOT NULL com default: null significa "usa o DEFAULT".
                    new ColumnDefinition(
                        'balance',
                        TypeSpec::parse('DECIMAL', '10,2'),
                        notNull: true,
                        default: DefaultValue::literal('0.00'),
                    ),
                    new ColumnDefinition(
                        'active',
                        TypeSpec::parse('BOOLEAN'),
                        notNull: true,
                        default: DefaultValue::literal(true),
                    ),
                    new ColumnDefinition(
                        'created_at',
                        TypeSpec::parse('DATETIME'),
                        notNull: true,
                        default: DefaultValue::expression('CURRENT_TIMESTAMP'),
                    ),
                    new ColumnDefinition(
                        'status',
                        TypeSpec::parse("ENUM('ativo','bloqueado','pendente')"),
                        notNull: true,
                        default: DefaultValue::literal('pendente'),
                    ),
                    // Nullable COM default: o caso ambíguo, e o único que usa Unspecified.
                    new ColumnDefinition(
                        'nickname',
                        TypeSpec::parse('VARCHAR', 40),
                        default: DefaultValue::literal('anônimo'),
                    ),
                    new ColumnDefinition('metadata', TypeSpec::parse('JSON')),
                    new ColumnDefinition('avatar', TypeSpec::parse('BLOB')),
                    new ColumnDefinition('code', TypeSpec::parse('CHAR', 10)),
                ],
                primaryKey: new PrimaryKeyDefinition('pk_users', ['id']),
                comment: 'Usuários do sistema',
            ),
            new TableDefinition(
                name: 'schedule_user',
                columns: [
                    new ColumnDefinition('schedule_id', TypeSpec::parse('INT'), notNull: true),
                    new ColumnDefinition('user_id', TypeSpec::parse('INT'), notNull: true),
                ],
                // Chave composta e sem auto incremento: o Insert não tem coluna opcional
                // nenhuma, e o nome da classe vem de tabela com underscore.
                primaryKey: new PrimaryKeyDefinition('pk_schedule_user', ['schedule_id', 'user_id']),
            ),
        ]);
    }

    /**
     * Valores de ENUM que não rendem cases distintos.
     *
     * `in-progress` e `in_progress` produzem o mesmo `InProgress`. O emissor tem que
     * desistir do enum PHP e deixar a coluna como string, em vez de emitir um arquivo
     * com case duplicado — que nem parseia.
     */
    public static function collidingEnum(): SchemaDefinition
    {
        return new SchemaDefinition([
            new TableDefinition(
                name: 'jobs',
                columns: [
                    new ColumnDefinition('id', TypeSpec::parse('INT'), notNull: true, autoIncrement: true),
                    new ColumnDefinition(
                        'state',
                        TypeSpec::parse("ENUM('in-progress','in_progress')"),
                        notNull: true,
                    ),
                ],
                primaryKey: new PrimaryKeyDefinition('pk_jobs', ['id']),
            ),
        ]);
    }
}
