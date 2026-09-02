<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Dialect;

use Diogodg\Neoorm\Migrations\Operation\OperationList;
use Diogodg\Neoorm\Migrations\Operation\SchemaOperation;
use Diogodg\Neoorm\Schema\SchemaDefinition;
use Diogodg\Neoorm\Schema\Type\TypeSpec;

/**
 * Traduz operações neutras para o SQL de um banco.
 *
 * Nenhum método recebe ou usa conexão. É deliberado: `migration:generate` tem que
 * funcionar sem banco nenhum no ar — em CI, num container de build, offline — e
 * é o que torna a suíte inteira de geração de SQL testável sem servidor. No
 * sistema antigo o SQL era montado dentro do driver, que já tinha um PDO no
 * construtor, e por isso não havia como olhar o DDL gerado sem aplicá-lo.
 *
 * Citação e escape passam por exatamente dois métodos, `quoteIdentifier()` e
 * `quoteLiteral()`. Todo identificador é sempre citado, sem exceção nem
 * heurística de palavra reservada — uma coluna chamada `order` ou `select`
 * simplesmente funciona.
 */
interface Dialect
{
    /** 'mysql' ou 'pgsql'. É a chave usada no snapshot e no nome do diretório. */
    public function name(): string;

    public function quoteIdentifier(string $identifier): string;

    public function quoteLiteral(string|int|float|bool|null $value): string;

    public function maxIdentifierLength(): int;

    /**
     * Se DDL participa de transação.
     *
     * PostgreSQL sim; MySQL não — cada statement de DDL dá commit implícito. O
     * runner usa isto para escolher entre "atômico" e "retomável", e a diferença
     * vai declarada na doc em vez de escondida.
     */
    public function supportsTransactionalDdl(): bool;

    /**
     * @return string|null null quando o tipo é suportado; a razão, quando não
     */
    public function checkType(TypeSpec $type): ?string;

    public function renderType(TypeSpec $type): string;

    /**
     * Statements de uma operação, sem `;` no fim.
     *
     * Uma operação pode virar mais de um statement: no PostgreSQL um
     * `CreateTable` com comentários vira o CREATE mais um `COMMENT ON` por
     * comentário, e um `AlterColumn` vira até cinco.
     *
     * @return list<string>
     */
    public function compile(SchemaOperation $operation): array;

    /**
     * @return list<string>
     */
    public function compileAll(OperationList $operations): array;

    /**
     * DDL da tabela de controle das migrações, idempotente.
     *
     * @return list<string>
     */
    public function migrationsTableDdl(string $table): array;

    /**
     * Statements que ajustam a SESSÃO antes de qualquer migração rodar.
     *
     * Migração é o único momento em que a biblioteca executa SQL que ela não montou —
     * um arquivo `.sql` do repositório, possivelmente editado à mão. Por isso a sessão
     * precisa estar num estado conhecido: se o servidor está configurado de um jeito e
     * o arquivo foi escrito supondo outro, o SQL muda de significado sem mudar de texto.
     *
     * @return list<string>
     */
    public function sessionSetup(): array;

    /**
     * Descarta do schema o que este banco não sabe guardar.
     *
     * Existe porque o IR é neutro e o snapshot não: há um snapshot por dialeto, em
     * diretórios separados, justamente porque os dois bancos não representam as mesmas
     * coisas. `engine` e `collation` de tabela só existem no MySQL, e um schema declarado
     * com `engine: 'InnoDB'` aplicado num PostgreSQL produziria, em toda execução, uma
     * operação de opções de tabela que compila para nenhum statement — uma migração que
     * afirma mudar algo, não muda nada, e nunca converge.
     *
     * Passar o schema declarado por aqui antes de virar snapshot é o que faz o `generate`
     * convergir nos dois bancos a partir dos mesmos models.
     */
    public function normalizeForStorage(SchemaDefinition $schema): SchemaDefinition;

    // ------------------------------------------------------------------- DML
    //
    // A partir daqui é consulta, não migração. O dialeto continua sendo folha:
    // recebe nome de placeholder já alocado, nunca um coletor de binds. Inverter
    // isso faria a camada de dialeto depender do compilador — e é o compilador
    // que tem o estado dos binds, não o contrário.

    /**
     * Cláusula de paginação, ou string vazia quando não há nem limite nem offset.
     *
     * A forma `LIMIT n OFFSET m` é a dos dois bancos. A do MySQL com vírgula
     * (`LIMIT offset, count`) fica de fora de propósito: é erro de sintaxe no
     * PostgreSQL e, além disso, inverte a ordem dos dois números em relação ao
     * que os nomes sugerem.
     */
    public function limitOffsetClause(?string $limitPlaceholder, ?string $offsetPlaceholder): string;

    /**
     * Se um INSERT sabe devolver a linha gravada em vez de só contá-la.
     *
     * Falso no MySQL, e é assimetria de banco, não de biblioteca: sem isto o
     * INSERT precisa de um segundo SELECT por `lastInsertId()` para hidratar o
     * retorno. Está declarado aqui para que quem chama possa decidir com o fato à
     * vista, em vez de descobrir por um N+1 invisível.
     */
    public function supportsReturning(): bool;

    /**
     * Se UPDATE e DELETE também sabem devolver as linhas afetadas.
     *
     * Separado de {@see supportsReturning()} porque não é a mesma capacidade: um
     * banco pode ter RETURNING no INSERT e não nos demais.
     */
    public function supportsReturningOnModify(): bool;

    /**
     * Comparação textual que ignora caixa.
     *
     * PostgreSQL tem `ILIKE`; o MySQL não tem nada equivalente e depende do
     * collation da coluna, que a biblioteca não controla. Rebaixar para
     * `LOWER() LIKE LOWER()` custa o índice, mas devolve o mesmo resultado nos
     * dois bancos — e resultado igual é o que a portabilidade tem que garantir.
     */
    public function caseInsensitiveLike(string $left, string $right): string;

    /**
     * Se o banco escreve `NULLS FIRST` / `NULLS LAST` no ORDER BY.
     *
     * Só o PostgreSQL. Onde não há, o compilador emula com uma chave de ordenação
     * extra — e precisa saber disso aqui, porque a emulação acrescenta um termo ao
     * ORDER BY e não é algo que o dialeto consiga fazer sozinho: ele receberia o
     * termo já compilado, sem poder inserir outro antes.
     */
    public function supportsNullsPlacement(): bool;

    /**
     * Statements que preparam a sessão de uma conexão de CONSULTA.
     *
     * Distinto de {@see sessionSetup()}, que é escopo de migração: aquele prepara a
     * sessão para aplicar DDL vinda de arquivo, este prepara a sessão em que a aplicação
     * lê e grava dados.
     *
     * O que mora aqui é o fuso. Para `TIMESTAMP` no MySQL o servidor converte na leitura
     * usando `@@session.time_zone`, então sem fixá-lo a MESMA linha volta diferente
     * conforme o fuso do container — e nenhum caster consegue compensar depois, porque a
     * conversão já aconteceu no servidor.
     *
     * @return list<string>
     */
    public function connectionSetup(): array;

    /**
     * Os três comandos de savepoint, que dão aninhamento de transação.
     *
     * O nome vem da profundidade, um inteiro, e não de string do chamador: assim
     * não existe caminho pelo qual texto arbitrário chegue a um comando que não
     * aceita parâmetro. Savepoint é uma das poucas construções em que o nome não
     * pode ser bind.
     */
    public function savepoint(int $depth): string;

    public function releaseSavepoint(int $depth): string;

    public function rollbackToSavepoint(int $depth): string;
}
