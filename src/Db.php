<?php
namespace Diogodg\Neoorm;

use Exception;

/**
 * Classe base que representa o “core” para interação com o banco de dados.
 * Ela importa *traits* que segregam a lógica em partes menores.
 */
class Db
{
    // Importamos as *traits* que contêm blocos de métodos separados
    use Traits\DbProperties;
    use Traits\DbSelect;
    use Traits\DbFilters;
    use Traits\DbCrud;
    use Traits\DbHelpers;

    /**
     * Construtor da classe.
     *
     * @param string $table Nome da tabela do banco de dados.
     * @param string|null $class Nome totalmente qualificado da classe (Model) associada.
     */
    public function __construct(string $table, string|null $class = null)
    {
        // Obtemos a instância de PDO de outra classe
        $this->pdo = Connection::getConnection();

        $this->table = $table;
        $this->class = $class;

        $this->getColumnTable();
        $this->setObjectNull();
    }

    /**
     * Métodos “mágicos” para manipular colunas do objeto interno ($this->object).
     */
    public function __set($name, $value)
    {
        return $this->object[$name] = $value;
    }

    public function __get($name)
    {
        if (array_key_exists($name, $this->object)) {
            return $this->object[$name];
        }

        $trace = debug_backtrace();
        throw new Exception(
            'Column not found: ' . $name .
            ' in ' . $trace[0]['file'] .
            ' on line ' . $trace[0]['line']
        );
    }

    public function __isset($name)
    {
        return isset($this->object[$name]);
    }

    public function __unset($name)
    {
        unset($this->object[$name]);
    }
}