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
     * Propriedades configuradas (ORDER, GROUP, LIMIT, etc).
     *
     * @var array
     */
    private array $propertys = [];

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
     * Valores do bindParam das Propertys (LIMIT, OFFSET etc).
     *
     * @var array
     */
    private array $valuesBindProperty = [];

    /**
     * Contador de parâmetros do bindParam.
     *
     * @var int
     */
    private int $counterBind = 1;

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
}
