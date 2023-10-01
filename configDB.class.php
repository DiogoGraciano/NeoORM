<?php
class configDB{

    private $pdo;
    
    public function getPDO() {

        //$this->pdo = new PDO("pgsql:host=localhost;port=5432;dbname=app;user=postgres;password=154326");
        $this->pdo = new PDO("mysql:host=localhost;port=3306;dbname=app;user=root");

        return $this->pdo;
    }
}

?>