# Testes de Migrations - NeoORM

Este documento descreve como executar e usar os testes para o sistema de migrations do NeoORM.

## Visão Geral

O sistema de migrations do NeoORM foi completamente refatorado para usar:

- **SchemaTracker**: Rastreia mudanças no schema do banco de dados
- **SchemaComparator**: Compara schemas e gera comandos SQL para sincronização
- **SchemaExtractor**: Extrai informações de schema das definições de tabela
- **TableMysql/TablePgsql**: Classes de tabela integradas com o sistema de schema

## Estrutura dos Testes

### Arquivo Principal
- `tests/MigrationsTest.php` - Contém todos os testes para o sistema de migrations

### Testes Incluídos

1. **testCreateSchemaTrackingTables**
   - Testa a criação das tabelas de rastreamento do schema
   - Verifica se as tabelas `_schema_*` são criadas corretamente

2. **testCreateMysqlTableWithTracking**
   - Testa criação de tabela MySQL com rastreamento automático
   - Verifica se o schema é salvo corretamente

3. **testCreatePgsqlTableWithTracking**
   - Testa criação de tabela PostgreSQL com rastreamento automático
   - Verifica se o schema é salvo corretamente

4. **testTableSchemaUpdate**
   - Testa atualização de schema quando colunas são adicionadas
   - Verifica se as mudanças são detectadas e aplicadas

5. **testSchemaExtraction**
   - Testa extração de schema de definições de tabela
   - Verifica se todas as informações são extraídas corretamente

6. **testSchemaComparison**
   - Testa comparação entre schemas atual e salvo
   - Verifica geração de comandos SQL para sincronização

7. **testIndexesAndConstraints**
   - Testa criação e rastreamento de índices e constraints
   - Verifica se são salvos corretamente no sistema de rastreamento

8. **testForeignKeys**
   - Testa criação e rastreamento de foreign keys
   - Verifica relacionamentos entre tabelas

9. **testRemoveTableFromTracking**
   - Testa remoção de tabelas do sistema de rastreamento
   - Verifica limpeza adequada

10. **testFullMigrationExecution**
    - Testa execução completa do sistema de migrations
    - Verifica integração com a classe `Migrate`

## Como Executar os Testes

### Pré-requisitos

1. **Banco de Dados Configurado**
   ```bash
   # MySQL
   mysql -u root -p -e "CREATE DATABASE test_neoorm_migrations;"
   
   # PostgreSQL
   psql -U postgres -c "CREATE DATABASE test_neoorm_migrations;"
   ```

2. **Configuração do Ambiente**
   
   Configure as variáveis de ambiente no arquivo `.env` ou diretamente:
   ```bash
   export DB_DRIVER=mysql
   export DB_HOST=localhost
   export DB_PORT=3306
   export DB_NAME=test_neoorm_migrations
   export DB_USER=root
   export DB_PASSWORD=
   export DB_CHARSET=utf8mb4
   ```

### Executando com PHPUnit

```bash
# Executar todos os testes de migrations
./vendor/bin/phpunit tests/MigrationsTest.php

# Executar um teste específico
./vendor/bin/phpunit tests/MigrationsTest.php --filter testCreateSchemaTrackingTables

# Executar com output detalhado
./vendor/bin/phpunit tests/MigrationsTest.php --verbose
```

### Executando com o Script Personalizado

```bash
# Executar o script de teste personalizado
php run_migrations_tests.php
```

## Funcionalidades Testadas

### 1. Sistema de Rastreamento
- Criação automática das tabelas de rastreamento
- Salvamento de schemas de tabela
- Rastreamento de mudanças
- Estatísticas de rastreamento

### 2. Comparação de Schemas
- Detecção de colunas adicionadas/removidas/modificadas
- Detecção de índices adicionados/removidos
- Detecção de constraints adicionadas/removidas
- Detecção de foreign keys adicionadas/removidas

### 3. Geração de SQL
- Comandos ALTER TABLE para adicionar colunas
- Comandos para criar/remover índices
- Comandos para criar/remover constraints
- Comandos para criar/remover foreign keys

### 4. Integração com Classes de Tabela
- TableMysql integrada com SchemaComparator
- TablePgsql integrada com SchemaComparator
- Criação automática usando schemas
- Atualização automática usando schemas

## Estrutura das Tabelas de Rastreamento

O sistema cria as seguintes tabelas para rastreamento:

```sql
-- Tabela principal de rastreamento de tabelas
_schema_tables (
    id, table_name, table_comment, engine, charset, 
    created_at, updated_at
)

-- Rastreamento de colunas
_schema_columns (
    id, table_name, column_name, data_type, is_nullable,
    column_default, extra, column_comment, created_at
)

-- Rastreamento de índices
_schema_indexes (
    id, table_name, index_name, index_type, columns,
    is_unique, created_at
)

-- Rastreamento de constraints
_schema_constraints (
    id, table_name, constraint_name, constraint_type,
    columns, created_at
)

-- Rastreamento de foreign keys
_schema_foreign_keys (
    id, table_name, column_name, referenced_table,
    referenced_column, on_delete, on_update, created_at
)
```

## Exemplo de Uso

```php
use Diogodg\Neoorm\Migrations\Driver\TableMysql;
use Diogodg\Neoorm\Migrations\Column;

// Criar uma nova tabela
$table = new TableMysql('users');
$table->addColumn((new Column('id', 'INT'))->isPrimary())
      ->addColumn((new Column('name', 'VARCHAR', '255'))->isNotNull())
      ->addColumn((new Column('email', 'VARCHAR', '255'))->isUnique());

$table->isAutoIncrement();
$table->create(); // Cria a tabela e salva o schema

// Atualizar a tabela (adicionar coluna)
$table = new TableMysql('users');
$table->addColumn((new Column('id', 'INT'))->isPrimary())
      ->addColumn((new Column('name', 'VARCHAR', '255'))->isNotNull())
      ->addColumn((new Column('email', 'VARCHAR', '255'))->isUnique())
      ->addColumn((new Column('phone', 'VARCHAR', '20'))); // Nova coluna

$table->isAutoIncrement();
$table->update(); // Detecta mudanças e aplica automaticamente
```

## Troubleshooting

### Problemas Comuns

1. **Erro de Conexão com Banco**
   - Verifique as configurações de conexão
   - Certifique-se de que o banco de dados existe
   - Verifique permissões do usuário

2. **Tabelas de Rastreamento Não Criadas**
   - Execute `$schemaTracker->createTrackingTables()` manualmente
   - Verifique se o usuário tem permissões para criar tabelas

3. **Testes Falhando**
   - Limpe o banco de dados de teste
   - Verifique se não há conflitos com dados existentes
   - Execute os testes individualmente para isolar problemas

### Logs e Debug

Para debug, você pode habilitar logs nas classes:

```php
// Habilitar debug no SchemaComparator
$comparator = new SchemaComparator($schemaTracker);
$comparator->setDebug(true);

// Verificar estatísticas de rastreamento
$stats = $schemaTracker->getTrackingStats();
var_dump($stats);
```

## Contribuindo

Para adicionar novos testes:

1. Adicione o método de teste na classe `MigrationsTest`
2. Siga o padrão de nomenclatura `test*`
3. Use os métodos de limpeza adequados
4. Documente o que o teste verifica
5. Adicione o teste à lista no script de execução

## Suporte

Para problemas ou dúvidas sobre os testes de migrations:

1. Verifique este README
2. Execute os testes individualmente para isolar problemas
3. Verifique os logs do banco de dados
4. Consulte a documentação das classes de migration 