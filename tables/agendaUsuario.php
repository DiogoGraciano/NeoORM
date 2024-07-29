<?php
namespace app\db\tables;

use app\db\abstract\model;
use app\db\migrations\table;
use app\db\migrations\column;

class agendaUsuario extends model {
    public const table = "agenda_usuario";

    public function __construct() {
        parent::__construct(self::table);
    }

    public static function table(){
        return (new table(self::table,comment:"Tabela de vinculo entre agendamentos e usuarios"))
                ->addColumn((new column("id_agenda","INT"))->isPrimary()->isForeingKey(agenda::table())->setComment("ID agenda"))
                ->addColumn((new column("id_usuario","INT"))->isPrimary()->isForeingKey(usuario::table())->setComment("ID Usuario"));
    }

}