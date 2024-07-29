<?php
namespace app\db\tables;

use app\db\abstract\model;
use app\db\migrations\table;
use app\db\migrations\column;
use app\db\db;

class estado extends model {
    public const table = "estado";

    public function __construct() {
        parent::__construct(self::table);
    }

    public static function table(){
        return (new table(self::table,comment:"Tabela de estados"))
                ->addColumn((new column("id","INT"))->isPrimary()->setComment("ID da cidade"))
                ->addColumn((new column("nome","VARCHAR",120))->isNotNull()->setComment("Nome do estado"))
                ->addColumn((new column("uf","VARCHAR",2))->isNotNull()->setComment("nome da Uf"))
                ->addColumn((new column("pais","INT"))->isNotNull()->isForeingKey(pais::table())->setComment("id da pais do estado"))
                ->addColumn((new column("ibge","INT"))->isUnique()->setComment("id do IBJE do estado"))
                ->addColumn((new column("ddd","VARCHAR",50))->setComment("DDDs separado por , da Uf"));
    }

    public static function seed(){
        $object = new db(self::table);
        if(!$object->addLimit(1)->selectColumns("id")){
            $object->nome = "Acre";
            $object->uf = "AC";
            $object->pais = 1;
            $object->ibge = 12;
            $object->ddd = "68";
            $object->store();

            $object->nome = "Alagoas";
            $object->uf = "AL";
            $object->pais = 1;
            $object->ibge = 27;
            $object->ddd = "82";
            $object->store();

            $object->nome = "Amapá";
            $object->uf = "AP";
            $object->pais = 1;
            $object->ibge = 16;
            $object->ddd = "96";
            $object->store();

            $object->nome = "Amazonas";
            $object->uf = "AM";
            $object->pais = 1;
            $object->ibge = 13;
            $object->ddd = "92,97";
            $object->store();

            $object->nome = "Bahia";
            $object->uf = "BA";
            $object->pais = 1;
            $object->ibge = 29;
            $object->ddd = "71,73,74,75,77";
            $object->store();

            $object->nome = "Ceará";
            $object->uf = "CE";
            $object->pais = 1;
            $object->ibge = 23;
            $object->ddd = "85,88";
            $object->store();

            $object->nome = "Distrito Federal";
            $object->uf = "DF";
            $object->pais = 1;
            $object->ibge = 53;
            $object->ddd = "61";
            $object->store();

            $object->nome = "Espírito Santo";
            $object->uf = "ES";
            $object->pais = 1;
            $object->ibge = 32;
            $object->ddd = "27,28";
            $object->store();

            $object->nome = "Goiás";
            $object->uf = "GO";
            $object->pais = 1;
            $object->ibge = 52;
            $object->ddd = "62,64";
            $object->store();

            $object->nome = "Maranhão";
            $object->uf = "MA";
            $object->pais = 1;
            $object->ibge = 21;
            $object->ddd = "98,99";
            $object->store();

            $object->nome = "Mato Grosso";
            $object->uf = "MT";
            $object->pais = 1;
            $object->ibge = 51;
            $object->ddd = "65,66";
            $object->store();

            $object->nome = "Mato Grosso do Sul";
            $object->uf = "MS";
            $object->pais = 1;
            $object->ibge = 50;
            $object->ddd = "67";
            $object->store();

            $object->nome = "Minas Gerais";
            $object->uf = "MG";
            $object->pais = 1;
            $object->ibge = 31;
            $object->ddd = "31,32,33,34,35,37,38";
            $object->store();

            $object->nome = "Pará";
            $object->uf = "PA";
            $object->pais = 1;
            $object->ibge = 15;
            $object->ddd = "91,93,94";
            $object->store();

            $object->nome = "Paraíba";
            $object->uf = "PB";
            $object->pais = 1;
            $object->ibge = 25;
            $object->ddd = "83";
            $object->store();

            $object->nome = "Paraná";
            $object->uf = "PR";
            $object->pais = 1;
            $object->ibge = 41;
            $object->ddd = "41,42,43,44,45,46";
            $object->store();

            $object->nome = "Pernambuco";
            $object->uf = "PE";
            $object->pais = 1;
            $object->ibge = 26;
            $object->ddd = "81,87";
            $object->store();

            $object->nome = "Piauí";
            $object->uf = "PI";
            $object->pais = 1;
            $object->ibge = 22;
            $object->ddd = "86,89";
            $object->store();

            $object->nome = "Rio de Janeiro";
            $object->uf = "RJ";
            $object->pais = 1;
            $object->ibge = 33;
            $object->ddd = "21,22,24";
            $object->store();

            $object->nome = "Rio Grande do Norte";
            $object->uf = "RN";
            $object->pais = 1;
            $object->ibge = 24;
            $object->ddd = "84";
            $object->store();

            $object->nome = "Rio Grande do Sul";
            $object->uf = "RS";
            $object->pais = 1;
            $object->ibge = 43;
            $object->ddd = "51,53,54,55";
            $object->store();

            $object->nome = "Rondônia";
            $object->uf = "RO";
            $object->pais = 1;
            $object->ibge = 11;
            $object->ddd = "69";
            $object->store();

            $object->nome = "Roraima";
            $object->uf = "RR";
            $object->pais = 1;
            $object->ibge = 14;
            $object->ddd = "95";
            $object->store();

            $object->nome = "Santa Catarina";
            $object->uf = "SC";
            $object->pais = 1;
            $object->ibge = 42;
            $object->ddd = "47,48,49";
            $object->store();

            $object->nome = "São Paulo";
            $object->uf = "SP";
            $object->pais = 1;
            $object->ibge = 35;
            $object->ddd = "11,12,13,14,15,16,17,18,19";
            $object->store();

            $object->nome = "Sergipe";
            $object->uf = "SE";
            $object->pais = 1;
            $object->ibge = 28;
            $object->ddd = "79";
            $object->store();

            $object->nome = "Tocantins";
            $object->uf = "TO";
            $object->pais = 1;
            $object->ibge = 17;
            $object->ddd = "63";
            $object->store();
        }
    }
}