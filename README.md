# NeoORM

Data mapper para MySQL e PostgreSQL, com **consulta tipada** e migrações versionadas em
arquivo.

```php
use Diogodg\Neoorm\Query\Database;
use App\Models\Generated\Tables;

use function Diogodg\Neoorm\Query\{eq, gt, desc};

$db = Database::fromConfig();
$u  = Tables::users();

$maiores = $db->select()->from($u)
    ->where(gt($u->age, 18))
    ->orderBy(desc($u->created_at))
    ->limit(10)
    ->all();                       // list<UsersRow> — tipado de verdade
```

`$maiores[0]->created_at` é um `DateTimeImmutable`, `->active` é um `bool` e `->balance` é
uma string numérica, nos dois bancos. A IDE completa os nomes das colunas, e o PHPStan
reprova `$u->emial` antes de você rodar.

## Instalação

```bash
composer require diogodg/neoorm
```

`.env` na raiz do projeto:

```env
DRIVER=mysql            # ou pgsql
DBHOST=localhost
DBPORT=3306
DBNAME=db
DBCHARSET=utf8mb4
DBUSER=root
DBPASSWORD=

# Opcionais — todo caminho e namespace tem padrão; só declare o que fugir dele
PATH_MODEL=./App/Models                    # padrão: ./App/Models
MODEL_NAMESPACE=App\Models                 # padrão: App\Models
PATH_GENERATED=./App/Models/Generated      # padrão: {PATH_MODEL}/Generated
GENERATED_NAMESPACE=App\Models\Generated   # padrão: {MODEL_NAMESPACE}\Generated
PATH_SEEDS=./App/Seeders                   # padrão: ./App/Seeders
SEEDER_NAMESPACE=App\Seeders               # padrão: App\Seeders
PATH_MIGRATIONS=./Migrations               # padrão: ./Migrations
MIGRATIONS_TABLE=_neoorm_migrations
MIGRATIONS_STRICT=true
DBSCHEMA=public                            # só PostgreSQL
ENVIRONMENT=dev                            # `prod` bloqueia db:push e db:reset
```

Os caminhos são relativos **à raiz do projeto** — o diretório com o `composer.json` —,
não ao diretório de onde você chamou o CLI. Um caminho absoluto é usado como está, e uma
chave declarada vazia (`PATH_MODEL=`) vale como não declarada: cai no padrão.

O layout padrão, portanto, é este, e num projeto que o siga o `.env` não precisa de
nenhuma dessas chaves:

```
App/Models/            models          App\Models
App/Models/Generated/  código gerado   App\Models\Generated
App/Seeders/           seeders         App\Seeders
Migrations/            .sql, journal.json, meta/
```

## O modelo mental

Quatro coisas, e cada uma faz uma:

| | O quê | Quem escreve |
|---|---|---|
| **Model** | descreve a tabela | você |
| **Seeder** | popula a tabela | você |
| **`Generated/`** | linhas, payloads de insert e referências de coluna, tipados | `neoorm generate:types` |
| **`Database`** | monta e executa a consulta | você usa |

Um model **não consulta nada**. Ele não tem `get()`, não tem `store()`, não guarda linha.
Na 1.x tinha: `Model` estendia `Db`, que era ao mesmo tempo conexão, construtor de query e
linha de resultado — e era por isso que não existia tipo de retorno possível além de
`array`. Ver [UPGRADE.md](UPGRADE.md).

## Definindo uma tabela

`App/Models/State.php`:

```php
<?php

namespace App\Models;

use App\Models\Generated\StateTable;
use Diogodg\Neoorm\Abstract\Model;
use Diogodg\Neoorm\Migrations\Col;
use Diogodg\Neoorm\Migrations\Table;

/**
 * @extends Model<StateTable>
 */
class State extends Model
{
    public const table = 'state';

    public static function table(): Table
    {
        return Table::make(self::table, comment: 'States table')
            ->columns([
                'id'           => Col::id(),
                'name'         => Col::varchar(120)->notNull(),
                'abbreviation' => Col::char(2)->notNull(),
                'country'      => Col::int()->notNull()->references(Country::class),
                'ibge'         => Col::int()->unique(),
            ]);
    }
}
```

As colunas são um **mapa `nome => Col`**: o nome é a chave, e o tipo é uma fábrica. Escrever
o nome duas vezes — uma na chave e outra num construtor — seria a única forma de os dois
discordarem, e o tipo como string livre (`'VARCAHR'`) só quebrava no `build()`.

Descrever a tabela é operação de **memória**: `table()` não abre conexão e não roda DDL.
(Na 1.x rodava — cinco `CREATE TABLE IF NOT EXISTS` por instanciação de model, uma vez por
linha hidratada.)

### As colunas

`Col::id()` é `INT` chave primária com auto incremento — a primeira linha de quase todo
model. `Col::bigId()` é o mesmo em `BIGINT`, e `Col::fk(Outro::class)` é
`INT NOT NULL` já referenciando.

Uma fábrica por tipo: `tinyInt() smallInt() mediumInt() int() bigInt() decimal($p, $s)
float() double() boolean() char($n) varchar($n) tinyText() text() mediumText() longText()
date() time() dateTime() timestamp() timestampTz() year() json() jsonb() uuid()
binary($n) varBinary($n) tinyBlob() blob() mediumBlob() longBlob() bytea() enum($valores)`.
Para o que elas não cobrirem, `Col::type('INT UNSIGNED')` aceita o tipo como texto.

Os modificadores encadeiam: `notNull() nullable() primary() unique() autoIncrement()
unsigned() index() default($v) defaultRaw($sql) defaultNull() comment($t) collation($c)
references(Outro::class)`.

`Col` é **imutável**, então uma coluna serve de molde sem risco de alias:

```php
$dinheiro = Col::decimal(19, 4)->notNull()->default(0);

'saldo'  => $dinheiro,
'limite' => $dinheiro->comment('Limite de crédito'),   // não mexe em $dinheiro
```

`references()` aceita a **classe** do model, e não só o nome da tabela: `Country::class` é
verificável pela IDE e pelo PHPStan, `'country'` não é.

### O que fica na tabela

O que não cabe numa coluna só:

```php
Table::make('produto_categoria')
    ->columns([
        'produto'   => Col::int()->notNull(),
        'categoria' => Col::int()->notNull(),
        'nota'      => Col::decimal(3, 1),
    ])
    ->primary(['produto', 'categoria'])              // chave composta
    ->unique('pc_nota_unique', ['produto', 'nota'])  // unicidade multi-coluna
    ->index('pc_nota_index', ['nota'])
    ->check('nota >= 0')
    ->foreignKey('outra', ['a', 'b'], ['x', 'y'])    // foreign key composta
    ->timestamps();                                  // created_at + updated_at
```

Índice unico se declara com `unique()`, nunca como "índice único": no MySQL os dois são o
mesmo objeto de catálogo, então ter duas representações significaria que uma delas
divergiria do banco para sempre.

A **ordem das chamadas não importa** — toda validação acontece no `build()`, sobre o schema
pronto.

### Models em subpastas

`PATH_MODEL` é varrido recursivamente, com subpasta virando segmento de namespace pela
regra do PSR-4:

```
App/Models/
  Country.php                 App\Models\Country
  Billing/Invoice.php         App\Models\Billing\Invoice
  Generated/                  pulado
```

## Populando as tabelas

O dado inicial mora em `PATH_SEEDS`, um arquivo por tabela — não dentro do model. Schema e
dado mudam por motivos diferentes e num ritmo diferente.

`App/Seeders/StateSeeder.php`:

```php
<?php

namespace App\Seeders;

use App\Models\Generated\Inserts\StateInsert;
use App\Models\Generated\Tables;
use App\Models\State;
use Diogodg\Neoorm\Migrations\Seed\Seeder;
use Diogodg\Neoorm\Query\Executor;

final class StateSeeder extends Seeder
{
    public static function table(): string
    {
        return State::table;
    }

    public function run(Executor $db): void
    {
        if ($db->select()->from(Tables::state())->count() > 0) {
            return;
        }

        $db->insert(Tables::state())
            ->values(new StateInsert(name: 'Acre', abbreviation: 'AC', country: 1, ibge: 12))
            ->execute();
    }
}
```

```bash
vendor/bin/neoorm db:seed                    # todos, em ordem de dependência
vendor/bin/neoorm db:seed --tables=state     # só estes
```

O executor chega por **parâmetro**, e é o `Tx` da transação que o comando abriu: se um
seeder falhar no meio, nenhum dado parcial fica. Cada seeder deve ser idempotente — o
runner não guarda o que já rodou, e a guarda usual é conferir se a tabela já tem linha.

A **ordem vem do grafo de foreign keys**, não do nome do arquivo: o seeder de `state` roda
depois do de `country` porque a tabela depende dela.

Uma tabela, um seeder. Duas classes para a mesma tabela, ou um `table()` que não existe no
schema, param o comando com a lista completa — o segundo era silêncio puro antes: o seeder
simplesmente nunca rodava.

## Consultando a partir do model

```php
$s = State::ref();                              // StateTable, colunas tipadas
$db->select()->from($s)->where(eq($s->ibge, 12))->one();

$vizinho = State::ref('vizinho');               // com alias, para self-join
```

O tipo concreto vem do `@extends Model<StateTable>` da classe. É anotação e não `use` de
propósito: um `use` de algo em `Generated/` faria o model fatalar quando o diretório não
existisse — e `generate:types` precisa **ler** os models para criá-lo. Sem a anotação
`ref()` continua funcionando, só devolve `Query\Table`; com a anotação errada,
`generate:types --check` reprova.

## Gerando os tipos

```bash
vendor/bin/neoorm generate:types
```

Lê `PATH_MODEL` e escreve em `PATH_GENERATED`:

```
App/Models/Generated/
  Tables.php              Tables::users(), Tables::scheduleUser(), ...
  UsersTable.php          $u->email — propriedade declarada, com tipo
  Rows/UsersRow.php       final readonly, com fromRow() e toArray()
  Inserts/UsersInsert.php construtor de argumentos nomeados
  Enums/UsersStatus.php   enum PHP para coluna ENUM
```

**Roda sem banco** — só lê o diretório de models. É o que o torna viável como hook de
pre-commit e como gate de CI.

**Commite o diretório.** Ele é código-fonte como qualquer outro: entra em code review,
e o gate abaixo garante que não envelhece.

```bash
vendor/bin/neoorm generate:types --check   # sai 1 se o gerado divergir dos models
```

O `--check` também confere o `@extends Model<XTable>` de cada model. A anotação é o que dá
tipo concreto a `Model::ref()`, e é um comentário: nada em tempo de execução a contradiz,
então copiar um model e esquecer de trocar a classe faz a IDE e o PHPStan concordarem com
colunas que não existem. Model sem anotação não é erro; com a anotação errada, é.

As propriedades têm o nome **idêntico à coluna**, em `snake_case`. Camelizar não é
injetivo — o IR já minusculou tudo, então `user_id` e `userId` seriam a mesma coluna, e
`is_active` colidiria com `isactive`.

## Consultando

### Select

```php
$db = Database::fromConfig();
$u = Tables::users();

$db->select()->from($u)->all();                     // list<UsersRow>
$db->select()->from($u)->where(eq($u->id, 1))->one();      // ?UsersRow
$db->select()->from($u)->where(eq($u->id, 1))->oneOrFail();// UsersRow, ou lança
$db->select()->from($u)->count();                   // int
$db->select()->from($u)->cursor();                  // Generator<UsersRow>, sem materializar
```

### Condições

```php
use Diogodg\Neoorm\Query\Op;
use function Diogodg\Neoorm\Query\{eq, ne, gt, gte, lt, lte, like, notLike, ilike,
    inArray, notInArray, between, notBetween, isNull, isNotNull, asc, desc, sql};

$db->select()->from($u)
    ->where(Op::and(
        gte($u->age, 18),
        Op::or(ilike($u->name, 'd%'), inArray($u->status, ['active', 'trial'])),
        isNotNull($u->email),
    ))
    ->all();
```

`ilike()` é operador no PostgreSQL e vira `LOWER(a) LIKE LOWER(b)` no MySQL — o dialeto
decide, você não.

`sql()` é a via de escape, e o contrato é explícito: o fragmento vai cru, os **valores
continuam virando bind**.

```php
->where(sql('LENGTH(name) > ?', 10))
```

### Paginação, ordenação, agrupamento

```php
use Diogodg\Neoorm\Query\Func;
use Diogodg\Neoorm\Query\Expr\{Aliased, NullsPlacement};

$pagina = $db->select()->from($u)
    ->orderBy(desc($u->created_at, NullsPlacement::Last), asc($u->name))
    ->limit(20)->offset(40)
    ->all();

$porStatus = $db->selectFields([$u->status, new Aliased(Func::count(), 'total')])
    ->from($u)
    ->groupBy($u->status)
    ->having(gt(Func::count(), 5))
    ->all();                                  // list<array<string,mixed>>
```

`NULLS FIRST/LAST` só existe no PostgreSQL; no MySQL o compilador emite
`expr IS NULL DESC` antes do termo, que dá o mesmo resultado.

### Joins e seleção parcial

```php
$p = Tables::posts();

$linhas = $db->selectFields([$u->id, $u->name, $p->title])
    ->from($u)
    ->leftJoin($p, eq($p->author_id, $u->id))
    ->all();

$linhas[0]['id'];            // da tabela base
$linhas[0]['posts__title'];  // da tabela juntada
```

Seleção parcial devolve `array<string,mixed>`, e isso está **dito no tipo** em vez de
escondido: o PHP não tem tipo estrutural, então não há como declarar "array com estas
chaves e estes tipos" que o runtime verifique.

As colunas da tabela juntada saem com prefixo `{tabela}__{coluna}` porque `FETCH_ASSOC`
colapsa homônimas: `users.id` e `posts.id` escreveriam a mesma chave, e a última venceria,
em silêncio.

Uma tabela apelidada requalifica as próprias colunas, o que é o que faz self-join
funcionar:

```php
$vizinho = Tables::state('vizinho');
$db->selectFields([$s->id, $vizinho->id])
   ->from($s)->innerJoin($vizinho, eq($vizinho->country, $s->country))->all();
```

### Insert, update, delete

```php
$db->insert($u)->values(new UsersInsert(name: 'Diogo', email: 'd@x.com'))->execute();

// RETURNING no PostgreSQL; INSERT + lastInsertId() + SELECT no MySQL.
$novo = $db->insert($u)->values(new UsersInsert(name: 'Ana', email: 'a@x.com'))->returningOne();

$db->update($u)->set(['phone' => '...'])->where(eq($u->id, 1))->execute();
$db->delete($u)->where(eq($u->id, 1))->execute();
```

No `UsersInsert`, coluna com DEFAULT ou auto incremento é opcional, e deixá-la em `null`
**omite a coluna** do INSERT para o banco aplicar o próprio padrão.

Em lote, os payloads podem omitir opcionais diferentes: a união das colunas é feita uma
vez e cada ausência vira `DEFAULT` na linha correspondente.

`execute()` de UPDATE devolve linhas **casadas** nos dois bancos, inclusive quando o
valor novo é igual ao antigo. A conexão MySQL liga `MYSQL_ATTR_FOUND_ROWS` para manter o
mesmo contrato do PostgreSQL.

**UPDATE e DELETE sem WHERE são recusados**, a menos que você peça:

```php
$db->update($u)->set(['ativo' => false])->allowFullTableScan()->execute();
```

O `deleteByFilter()` da 1.x já protegia o DELETE; o UPDATE nunca protegeu, e um `where()`
esquecido reescrevia a tabela inteira sem aviso.

### O builder é imutável

```php
$ativos = $db->select()->from($u)->where(eq($u->status, 'active'));

$pagina = $ativos->limit(10)->all();
$total  = $ativos->count();          // $ativos intacto
```

`count()` ignora paginação e ordenação. Com `GROUP BY`, `HAVING` ou `DISTINCT`, conta as
linhas da consulta resultante por meio de subconsulta — não o tamanho do primeiro grupo.
`limit()` e `offset()` recusam valores negativos.

Cada cláusula devolve outra instância. É o que elimina o `clean()` da 1.x, que existia
justamente porque o builder era mutável e reusado — "filtro sobrando da consulta anterior"
era a classe de bug resultante.

### Transações

```php
use Diogodg\Neoorm\Query\Tx;

$db->transaction(function (Tx $tx) use ($u, $p) {
    $autor = $tx->insert($u)->values(new UsersInsert(name: 'Ana', email: 'a@x.com'))->returningOne();

    $tx->insert($p)->values(new PostsInsert(author_id: $autor->id, title: 'Olá'))->execute();
});
```

Aninham de verdade, por savepoint: a falha interna desfaz até o savepoint e a externa
commita. Na 1.x `Connection::beginTransaction()` era no-op se já houvesse transação e
`commit()` era no-op se não houvesse — uma transação interna não commitava nada, e uma
falha interna desfazia o trabalho externo sem avisar.

O estado dos savepoints pertence ao handle PDO: dois objetos `Database` sobre a mesma
conexão compartilham a profundidade. Os métodos transacionais estáticos de `Connection`
não existem na v2.

`Tx` implementa a mesma interface `Executor` que `Database`, então um repositório pode
type-hintar `Executor` e funcionar dos dois jeitos.

### Vendo o SQL

```php
$query = $db->select()->from($u)->where(eq($u->id, 1))->toSql();

$query->sql;       // SELECT "users"."id", ... FROM "users" WHERE "users"."id" = :p0
$query->values();  // ['p0' => 1]
```

## Tipos das colunas

| Coluna | PHP | Por quê |
|---|---|---|
| inteiros | `int` | |
| `FLOAT`, `DOUBLE` | `float` | |
| `DECIMAL` | `string` (`numeric-string`) | é o que o PDO devolve, e `DECIMAL(19,4)` de dinheiro excede a precisão de float |
| `BOOLEAN` | `bool` | mysqlnd devolve `int`, pdo_pgsql devolve `bool` — o caster resolve |
| textuais | `string` | `CHAR` sofre `rtrim` para os dois dialetos concordarem |
| `DATE`, `DATETIME`, `TIMESTAMP` | `DateTimeImmutable` | fuso normalizado para UTC na leitura |
| `TIME` | `string` | é **duração** no MySQL (±838h); `DateTimeImmutable` corromperia |
| `JSON`, `JSONB` | `mixed` decodificado | `array` seria mentira: `'42'` e `'null'` são JSON válidos |
| `UUID` | `string` | |
| binários | `string` | `bytea` chega como stream no pdo_pgsql — o caster drena |
| `ENUM` | enum PHP gerado | |

O fuso da sessão é fixado na conexão (`SET time_zone = '+00:00'` / `SET TIME ZONE 'UTC'`).
`DATETIME`, `TIMESTAMP` e `TIMESTAMPTZ` são convertidos para UTC tanto na escrita quanto
na leitura. `DATE` e `TIME` preservam o valor de calendário, sem conversão de fuso.

Use `isNull($coluna)` e `isNotNull($coluna)` para nulidade. `eq($coluna, null)` e
`ne($coluna, null)` lançam cedo, porque `= NULL`/`<> NULL` nunca são verdadeiros em SQL.

## Migrações

Mudança de schema é **arquivo versionado no repositório**: seus models são comparados com o
último snapshot, e a diferença sai como um `.sql` numerado que dá para ler no code review
antes de tocar em ambiente nenhum.

```bash
vendor/bin/neoorm migration:generate --name=add_slug   # escreve o .sql + snapshot + journal
vendor/bin/neoorm migration:up                         # aplica o pendente
vendor/bin/neoorm db:check                             # sai 0 só se models, snapshot e banco concordarem
```

`migration:generate` **não abre conexão**. Ele compara dois arquivos JSON e escreve SQL, o
que é o que permite gerar e revisar uma migração em CI sem serviço de banco no ar.

### O que vai para o repositório

```
Migrations/
  pgsql/
    0000_initial.sql          <- commitado
    0001_add_slug.sql         <- commitado
    journal.json              <- commitado
    meta/0000_snapshot.json   <- commitado
  mysql/  (mesmo layout)
```

Tudo é código-fonte. O snapshot é a única entrada do differ — fora do repositório, dois
desenvolvedores geram cada um um `0002` com conteúdos diferentes e o mesmo número.

Um diretório **por dialeto** porque um `.sql` é inerentemente específico, e porque engine e
collation só existem no MySQL.

### Os comandos

| Comando | O que toca |
|---|---|
| `generate:types [--check] [--dry-run]` | arquivos gerados. Sem banco |
| `migration:generate [--name=x] [--empty] [--rename=a:b] [--allow-destructive] [--dry-run]` | arquivos do repositório. Sem banco |
| `migration:up [--to=tag] [--step=n] [--dry-run]` | o banco |
| `migration:status` | lê os dois. Sai 1 se houver inconsistência |
| `db:check [--strict]` | lê tudo. **O gate de CI** |
| `db:push [--dry-run] [--allow-destructive]` | o banco, sem arquivo. Só desenvolvimento |
| `db:pull [--write-to=x] [--only-declared]` | lê o banco para um snapshot |
| `db:seed [--tables=a,b]` | o banco, só dados. Roda os seeders de `PATH_SEEDS` |
| `db:reset [--force] [--seed] [--confirm=nome]` | derruba e recria. Só desenvolvimento |

Dentro do NeoFramework os mesmos nomes valem em `php neof`.

### Não há migração `down`

De propósito. Um `down` automático sugere uma reversibilidade que não existe: um
`DROP COLUMN` desfeito recria a coluna **vazia**, e o dado não volta. Em desenvolvimento se
usa `db:reset`; em produção se escreve uma migração corretiva para frente.

### A atomicidade é assimétrica, e isso não é escondido

- **PostgreSQL** tem DDL transacional. Uma migração é *atômica*: ela e o registro dela
  commitam juntos, ou nada acontece.
- **MySQL** commita implicitamente a cada statement de DDL, então transação não é possível.
  Em vez de fingir, o runner grava `applied_index` depois de cada statement e a migração
  fica **retomável**: rodar de novo continua exatamente de onde parou, e o erro diz qual
  statement falhou.

### `db:push` vs `migration:generate`

`push` compara os models com o **banco vivo** e aplica a diferença direto, sem escrever
arquivo. É para o ciclo de desenvolvimento em que você muda um model dez vezes por hora e
não quer dez migrações no histórico. Recusa rodar em produção — não porque o SQL seria
diferente (há teste afirmando que não é), mas porque não deixa rastro: nada para revisar, e
nenhuma forma de reproduzir o estado resultante em outro lugar.

## Desenvolvimento

```bash
docker compose up -d
docker compose exec php composer test         # unit + integração nos dois dialetos
docker compose exec php composer test:unit    # sem banco, poucos segundos
docker compose exec php composer test:types   # PHPStan nível 8
```
