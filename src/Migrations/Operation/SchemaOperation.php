<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Migrations\Operation;

/**
 * Uma mudança de schema, ainda sem dialeto.
 *
 * O conjunto de implementações é fechado: 20 classes, nem uma a mais. É o
 * vocabulário inteiro que o differ pode falar e que os dialetos precisam saber
 * traduzir, e é o que permite ao teste "todo dialeto compila toda operação" ser
 * uma prova de cobertura e não uma amostragem.
 *
 * Nenhuma operação carrega SQL. O sistema antigo montava a string de DDL no
 * construtor do builder, o que tornava impossível gerar dois dialetos a partir
 * da mesma intenção — e foi por isso que o PostgreSQL perdia comentário e
 * tamanho de coluna: o SQL já vinha cozido em MySQL e o driver pgsql descartava
 * os pedaços que não reconhecia.
 */
interface SchemaOperation
{
    /**
     * A tabela sobre a qual a operação age.
     *
     * Em renomeações é o nome de ORIGEM: é por ele que o `OperationSorter`
     * encontra as foreign keys que precisam sair da frente antes do rename.
     */
    public function tableName(): string;

    /**
     * Pode destruir dados existentes, ou falhar numa tabela populada.
     *
     * Não é uma previsão — é uma classificação estática. Saber se um
     * `ALTER COLUMN` vai truncar de fato exigiria olhar as linhas; a operação
     * declara o risco e quem decide é quem roda o comando.
     *
     * O conjunto é largo de propósito: `ADD UNIQUE` numa tabela com duplicatas
     * falha, e `ADD FOREIGN KEY` com linha órfã também. São coisas que valem um
     * aviso a quem revisa a migração.
     */
    public function isDestructive(): bool;

    /**
     * DESCARTA dados irrecuperavelmente.
     *
     * Distinto de `isDestructive()`, e a distinção importa porque as duas coisas
     * pedem respostas diferentes. Uma operação arriscada FALHA quando os dados
     * não a comportam — o banco recusa, nada se perde, e o aviso serve para quem
     * revisa. Uma operação que descarta dados SUCEDE e apaga: sem migração
     * `down`, o que ela levou só volta de um backup.
     *
     * É por isso que só o segundo grupo exige confirmação explícita. Bloquear o
     * primeiro obrigaria `--allow-destructive` em toda migração que criasse uma
     * tabela com uma restrição de unicidade — ou seja, na primeira migração de
     * qualquer projeto —, e uma confirmação que se dá sempre é uma confirmação
     * que ninguém lê.
     */
    public function discardsData(): bool;

    /**
     * Uma linha, em português, para log e dry-run.
     *
     * É também o contrato observável do differ: `DriftPairs` afirma pares
     * (schema anterior, schema novo) contra a lista de `describe()` esperada, o
     * que torna o comportamento do differ legível como texto em vez de como
     * árvore de objetos.
     */
    public function describe(): string;
}
