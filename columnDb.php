<?php
namespace app\db;
use stdClass;
use Exception;

/**
 * Classe base para criação do banco de dados.
 */
class columnDb
{
    /**
     * Colunas.
     *
     * @var object
     */
    private $column;


    /**
     * Tipos de dados do mysql.
     *
     * @var array
     */
    private const types = [
        'INT',
        'TINYINT',
        'SMALLINT',
        'MEDIUMINT',
        'BIGINT',
        'DECIMAL',
        'FLOAT',
        'DOUBLE',
        'BIT',
        'DATE',
        'TIME',
        'DATETIME',
        'TIMESTAMP',
        'YEAR',
        'CHAR',
        'VARCHAR',
        'BINARY',
        'VARBINARY',
        'TINYBLOB',
        'BLOB',
        'MEDIUMBLOB',
        'LONGBLOB',
        'TINYTEXT',
        'TEXT',
        'MEDIUMTEXT',
        'LONGTEXT'
    ];

    public function __construct(string $name,string $type,int|null $size = null)
    {
        $type = strtoupper(trim($type));
        
        if(in_array($type,self::types)){

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
            $this->column->foreingKey = "";
        }
        else 
            throw new Exception("Tipo é invalido");
        
    }

    public function isNotNull(){
        $this->column->null = "NOT NULL";
        return $this;
    }

    public function isPrimary(){
        $this->column->primary = "PRIMARY KEY ({$this->column->name})";
        return $this;
    }

    public function isUnique(){
        $this->column->unique = "UNIQUE ({$this->column->name})";
        return $this;
    }

    public function isForeingKey(tableDb $foreingTable,string $foreingColumn = "id"){
        $this->column->foreingKey = "FOREIGN KEY ({$this->column->name}) REFERENCES {$foreingTable->getTable()}({$foreingColumn})";
        $this->column->foreingTable = $foreingTable->getTable();
        $this->column->foreingColumn = $foreingColumn;
        return $this;
    }

    public function setDefaut(string|int|float|null $value = null){

        if(is_string($value))
            $this->column->defaut = "DEFAULT '".$value."'";
        elseif(is_null($value) && !$this->column->null) 
            $this->column->defaut = "DEFAULT NULL";
        elseif(!is_null($value)) 
            $this->column->defaut = "DEFAULT ".$value;

        $this->column->defautValue = $value;

        return $this;
    }

    public function getColumn(){
        return $this->column;
    }

    public function setComment($comment){
        $this->column->comment = "COMMENT '{$comment}'";

        $this->column->commentValue = $comment;
        return $this;
    }

    private function validateSize(string $type,int $size){
        if($size < 0){
            throw new Exception("Tamanho é invalido");
        }
        elseif(!in_array($type,["CHAR","BINARY","VARCHAR","VARBINARY"])){
            throw new Exception("Tamanho não deve ser informado para o tipo informado");
        }
        elseif(($type == "CHAR" || $type ==  "BINARY") && $size > 255){
            throw new Exception("Tamanho é invalido para o tipo informado");
        }
        elseif(($type == "VARCHAR" || $type ==  "VARBINARY") && $size > 65535){
            throw new Exception("Tamanho é invalido para o tipo informado");
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