<?php
namespace app\db\tables;

use app\db\abstract\model;
use app\db\migrations\table;
use app\db\migrations\column;

class empresa extends model {
    public const table = "empresa";

    public function __construct() {
        parent::__construct(self::table,get_class($this));
    }

    public static function table(){
        return (new table(self::table,comment:"Tabela de empresas"))
                ->addColumn((new column("id","INT"))->isPrimary()->setComment("ID do cliente"))
                ->addColumn((new column("nome","VARCHAR",300))->isNotNull()->isUnique()->setComment("Nome da empresa"))
                ->addColumn((new column("email","VARCHAR",300))->isNotNull()->setComment("Email da empresa"))
                ->addColumn((new column("telefone","VARCHAR",13))->isNotNull()->setComment("Telefone da empresa"))
                ->addColumn((new column("cnpj","VARCHAR",14))->isNotNull()->setComment("CNPJ da empresa"))
                ->addColumn((new column("razao","VARCHAR",300))->isNotNull()->isUnique()->setComment("Razão social da empresa"))
                ->addColumn((new column("fantasia","VARCHAR",300))->isNotNull()->setComment("Nome fantasia da empresa"));
    }
}