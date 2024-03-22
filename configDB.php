<?php
namespace app\db;

class ConfigDB{

    protected $pdo = false;
    
    protected function getConnection() {
        try {
            $this->pdo = new \PDO("mysql:host=localhost;port=3306;dbname=bd;user=root;charset=utf8mb4");
            $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

            return $this->pdo;
        } catch(\PDOException $e) {
            Logger::error($e->getMessage());
        }
    }
}

?>
