# Atualizando para o NeoORM 2.0

A versão 2.0 corrige falhas de injeção de SQL e um comportamento destrutivo nas
migrações. As mudanças abaixo quebram compatibilidade e exigem revisão do código
que usa a biblioteca.

---

## 1. Operadores passam por uma allowlist

`addFilter()`, `addHaving()` e `addJoin()` agora validam o operador contra
`Diogodg\Neoorm\Enums\LogicalOperator`. Qualquer valor fora da lista lança
exceção.

Operadores aceitos: `=`, `!=`, `<>`, `>`, `>=`, `<`, `<=`, `LIKE`, `NOT LIKE`,
`IN`, `NOT IN`, `BETWEEN`, `NOT BETWEEN`.

Caixa e espaçamento são normalizados, então `"not   like"` continua funcionando.

**Por quê:** o operador ia direto para o SQL sem qualquer verificação. Um valor
como `"= 1 OR 1=1 --"` era aceito e alterava a semântica da query inteira.

```php
// Antes (aceito, e explorável se o operador viesse de input)
$db->addFilter('id', $_GET['op'], $valor);

// Agora
$db->addFilter('id', LogicalOperator::EQUAL, $valor);
```

## 2. `IS NULL` deixou de ser um operador

O ramo `IS` interpolava o valor **cru** na query. Ele foi substituído por dois
métodos cujo predicado vem de constante interna:

```php
// Antes
$db->addFilter('deleted_at', 'IS', 'NULL');
$db->addFilter('deleted_at', 'IS NOT', 'NULL');

// Agora
$db->addFilterNull('deleted_at');
$db->addFilterNotNull('deleted_at');
```

Ambos aceitam os mesmos parâmetros de agrupamento de `addFilter()`
(`OperatorCondition`, `startGroupFilter`, `endGroupFilter`).

## 3. `IN` e `BETWEEN` validam o formato do valor

- `IN` / `NOT IN` exigem array não vazio.
- `BETWEEN` / `NOT BETWEEN` exigem array com exatamente dois elementos.
- Os demais operadores recusam array.

## 4. Alias em `selectColumns()` é validado

O atalho `[coluna, alias]` não passava por `validateIdentifier()`. Agora as duas
partes são validadas; aliases com espaço, parênteses ou função precisam de
`Raw`:

```php
// Continua válido
$db->selectColumns(['client.name', 'client_name']);

// Precisa de Raw
$db->selectColumns(new Raw('COUNT(*) as total'));
```

`Raw` é a via de escape explícita da biblioteca e **não é sanitizada** — nunca a
construa a partir de entrada do usuário.

## 5. `Table::create()` não apaga mais a tabela

`create()` executava `DROP TABLE IF EXISTS` incondicionalmente. Se você depende
desse comportamento, troque por `recreate()`:

```php
$table->create();    // cria se não existir; nunca destrói dados
$table->recreate();  // descarta a tabela e recria (destrutivo)
```

A interface `Migrations\Interface\Table` ganhou `recreate()`. Implementações
próprias precisam declará-lo.

## 6. Migrações fazem rollback de verdade

O `rollBack()` estava comentado: uma migração interrompida deixava a transação
aberta. Agora o rollback é executado.

Atenção: em MySQL, DDL provoca commit implícito, então a transação só protege de
fato os seeds. Em PostgreSQL ela funciona integralmente.

`recreateDatabase()` também passou a propagar erros em vez de apenas imprimi-los,
e fecha a conexão antes do `DROP DATABASE` — o banco não pode ser removido com
sessões abertas.

## 7. Conexão sem emulação de prepared statements

`PDO::ATTR_EMULATE_PREPARES` agora é `false`, com `ATTR_STRINGIFY_FETCHES` também
`false` e `ATTR_DEFAULT_FETCH_MODE` em `FETCH_ASSOC`.

**Impacto:** colunas numéricas passam a voltar como `int`/`float` em vez de
`string`. Se o seu código compara com `===` contra strings, ajuste.

## 8. `.env` é localizado e lido de outra forma

- O arquivo é procurado subindo a árvore de diretórios, em vez de um caminho
  fixo relativo a `vendor/`. A biblioteca passa a funcionar fora do vendor.
- A leitura usa `INI_SCANNER_RAW`: senhas com `#`, `$`, `"` ou `'` deixam de ser
  corrompidas.
- Precedência: `$_ENV` > `$_SERVER` > arquivo. Antes, qualquer variável presente
  em `$_ENV` impedia a leitura do arquivo inteiro.

Se a sua senha de banco tinha caracteres especiais e você a havia escapado para
contornar o bug, remova o escape.

## 9. Outras correções sem impacto de API

- `addOrder()` chamado duas vezes causava erro fatal (`$this->order[] .=`).
- `Table` com driver não suportado lança exceção em vez de deixar a propriedade
  sem inicializar.
- `Migrate` valida `PATH_MODEL`, ordena os models e aplica FKs também às tabelas
  já existentes.
- Placeholders de bind passaram a ser sequenciais (`:p0`, `:p1`), eliminando a
  chance de colisão do esquema anterior baseado em `md5(microtime + rand)`.
- `Connection::close()` foi adicionado.
