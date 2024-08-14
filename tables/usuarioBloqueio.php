<?php
namespace app\db\tables;

use app\db\abstract\model;
use app\db\migrations\table;
use app\db\migrations\column;

class usuarioBloqueio extends model {
    public const table = "usuario_bloqueio";

    public function __construct() {
        parent::__construct(self::table,get_class($this));
    }

    public static function table(){
        return (new table(self::table, comment: "Tabela de usuários bloqueados"))
                ->addColumn((new column("id", "INT"))->isPrimary()->setComment("ID do bloqueio"))
                ->addColumn((new column("id_usuario", "INT"))->isForeingKey(usuario::table())->isNotNull()->setComment("ID do usuário"))
                ->addColumn((new column("id_empresa", "INT"))->isForeingKey(empresa::table())->isNotNull()->setComment("ID da empresa"));
    }
}