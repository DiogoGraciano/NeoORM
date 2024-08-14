<?php
namespace app\db\migrations\driver;

use app\db\migrations\table;
use app\db\migrations\interface\column;
use stdClass;
use Exception;

/**
 * Classe base para criação do banco de dados.
 */
class columnPgsql implements column
{
    /**
     * Colunas.
     *
     * @var object
     */
    private $column;


    /**
     * Tipos de dados do pgsql.
     *
     * @var array
     */
    private const TYPES = [
        'SMALLINT',
        'INTEGER',
        'INT',
        'BIGINT',
        'DECIMAL',
        'NUMERIC',
        'REAL',
        'DOUBLE PRECISION',
        'SMALLSERIAL',
        'SERIAL',
        'BIGSERIAL',
        'MONEY',
        'CHARACTER',
        'CHAR',
        'VARCHAR',
        'TEXT',
        'BYTEA',
        'TIMESTAMP',
        'TIMESTAMPTZ',
        'DATE',
        'TIME',
        'TIMETZ',
        'INTERVAL',
        'BOOLEAN',
        'POINT',
        'LINE',
        'LSEG',
        'BOX',
        'PATH',
        'POLYGON',
        'CIRCLE',
        'CIDR',
        'INET',
        'MACADDR',
        'BIT',
        'VARBIT',
        'TSVECTOR',
        'TSQUERY',
        'UUID',
        'XML',
        'JSON',
        'JSONB',
        'ARRAY',
        'RANGE',
        'HSTORE',
        'ENUM',
        'GEOGRAPHY',
        'GEOMETRY'
    ];

    public function __construct(string $name,string $type,string|int|null $size = null)
    {
        $type = strtoupper(trim($type));

        if(in_array($type,self::TYPES)){

            $this->column = new StdClass;

            if($size && $this->validateSize($type,$size)){
                $this->column->type = $type."({$size})";
            }
            else 
                $this->column->type = $type;

            $name = strtolower(trim($name));

            if(!$this->validateName($name)){
                throw new Exception("Nome é invalido");
            }

            
            $this->column->name = $name;
            $this->column->size = $size;
            $this->column->primary = "";
            $this->column->unique = "";
            $this->column->null = "";
            $this->column->defaut = "";
            $this->column->comment = "";
            $this->column->defautValue = null;
            $this->column->commentValue = "";
            $this->column->foreingTable = null;
            $this->column->foreingColumn = null;
            $this->column->foreingTableClass = null;
            $this->column->foreingKey = "";
        }
        else 
            throw new Exception("Tipo é invalido");
        
    }

    public function isNotNull()
    {
        $this->column->null = "NOT NULL";
    }

    public function isPrimary()
    {
        $this->column->primary = "PRIMARY KEY ({$this->column->name})";
    }

    public function isUnique()
    {
        $this->column->unique = "UNIQUE ({$this->column->name})";
    }

    public function isForeingKey(table $foreingTable,string $foreingColumn = "id")
    {
        $this->column->foreingKey = "FOREIGN KEY ({$this->column->name}) REFERENCES {$foreingTable->getTable()}({$foreingColumn})";
        $this->column->foreingTable = $foreingTable->getTable();
        $this->column->foreingColumn = $foreingColumn;
        $this->column->foreingTableClass = $foreingTable;
    }

    public function setDefaut(string|int|float|null $value = null)
    {
        if(is_string($value))
            $this->column->defaut = "DEFAULT '".$value."'";
        elseif(is_null($value) && !$this->column->null) 
            $this->column->defaut = "DEFAULT NULL";
        elseif(!is_null($value)) 
            $this->column->defaut = "DEFAULT ".$value;

        $this->column->defautValue = $value;
    }

    public function getColumn()
    {
        return $this->column;
    }

    public function setComment($comment)
    {
        $this->column->comment = "COMMENT '{$comment}'";

        $this->column->commentValue = $comment;
        return $this;
    }

    private function validateSize(string $type, string|int $size)
    {
        if (in_array($type, ["DECIMAL", "NUMERIC"]) && preg_match("/\d+,\d+$/", $size)) {
            return true;
        }

        $size = intval($size);

        if ($size < 0) {
            throw new Exception("Tamanho é inválido");
        } elseif (!in_array($type, ["CHAR", "VARCHAR", "BIT", "VARBIT"])) {
            throw new Exception("Tamanho não deve ser informado para o tipo informado");
        } elseif (($type == "CHAR" || $type == "BIT") && $size > 10485760) { 
            throw new Exception("Tamanho é inválido para o tipo informado");
        } elseif (($type == "VARCHAR" || $type == "VARBIT") && $size > 10485760) {
            throw new Exception("Tamanho é inválido para o tipo informado");
        }

        return true;
    }

    private function validateName($name) {
        // Expressão regular para verificar se o nome da tabela contém apenas caracteres permitidos
        $regex = '/^[a-zA-Z_][a-zA-Z0-9_]*$/';
        
        // Verifica se o nome da tabela corresponde à expressão regular
        if (preg_match($regex, $name)) {
            return true; // Nome da tabela é válido
        } else {
            return false; // Nome da tabela é inválido
        }
    }
}