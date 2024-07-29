<?php
namespace app\db\tables;

use app\db\abstract\model;
use app\db\migrations\table;
use app\db\migrations\column;

class usuarioBloqueio extends model {
    public const table = "usuario_bloqueio";

    public function __construct() {
        parent::__construct(self::table);
    }

    public static function table(){
        return (new table(self::table, comment: "Tabela de usuários"))
                ->addColumn((new column("id_usuario", "INT"))->isPrimary()->isForeingKey(usuario::table())->isNotNull()->setComment("ID do usuário"))
                ->addColumn((new column("id_empresa", "INT"))->isPrimary()->isForeingKey(empresa::table())->setComment("ID da empresa"));
    }
}