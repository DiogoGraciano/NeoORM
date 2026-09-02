<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Abstract;

use Diogodg\Neoorm\Config;
use Diogodg\Neoorm\Migrations\Table;
use Diogodg\Neoorm\Query\Table as QueryTable;
use Diogodg\Neoorm\Schema\TableDefinition;
use RuntimeException;

/**
 * O contrato de um model: descrever uma tabela.
 *
 * Só isso. Semear a tabela saiu daqui — os dados iniciais agora são uma classe `Seeder`
 * em `PATH_SEEDS`, porque schema e dado mudam por motivos diferentes e num ritmo
 * diferente, e mantê-los no mesmo arquivo fazia o model de uma tabela de domínio grande
 * ser majoritariamente dado. Um model que ainda declare `seed()` faz `db:seed` parar com
 * o caminho do arquivo a criar, em vez de o método deixar de ser chamado em silêncio.
 *
 * Na 1.x esta classe estendia `Db` e herdava dele consulta, filtro, hidratação
 * e estado de paginação — o mesmo objeto era a tabela, o construtor de query E a linha
 * do resultado. Era a razão de o retorno nunca poder ser tipado (`PDO::FETCH_CLASS` de
 * `get_class($this)` só sabe produzir outro `Db`) e a razão de `Model::table()` rodar
 * DDL a cada instanciação.
 *
 * Consultar é trabalho de `Query\Database` e das tabelas geradas por
 * `neoorm generate:types`, que devolvem linhas tipadas. Um model não tem estado, não
 * abre conexão e nunca é instanciado pela biblioteca: `ModelSchemaLoader` e
 * `SchemaRegistry` chamam apenas os métodos estáticos.
 *
 * ```php
 * use App\Models\Generated\StateTable;
 *
 * /** @extends Model<StateTable> *\/
 * class State extends Model
 * {
 *     public const table = 'state';
 *
 *     public static function table(): Table { ... }
 * }
 *
 * $s = State::ref();                       // StateTable, com as colunas tipadas
 * $db->select()->from($s)->where(eq($s->ibge, 12))->one();
 * ```
 *
 * Estender esta classe é conveniência, não obrigação. Quem descobre models é o
 * `ModelSchemaLoader`, e o critério dele é ter `table()` estático — uma classe que não
 * herde daqui participa do schema do mesmo jeito. O que se perde sem herdar é `ref()`.
 *
 * @template TTable of QueryTable
 */
abstract class Model
{
    /**
     * O nome da tabela.
     *
     * Minúsculo por compatibilidade: é assim que todo model 1.x a declara, e renomear
     * quebraria cada `self::table` e cada `Outro::table` de foreign key sem ganhar nada.
     */
    public const table = '';

    /**
     * O schema da tabela.
     *
     * Devolve o builder — o `SchemaRegistry` chama `build()` e memoiza — ou já o
     * `TableDefinition` pronto, para quem monta o IR por outro caminho.
     */
    abstract public static function table(): Table|TableDefinition;

    /**
     * A referência TIPADA desta tabela, para montar consulta.
     *
     * `State::ref()` é `Tables::state()` sem precisar lembrar do nome do método gerado, e
     * fecha o círculo que faltava: até aqui o model descrevia a tabela e o código gerado
     * a consultava, sem nenhuma ligação declarada entre os dois.
     *
     * O tipo concreto vem do `@extends Model<StateTable>` do model — anotação, não
     * `use`, e a diferença importa: um `use` de algo em `Generated/` faria o model
     * fatalar quando o diretório não existisse, e `generate:types` precisa LER os models
     * para criá-lo. Sem a anotação isto ainda funciona, só devolve `Query\Table`, e
     * `generate:types --check` avisa quando a anotação existe e está errada.
     *
     * @return TTable
     */
    public static function ref(?string $alias = null): QueryTable
    {
        $registry = Config::getGeneratedNamespace() . '\\Tables';

        if (!class_exists($registry) || !method_exists($registry, 'for')) {
            throw new RuntimeException(
                "As tabelas tipadas ainda não existem ({$registry} não foi encontrada). "
                . 'Rode: vendor/bin/neoorm generate:types',
            );
        }

        /** @var TTable $table */
        $table = $registry::for(static::table, $alias);

        return $table;
    }
}
