# Atualizando para o NeoORM 2.0

A 2.0 **remove a camada de consulta antiga inteira** e a substitui por um data mapper com
retorno tipado. `Db`, as cinco traits `Db*`, `Definitions\Raw` como filtro e os enums de
operador não existem mais; `Abstract\Model` virou só a declaração da tabela.

Também reescreve o sistema de migrações do zero (seção 3) e endurece a conexão (seção 4).

Se você só tem meia hora: leia as seções 1, 2 e 3.

O **DSL dos models** também mudou: as colunas viraram um mapa `nome => Col`, e os dados
iniciais saíram do model para arquivos de seeder próprios. Ver a seção 2.

---

## 1. A camada de consulta é outra

### Por que não deu para preservar

`Db` era, ao mesmo tempo, a conexão, o construtor de query e a linha de resultado. Os
filtros viviam em `$this->filters`, no mesmo objeto que carregava os dados da linha, e
`clean()` apagava tudo depois de cada execução. Como `selectInstruction()` hidratava com
`PDO::FETCH_CLASS` de `get_class($this)`, **cada linha reconstruía um `Db` inteiro** — e
não existia tipo de retorno possível além de `array`.

Não era um problema de sintaxe. Era o desenho.

### O mapa

| 1.x | 2.0 |
|---|---|
| `(new Employee)->get($id)` | `$db->select()->from($e)->where(eq($e->id, $id))->one()` |
| `(new Employee)->get($nome, 'name')` | `$db->select()->from($e)->where(eq($e->name, $nome))->one()` |
| `->getAll()` | `$db->select()->from($e)->all()` |
| `->addFilter('age', '>=', 18)` | `->where(gte($e->age, 18))` |
| `->addFilter('id', 'IN', [1,2])` | `->where(inArray($e->id, [1, 2]))` |
| `->addFilterNull('deleted_at')` | `->where(isNull($e->deleted_at))` |
| `->addJoin('LEFT', 'user', 'user.id', 'e.user_id')` | `->leftJoin($u, eq($u->id, $e->user_id))` |
| `->selectColumns('a', ['b','alias'])` | `$db->selectFields([$e->a, new Aliased($e->b, 'alias')])` |
| `->addOrder('name')` | `->orderBy(asc($e->name))` |
| `->addLimit(10)->addOffset(20)` | `->limit(10)->offset(20)` |
| `->addGroup('status')` / `->addHaving(...)` | `->groupBy($e->status)` / `->having(...)` |
| `->count()` | `->count()` |
| `$e->name = 'x'; $e->store()` | `$db->insert($e)->values(new EmployeeInsert(name: 'x'))->execute()` |
| `$e->store()` com id preenchido | `$db->update($e)->set([...])->where(eq($e->id, $id))->execute()` |
| `->delete($id)` | `$db->delete($e)->where(eq($e->id, $id))->execute()` |
| `->deleteByFilter()` | `$db->delete($e)->where(...)->execute()` |
| `->paginate($p, $n)` | `->limit($n)->offset(($p - 1) * $n)`, e `->count()` para o total |
| `new Raw('COUNT(*) as total')` | `new Aliased(Func::count(), 'total')` |
| `new Raw($sqlQualquer)` | `sql('fragmento com ?', $valor)` |
| `Connection::beginTransaction()` | `$db->transaction(fn (Tx $tx) => ...)` |

Antes de tudo isso, uma vez:

```bash
vendor/bin/neoorm generate:types
```

E no código:

```php
use Diogodg\Neoorm\Query\Database;
use App\Models\Generated\Tables;
use App\Models\Generated\Inserts\EmployeeInsert;

use function Diogodg\Neoorm\Query\{eq, gte, inArray, isNull, asc};

$db = Database::fromConfig();
$e = Tables::employee();
```

### O que ficou melhor de propósito, e você vai notar

**O builder é imutável.** `$q->where(...)` devolve outro builder; `$q` fica intacto. Some
o `clean()`, e com ele a classe de bug "filtro sobrando da consulta anterior".

**UPDATE e DELETE sem WHERE são recusados.** `deleteByFilter()` já protegia o DELETE; o
UPDATE nunca protegeu. Para o caso legítimo há `->allowFullTableScan()`.

**Transações aninham de verdade**, por savepoint. Na 1.x `Connection::beginTransaction()`
era no-op se já houvesse transação e `commit()` era no-op se não houvesse: uma transação
interna não commitava nada, e uma falha interna desfazia o trabalho externo sem avisar.
Os métodos transacionais estáticos de `Connection` foram removidos; use
`Database::transaction()`.

**Nulidade é explícita.** `eq($coluna, null)` e `ne($coluna, null)` agora lançam com a
indicação de `isNull()`/`isNotNull()`. Antes geravam `= NULL`/`<> NULL`, condições que
nunca são verdadeiras em SQL.

**Contagens agrupadas e distintas foram corrigidas.** `count()` sobre `GROUP BY`,
`HAVING` ou `DISTINCT` envolve a consulta e conta seu resultado. Paginação e ordenação
continuam ignoradas. `limit()` e `offset()` negativos agora são recusados.

**UPDATE conta linhas casadas nos dois dialetos.** No MySQL a conexão passa a usar
`MYSQL_ATTR_FOUND_ROWS`; gravar novamente o mesmo valor devolve `1`, como no PostgreSQL,
e não mais `0`.

**INSERT em lote aceita payloads heterogêneos.** Opcionais omitidos em apenas algumas
linhas são emitidos como `DEFAULT`, sem exigir separar o lote.

**`Model::table()` não abre mais conexão.** Ele rodava cinco `CREATE TABLE IF NOT EXISTS`
por instanciação de model — uma vez por linha hidratada, com commit implícito no MySQL
matando qualquer transação em curso.

### Injeção de SQL: a defesa passou a ser estrutural

A 1.x recebia o **operador como string** (`addFilter($col, $op, $valor)`) e se defendia com
uma allowlist que precisava estar certa em quatro métodos. Na 2.0 o operador é enum e não
existe assinatura pública que aceite operador como texto — "operador fora da allowlist"
deixou de ser um estado possível. Identificadores (tabela, coluna, alias) passam pelo
`IdentifierValidator` no construtor do nó; valores viram bind, sempre, sem ramo.

`sql()` é a única via de escape, e o contrato está explícito: o fragmento vai cru, os
**valores continuam virando bind** — o que o `Raw` da 1.x não permitia, já que ele não
aceitava bind nenhum e quem precisava de valor ali só tinha a concatenação.

## 2. `Abstract\Model` só descreve a tabela

```php
use App\Models\Generated\StateTable;

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
                'id'      => Col::id(),
                'name'    => Col::varchar(120)->notNull(),
                'country' => Col::int()->notNull()->references(Country::class),
                'ibge'    => Col::int()->unique(),
            ]);
    }
}
```

O que sair do seu model:

- **`__construct()`** — `parent::__construct(self::table, self::class)` não existe mais.
  Um model nunca é instanciado pela biblioteca.
- **`get()`, `getAll()`, `store()`, `remove()`, `paginate()`, `getLastPage()`** e o resto
  herdado de `Db`. Ver o mapa da seção 1.
- **`seed()`** — os dados iniciais viraram arquivo próprio. Ver 2.2.

### 2.1 As colunas viraram um mapa `nome => Col`

`new Column('name', 'VARCHAR', 120)` deixou de existir. O nome da coluna passou a ser a
chave do mapa passado a `Table::columns()`, e o tipo passou a ser uma fábrica em vez de uma
string livre — `'VARCAHR'` era um erro que só aparecia no `build()`.

```php
// antes
return (new Table(self::table, comment: 'States table'))->isAutoIncrement()
    ->addColumn((new Column('id', 'INT'))->isPrimary())
    ->addColumn((new Column('name', 'VARCHAR', 120))->isNotNull())
    ->addColumn((new Column('country', 'INT'))->isNotNull())
    ->addForeignKey(Country::table, column: 'country')
    ->addColumn((new Column('ibge', 'INT'))->isUnique());

// agora
return Table::make(self::table, comment: 'States table')
    ->columns([
        'id'      => Col::id(),
        'name'    => Col::varchar(120)->notNull(),
        'country' => Col::int()->notNull()->references(Country::class),
        'ibge'    => Col::int()->unique(),
    ]);
```

O de/para, método a método:

| Antes | Agora |
|---|---|
| `new Table('t', comment: 'x')` | `Table::make('t', comment: 'x')` |
| `->isAutoIncrement()` na tabela | `Col::id()` na coluna da chave primária |
| `->addColumn((new Column('c','INT')))` | `->columns(['c' => Col::int()])` |
| `new Column('c', 'VARCHAR', 120)` | `Col::varchar(120)` |
| `new Column('c', 'DECIMAL', '10,2')` | `Col::decimal(10, 2)` |
| `new Column('c', 'INT UNSIGNED')` | `Col::int()->unsigned()` |
| `->isNotNull()` | `->notNull()` |
| `->isPrimary()` | `->primary()` |
| `->isUnique()` | `->unique()` |
| `->isAutoIncrement()` na coluna | `->autoIncrement()` |
| `->setDefault($v)` | `->default($v)` |
| `->setDefault($sql, true)` | `->defaultRaw($sql)` |
| `->setDefaultNull()` | `->defaultNull()` |
| `->setComment('x')` | `->comment('x')` |
| `->setCollation('x')` | `->collation('x')` |
| `->addForeignKey(Outro::table, column: 'c')` | `Col::int()->references(Outro::class)` na coluna `c` |
| `->addForeignKey('t', column: ['a','b'], foreignColumn: ['x','y'])` | `->foreignKey('t', ['a','b'], ['x','y'])` |
| `->addIndex('nome', ['a'])` | `->index('nome', ['a'])` — ou `Col::...->index()` |
| `->addIndex('nome', ['a'], unique: true)` | `->unique('nome', ['a'])` |
| `->addConstraint('nome', 'UNIQUE', ['a'])` | `->unique('nome', ['a'])` |
| `->addConstraint('nome', 'PRIMARY KEY', ['a','b'])` | `->primary(['a','b'])` |
| `->addCheck($expr, $nome)` | `->check($expr, $nome)` |

Cada método antigo continua existindo como **lápide**: chamá-lo lança `LogicException` com
a linha equivalente já montada, em vez de "undefined method".

Ganhos que vêm junto:

- `Col` é **imutável**, então uma coluna serve de molde:
  `$dinheiro = Col::decimal(19,4)->notNull();` usada em várias colunas sem risco de alias.
- `references()` aceita a **classe** do model (`Country::class`), verificável pela IDE e
  pelo PHPStan — `Country::table` era só uma string.
- `Table::timestamps()` adiciona `created_at`/`updated_at`.
- Um nome de coluna repetido é recusado com o nome dentro da mensagem.

### 2.2 `Model::seed()` virou um arquivo de seeder

Schema e dado mudam por motivos diferentes e num ritmo diferente, e mantê-los no mesmo
arquivo fazia o model de uma tabela de domínio grande ser majoritariamente dado.

```php
// antes: dentro de App/Models/State.php
public static function seed(): void
{
    $db = Database::fromConfig();
    ...
}

// agora: App/Seeders/StateSeeder.php
namespace App\Seeders;

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

O que muda na prática:

- **O executor chega por parâmetro.** Antes cada `seed()` chamava `Database::fromConfig()`
  por conta própria e só *por acidente* compartilhava o PDO da transação que o runner tinha
  aberto — bastava passar um `DatabaseConfig` para a escrita cair fora dela e sobreviver a
  um rollback. Agora `run()` recebe o `Tx`.
- **A ordem continua vindo do grafo de foreign keys**, não do nome do arquivo.
- Um model que ainda declare `seed()` faz `db:seed` **parar**, listando o arquivo a criar.
  Um método removido de classe-base falharia em silêncio, que é o pior modo possível.
- Duas classes para a mesma tabela, ou um `table()` que não existe no schema, são recusados
  com a lista completa.

Duas chaves novas no `.env`, ambas com default:

```env
PATH_SEEDS=./App/Seeders
SEEDER_NAMESPACE=App\Seeders
```

`Diogodg\Neoorm\Migrations\Seed\Seedable` foi **removida** — nada a referenciava.

### 2.3 Models podem morar em subpastas

`PATH_MODEL` passou a ser varrido recursivamente, com subpasta virando segmento de
namespace pela regra do PSR-4: `App/Models/Billing/Invoice.php` é `App\Models\Billing\Invoice`.
Antes a varredura era plana, e o efeito não era erro e sim silêncio — o model não existia
para o schema, então a migração seguinte propunha dropar a tabela dele. O diretório
`Generated/` é pulado.

### 2.4 `Model::ref()`: a ponte para o código gerado

```php
$s = State::ref();                       // StateTable, colunas tipadas
$db->select()->from($s)->where(eq($s->ibge, 12))->one();

$vizinho = State::ref('vizinho');        // com alias, para self-join
```

O tipo concreto vem do `@extends Model<StateTable>` anotado na classe. É anotação e não
`use` de propósito: um `use` de algo em `Generated/` faria o model fatalar quando o
diretório não existisse, e `generate:types` precisa **ler** os models para criá-lo. Sem a
anotação `ref()` ainda funciona, só devolve `Query\Table`; com a anotação **errada**,
`generate:types --check` reprova.

`Tables::state()` continua existindo — `ref()` é aditivo.

Classes removidas, caso você importasse alguma:

```
Diogodg\Neoorm\Db
Diogodg\Neoorm\Traits\{DbCrud,DbFilters,DbHelpers,DbProperties,DbSelect}
Diogodg\Neoorm\Enums\{LogicalOperator,OperatorCondition,OrderCondition}
Diogodg\Neoorm\Migrations\GeneretePhpDoc      -> neoorm generate:types
Diogodg\Neoorm\Codegen\PhpDocGenerator        -> neoorm generate:types
Diogodg\Neoorm\Migrations\Interface\{Table,Column}
Diogodg\Neoorm\Migrations\Driver\*            -> Dialect\*
Diogodg\Neoorm\Migrations\Dialect\*           -> Dialect\*  (promovido)
Diogodg\Neoorm\Migrations\Seed\Seedable       -> Seed\Seeder
Diogodg\Neoorm\Migrations\Column               -> Migrations\Col
Diogodg\Neoorm\Migrations\Migrate              -> comandos migration:* / db:*
```

`Definitions\Raw` **continua**, no papel que sempre foi o dele: default em expressão SQL
dentro do builder de schema (`->default(new Raw('CURRENT_TIMESTAMP'))`, ou o mais legível
`->defaultRaw('CURRENT_TIMESTAMP')`).

### Não há mais gerador de PHPDoc

`@property` num model descrevia as colunas que o `__get` mágico do `Db` exponha. Sem o
`Db`, um model não tem propriedade nenhuma, e a anotação viraria ficção — então o gerador
saiu junto. O sucessor é `generate:types`, que em vez de anotar produz classes de verdade:
o autocomplete passa a vir de propriedades declaradas, e o analisador estático verifica.

## 3. O sistema de migrações foi reescrito

O sistema antigo comparava seus models contra **cinco tabelas `_schema_*` dentro do próprio
banco**, que registravam o que o código *afirmou* ter feito — nunca o que o banco realmente
tinha. Daí vinham quase todos os defeitos conhecidos: índices que nunca eram criados,
`ON DELETE CASCADE` descartado, comentários e tamanhos de coluna perdidos no PostgreSQL, um
`ALTER` de collation gerado a cada execução, e três das quatro foreign keys de uma tabela
sumindo em silêncio.

Agora o fluxo é: **model → snapshot JSON commitado → differ puro → `.sql` numerado →
runner com tabela de controle**.

### O que fazer, na prática

```bash
vendor/bin/neoorm db:reset --force                    # banco limpo (desenvolvimento)
vendor/bin/neoorm migration:generate --name=initial   # primeiro .sql + snapshot + journal
vendor/bin/neoorm migration:up
vendor/bin/neoorm db:check                            # tem que sair 0
```

E daí em diante, a cada mudança de model:

```bash
vendor/bin/neoorm migration:generate --name=add_slug
vendor/bin/neoorm migration:up
```

Commite tudo que aparecer em `Migrations/`: os `.sql`, o `journal.json` e os `meta/*.json`.
**O snapshot é a única entrada do differ** — fora do repositório, dois desenvolvedores
geram um `0002` cada um, com conteúdos diferentes e o mesmo número.

Dentro do NeoFramework os mesmos comandos existem em `php neof`.

### O comando `migrate` não existe mais

`php neof migrate` imprime o mapa novo e **sai com código 1**. É deliberado: um script de
deploy que ainda o chame precisa PARAR, em vez de seguir achando que migrou. Um alias
silencioso escolheria errado pelo usuário — `db:push` estragaria o dia de quem esperava
versionamento, e `migration:up` não criaria o banco.

| Você fazia | Agora |
|---|---|
| `php neof migrate` | `migration:up` (deploy) ou `db:push` (desenvolvimento) |
| `php neof migrate --recreate` | `db:reset` |
| seed junto do migrate | `db:seed`, que roda depois de o schema convergir |
| PHPDoc junto do migrate | `php neof model:phpdoc` — ou, melhor, `generate:types` |

### A API antiga foi removida, sem shims

`Migrate`, `Column` e os métodos antigos de `Table` (`create()`, `update()`,
`addColumn()` e companhia) não existem na v2. Migração acontece exclusivamente pelos
comandos versionados; `Table` é um construtor puro de IR e não conhece PDO.

### `getColumns()` mudou de forma

Devolve `array<string, ColumnDefinition>` em ordem de declaração, em vez de `stdClass` com
SQL pré-cozido em `columnSql`. Se você lia `->columnSql`, `->null` ou `->default`, os campos
equivalentes estão em `ColumnDefinition` — com tipos, e sem string de SQL.

`ColumnDefinition::$comment` guarda **texto**, não `COMMENT '...'`. É o que conserta o
PHPDoc que saía como `@property string $name COMMENT 'City name'`.

### O que o builder passou a permitir

Além da mudança de forma da seção 2.1, duas restrições caíram:

- Índice de **uma** coluna. Antes o builder exigia duas, e por isso o caso mais comum que
  existe era impossível de declarar.
- A ordem das chamadas deixou de importar: `index()` pode vir antes de `columns()`, porque
  a validação passou a acontecer sobre o schema pronto.

### Estados que agora são recusados na entrada

O validador recusa declarar o que o banco **não sabe guardar**. Cada um destes produzia
divergência eterna: o model dizia uma coisa, a introspecção devolvia outra, e nenhuma
migração conseguia resolver.

| Declaração | Por quê |
|---|---|
| `setDefault(null)` em coluna nullable | Nenhum dos dois bancos distingue de "sem default" |
| `DATETIME` ou `UNSIGNED` no PostgreSQL | Não existem lá |
| Índice único no MySQL | Índice único e restrição de unicidade são o mesmo objeto no catálogo |
| Emoji em comentário no MySQL | O servidor guarda metadado em `utf8mb3` e devolve `?` |
| Auto incremento com `DEFAULT` | Os dois bancos recusam a combinação |

`migration:generate` diz qual, com `tabela.coluna`, e todos de uma vez.

### No MySQL, uma migração é retomável — não atômica

O MySQL faz commit implícito a cada DDL, então não existe transação que proteja uma
migração. Em vez de fingir que existe, o runner grava `applied_index` a cada statement: se
falhar, a exceção diz **qual** statement, e reexecutar retoma dali. No PostgreSQL, onde DDL
é transacional, a migração é genuinamente atômica.

Está na doc, no log e na mensagem da exceção porque prometer atomicidade onde o banco não a
oferece é pior que declarar a limitação.

## 4. Conexão sem emulação de prepared statements

`PDO::ATTR_EMULATE_PREPARES` agora é `false`, com `ATTR_STRINGIFY_FETCHES` também `false` e
`ATTR_DEFAULT_FETCH_MODE` em `FETCH_ASSOC`.

**Impacto:** colunas numéricas voltam como `int`/`float` em vez de `string`. Se o seu código
compara com `===` contra strings, ajuste. (Nas linhas geradas isso é irrelevante: o caster
já entrega o tipo declarado.)

O fuso da sessão passou a ser fixado na conexão (`SET time_zone = '+00:00'` /
`SET TIME ZONE 'UTC'`). Sem isso, para `TIMESTAMP` no MySQL o servidor converte usando
`@@session.time_zone` e a mesma linha volta diferente conforme o fuso do container.

## 5. Novas chaves de `.env`

```env
PATH_GENERATED=./App/Models/Generated      # padrão: {PATH_MODEL}/Generated
GENERATED_NAMESPACE=App\Models\Generated   # padrão: {MODEL_NAMESPACE}\Generated
PATH_MIGRATIONS=./Migrations               # padrão: ./Migrations
MIGRATIONS_TABLE=_neoorm_migrations
MIGRATIONS_STRICT=true
DBSCHEMA=public                            # só PostgreSQL
ENVIRONMENT=dev
```

Todas têm default; nada quebra se você não declarar nenhuma.

### 5.1 `PATH_MODEL` e `MODEL_NAMESPACE` deixaram de ser obrigatórios

Passaram a ter padrão como o resto: `./App/Models` e `App\Models`. O layout padrão completo é

```
App/Models/            models          App\Models
App/Models/Generated/  código gerado   App\Models\Generated
App/Seeders/           seeders         App\Seeders
Migrations/            .sql, journal.json, meta/
```

e um projeto que o siga não precisa declarar nenhuma dessas chaves.

Antes, `PATH_MODEL` ausente virava string vazia e o erro chegava como *"diretório de models
não encontrado: ''"*, enquanto `MODEL_NAMESPACE` ausente era remendado com um
`?: 'App\Models'` na chamada — um por chamador, e nenhum deles visível para
`PATH_GENERATED`, que preferia lançar exceção a assumir o mesmo padrão. Agora o padrão é um
só, mora em `Config`, e uma chave **declarada vazia** (`PATH_MODEL=`) vale como não
declarada em vez de virar a raiz do projeto.

**Impacto:** se o seu projeto guarda models fora de `App/Models` e você contava com o erro
para lembrar de configurar, a NeoORM agora procura em `App/Models` silenciosamente — e, se
esse diretório não existir, o erro cita o caminho padrão. Declare `PATH_MODEL` como sempre.

## 6. `.env` é localizado e lido de outra forma

- O arquivo é procurado subindo a árvore de diretórios, em vez de um caminho fixo relativo
  a `vendor/`. A biblioteca passa a funcionar fora do vendor.
- A leitura usa `INI_SCANNER_RAW`: senhas com `#`, `$`, `"` ou `'` deixam de ser
  corrompidas. Se você havia escapado a senha para contornar o bug, remova o escape.
- Precedência: `$_ENV` > `$_SERVER` > arquivo. Antes, qualquer variável presente em `$_ENV`
  impedia a leitura do arquivo inteiro.

## 7. Outras correções sem impacto de API

- Placeholders de bind são sequenciais (`:p0`, `:p1`), eliminando a chance de colisão do
  esquema anterior baseado em `md5(microtime + rand)`.
- `LIMIT`/`OFFSET` saem como `LIMIT n OFFSET m` nos dois dialetos. A 1.x emitia o
  `LIMIT a,b` do MySQL — erro de sintaxe no PostgreSQL, e com os dois números trocados,
  porque `LIMIT a,b` significa *offset a, count b*.
- `Connection::close()` foi adicionado.
