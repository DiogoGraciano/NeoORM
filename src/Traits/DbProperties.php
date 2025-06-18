<?php

namespace Diogodg\Neoorm\Traits;

/**
 * Trait que concentra as propriedades (atributos) da classe Db.
*/
trait DbProperties
{
    /**
     * Tabela atual.
     *
     * @var string
     */
    private string $table;

    /**
     * Classe (Model) associada a esta tabela.
     *
     * @var string
     */
    private string $class;

    /**
     * Objeto da tabela (colunas => valores).
     *
     * @var array
     */
    private array $object = [];

    /**
     * Array de colunas da tabela.
     *
     * @var array
     */
    private array $columns = [];

    /**
     * Joins configurados.
     *
     * @var array
     */
    private array $joins = [];

    /**
     * Debug está ativo?
     *
     * @var bool
     */
    private bool $debug = false;

    /**
     * Propriedades configuradas (ORDER).
     *
     * @var array
     */
    private array $order = [];


    /**
     * Propriedades configuradas (GROUP).
     *
     * @var array
     */
    private array $group = [];

    /**
     * Propriedades configuradas (HAVING).
     *
     * @var array
     */
    private array $having = [];

    /**
     * Propriedades configuradas (LIMIT).
     *
     * @var array
     */
    private array $limit = [];


    /**
     * Filtros configurados (WHERE).
     *
     * @var array
     */
    private array $filters = [];

    /**
     * Valores do bindParam.
     *
     * @var array
     */
    private array $valuesBind = [];

    /**
     * Instância do PDO.
     *
     * @var PDO
     */
    private \PDO $pdo;

    /**
     * Retorna resultados como array?
     *
     * @var bool
     */
    private bool $asArray = false;

    /**
     * Indica se já foi adicionada alguma ordenação (ORDER BY).
     *
     * @var bool
     */
    private bool $hasOrder = false;

    /**
     * Indica se já foi adicionado algum filtro HAVING.
     *
     * @var bool
     */
    private bool $hasHaving = false;
}
