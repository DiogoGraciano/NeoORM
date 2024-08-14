<?php
namespace app\db\tables;

use app\db\abstract\model;
use app\db\migrations\table;
use app\db\migrations\column;

class usuarioApi extends model {
    public const table = "usuario_api";

    public function __construct() {
        parent::__construct(self::table,get_class($this));
    }

    public static function table(){
        return (new table(self::table, comment: "Tabela de usuários da api"))
                ->addColumn((new column("id", "INT"))->isPrimary()->isNotNull()->setComment("ID do usuário"))
                ->addColumn((new column("usuario", "VARCHAR", 50))->isNotNull()->setComment("Nome do usuário"))
                ->addColumn((new column("senha", "VARCHAR", 100))->isNotNull()->setComment("Senha do usuário"))
                ->addColumn((new column("tipo_usuario", "INT"))->isNotNull()->setComment("Tipo de usuário: 1 -> programa, 2 -> empresa"))
                ->addColumn((new column("id_empresa", "INT"))->isForeingKey(empresa::table())->setComment("ID da empresa"));
    }
}