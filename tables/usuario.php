<?php
namespace app\db\tables;

use app\db\abstract\model;
use app\db\migrations\table;
use app\db\migrations\column;

class usuario extends model {
    public const table = "usuario";

    public function __construct() {
        parent::__construct(self::table);
    }

    public static function table(){
        return (new table(self::table, comment: "Tabela de usuários"))
                ->addColumn((new column("id", "INT"))->isPrimary()->isNotNull()->setComment("ID do usuário"))
                ->addColumn((new column("nome", "VARCHAR", 500))->isNotNull()->setComment("Nome do usuário"))
                ->addColumn((new column("cpf_cnpj", "VARCHAR", 14))->isUnique()->isNotNull()->setComment("CPF ou CNPJ do usuário"))
                ->addColumn((new column("telefone", "VARCHAR", 11))->isNotNull()->setComment("Telefone do usuário"))
                ->addColumn((new column("senha", "VARCHAR", 150))->isNotNull()->setComment("Senha do usuário"))
                ->addColumn((new column("email", "VARCHAR", 200))->isUnique()->setComment("Email do usuário"))
                ->addColumn((new column("tipo_usuario", "INT"))->isNotNull()->setComment("Tipo de usuário: 0 -> ADM, 1 -> empresa, 2 -> funcionario, 3 -> usuário, 4 -> cliente cadastrado"))
                ->addColumn((new column("id_empresa", "INT"))->isForeingKey(empresa::table())->setComment("ID da empresa"));
    }
}