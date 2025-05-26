<?php
namespace Diogodg\Neoorm\Migrations\Driver;

use Diogodg\Neoorm\Migrations\Interface\Column;
use stdClass;
use Exception;

/**
 * Classe base para criação do banco de dados.
 */
class ColumnPgsql implements Column
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

            if($size && !$this->validateSize($type,$size)){
                throw new Exception($name.": Tamanho é inválido");
            }
            
            $name = strtolower(trim($name));

            if(!$this->validateName($name)){
                throw new Exception($name.": Nome é invalido");
            }

            $this->column->type = $type;
            $this->column->name = $name;
            $this->column->size = $size;
            $this->column->primary = "";
            $this->column->unique = "";
            $this->column->null = "";
            $this->column->default = "";
            $this->column->comment = "";
            $this->column->defaultValue = null;
            $this->column->commentValue = "";
        }
        else 
            throw new Exception($name.": Tipo é invalido: ".$type);
        
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

    public function setDefault(string|int|float|null $value = null,bool $is_constant = false)
    {
        if($is_constant && !is_null($value))
            $this->column->default = "DEFAULT ".$value;
        elseif(is_string($value))
            $this->column->default = "DEFAULT '".$value."'";
        elseif(is_null($value) && !$this->column->null) 
            $this->column->default = "DEFAULT NULL";
        elseif(!is_null($value)) 
            $this->column->default = "DEFAULT ".$value;

        $this->column->defaultValue = $value;
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