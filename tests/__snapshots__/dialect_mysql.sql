-- Superfície do dialeto mysql, gerada por DialectGoldenTest.
-- Regenere com: composer test:snapshots

-- [create_table] cria a tabela 'product' com 7 colunas
CREATE TABLE `product` (
	`id` INT NOT NULL AUTO_INCREMENT,
	`name` VARCHAR(120) NOT NULL COMMENT 'Nome do produto',
	`price` DECIMAL(10,2) NOT NULL DEFAULT 0,
	`active` TINYINT(1) NOT NULL DEFAULT 1,
	`description` TEXT NULL,
	`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
	`category` INT NULL,
	PRIMARY KEY (`id`)
) ENGINE=InnoDB COMMENT='Catálogo de produtos';

-- [drop_table] remove a tabela 'product'
DROP TABLE `product`;

-- [rename_table] renomeia a tabela 'product' para 'item'
ALTER TABLE `product` RENAME TO `item`;

-- [set_table_comment] define o comentário da tabela 'product'
ALTER TABLE `product` COMMENT='Catálogo revisado';

-- [set_table_options] define engine InnoDB e collation utf8mb4_0900_ai_ci na tabela 'product'
ALTER TABLE `product` ENGINE=InnoDB, COLLATE=utf8mb4_0900_ai_ci;

-- [add_column] adiciona a coluna 'product.sku' (VARCHAR(32))
ALTER TABLE `product` ADD COLUMN `sku` VARCHAR(32) NOT NULL COMMENT 'Código do produto';

-- [drop_column] remove a coluna 'product.description'
ALTER TABLE `product` DROP COLUMN `description`;

-- [rename_column] renomeia a coluna 'product.name' para 'title'
ALTER TABLE `product` RENAME COLUMN `name` TO `title`;

-- [alter_column] altera a coluna 'product.name' (tipo, comentário)
ALTER TABLE `product` MODIFY COLUMN `name` VARCHAR(200) NOT NULL COMMENT 'Nome comercial';

-- [add_primary_key] define a chave primária de 'product' em (id)
ALTER TABLE `product` ADD PRIMARY KEY (`id`);

-- [drop_primary_key] remove a chave primária de 'product'
ALTER TABLE `product` DROP PRIMARY KEY;

-- [add_unique_constraint] adiciona a restrição de unicidade 'product_sku_unique' em 'product' (sku)
ALTER TABLE `product` ADD CONSTRAINT `product_sku_unique` UNIQUE (`sku`);

-- [drop_unique_constraint] remove a restrição de unicidade 'product_sku_unique' de 'product'
ALTER TABLE `product` DROP INDEX `product_sku_unique`;

-- [create_index] cria o índice 'product_category_index' em 'product' (category)
CREATE INDEX `product_category_index` ON `product` (`category`);

-- [drop_index] remove o índice 'product_category_index' de 'product'
ALTER TABLE `product` DROP INDEX `product_category_index`;

-- [add_foreign_key] adiciona a foreign key 'product_category_category_id_fk' em 'product' (category) -> category (id)
ALTER TABLE `product` ADD CONSTRAINT `product_category_category_id_fk` FOREIGN KEY (`category`) REFERENCES `category` (`id`);

-- [drop_foreign_key] remove a foreign key 'product_category_category_id_fk' de 'product'
ALTER TABLE `product` DROP FOREIGN KEY `product_category_category_id_fk`;

-- [add_check_constraint] adiciona a restrição CHECK 'product_b3940f6d_check' em 'product'
ALTER TABLE `product` ADD CONSTRAINT `product_b3940f6d_check` CHECK (price >= 0);

-- [drop_check_constraint] remove a restrição CHECK 'product_b3940f6d_check' de 'product'
ALTER TABLE `product` DROP CHECK `product_b3940f6d_check`;

-- [raw_sql] executa SQL cru: CREATE VIEW active_product AS SELECT id FROM product WHER...
CREATE VIEW active_product AS SELECT id FROM product WHERE active = 1;

-- [create_table_composite_pk] cria a tabela 'product_category' com 2 colunas
CREATE TABLE `product_category` (
	`product` INT NOT NULL,
	`category` INT NOT NULL,
	PRIMARY KEY (`product`, `category`)
);

-- [create_table_without_primary_key] cria a tabela 'import_log' com 2 colunas
CREATE TABLE `import_log` (
	`payload` TEXT NULL,
	`at` TIMESTAMP NULL
);

-- [create_table_reserved_words] cria a tabela 'order' com 4 colunas
CREATE TABLE `order` (
	`select` INT NOT NULL,
	`from` VARCHAR(40) NULL,
	`default` TINYINT(1) NOT NULL DEFAULT 0,
	`user` VARCHAR(40) NULL,
	PRIMARY KEY (`select`)
);

-- [create_table_hostile_comment] cria a tabela 'audit_log' com 2 colunas
CREATE TABLE `audit_log` (
	`id` BIGINT NOT NULL AUTO_INCREMENT,
	`detail` TEXT NULL COMMENT 'aspa '' dupla " barra \\ nova
linha acentuação 🎯',
	PRIMARY KEY (`id`)
);

-- [set_table_comment_removed] remove o comentário da tabela 'product'
ALTER TABLE `product` COMMENT='';

-- [set_table_options_empty] restaura as opções padrão da tabela 'product'
-- (nenhum statement neste dialeto)

-- [add_column_default_empty_string] adiciona a coluna 'product.note' (VARCHAR(80))
ALTER TABLE `product` ADD COLUMN `note` VARCHAR(80) NOT NULL DEFAULT '';

-- [add_column_expression_default] adiciona a coluna 'product.updated_at' (TIMESTAMP)
ALTER TABLE `product` ADD COLUMN `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP;

-- [alter_column_type_and_default] altera a coluna 'product.code' (tipo)
ALTER TABLE `product` MODIFY COLUMN `code` INT NOT NULL DEFAULT 0;

-- [alter_column_comment_only] altera a coluna 'product.name' (comentário)
ALTER TABLE `product` MODIFY COLUMN `name` VARCHAR(120) NOT NULL COMMENT 'depois';

-- [alter_column_drop_not_null] altera a coluna 'product.description' (nulidade)
ALTER TABLE `product` MODIFY COLUMN `description` TEXT NULL;

-- [alter_column_drop_default] altera a coluna 'product.price' (default)
ALTER TABLE `product` MODIFY COLUMN `price` DECIMAL(10,2) NOT NULL;

-- [alter_column_add_identity] altera a coluna 'product.id' (auto incremento)
ALTER TABLE `product` MODIFY COLUMN `id` INT NOT NULL AUTO_INCREMENT;

-- [alter_column_drop_identity] altera a coluna 'product.id' (auto incremento)
ALTER TABLE `product` MODIFY COLUMN `id` INT NOT NULL;

-- [drop_primary_key_with_auto_increment] remove a chave primária de 'product'
ALTER TABLE `product` MODIFY COLUMN `id` INT NOT NULL;
--> statement-breakpoint
ALTER TABLE `product` DROP PRIMARY KEY;

-- [create_index_unique] cria o índice único 'product_sku_unique_index' em 'product' (sku)
CREATE UNIQUE INDEX `product_sku_unique_index` ON `product` (`sku`);

-- [create_index_multi_column] cria o índice 'product_category_name_index' em 'product' (category, name)
CREATE INDEX `product_category_name_index` ON `product` (`category`, `name`);

-- [add_foreign_key_cascade] adiciona a foreign key 'product_category_category_id_fk' em 'product' (category) -> category (id)
ALTER TABLE `product` ADD CONSTRAINT `product_category_category_id_fk` FOREIGN KEY (`category`) REFERENCES `category` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

-- [add_foreign_key_set_null] adiciona a foreign key 'product_category_category_id_fk' em 'product' (category) -> category (id)
ALTER TABLE `product` ADD CONSTRAINT `product_category_category_id_fk` FOREIGN KEY (`category`) REFERENCES `category` (`id`) ON DELETE SET NULL;

-- [add_foreign_key_composite] adiciona a foreign key 'product_category_product_category_catalog_entry_produc_d3dc2462' em 'product_category' (product, category) -> catalog_entry (product, category)
ALTER TABLE `product_category` ADD CONSTRAINT `product_category_product_category_catalog_entry_produc_d3dc2462` FOREIGN KEY (`product`, `category`) REFERENCES `catalog_entry` (`product`, `category`) ON DELETE CASCADE;

-- [add_foreign_key_self_reference] adiciona a foreign key 'category_parent_category_id_fk' em 'category' (parent) -> category (id)
ALTER TABLE `category` ADD CONSTRAINT `category_parent_category_id_fk` FOREIGN KEY (`parent`) REFERENCES `category` (`id`) ON DELETE SET NULL;

-- [migrations_table] tabela de controle do runner
CREATE TABLE IF NOT EXISTS `_neoorm_migrations` (
	`tag` VARCHAR(191) NOT NULL,
	`hash` CHAR(64) NOT NULL,
	`statements` INT NOT NULL DEFAULT 0,
	`applied_index` INT NOT NULL DEFAULT 0,
	`status` VARCHAR(16) NOT NULL DEFAULT 'running',
	`error` TEXT NULL,
	`started_at` TIMESTAMP NULL,
	`finished_at` TIMESTAMP NULL,
	PRIMARY KEY (`tag`)
);
