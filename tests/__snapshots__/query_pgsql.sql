-- Consultas do dialeto pgsql, geradas por CompilerGoldenTest.
-- Regenere com: composer test:snapshots

-- [select_all]
SELECT "users".* FROM "users";

-- [select_columns]
SELECT "users"."id", "users"."name" FROM "users";

-- [select_where]
SELECT "users".* FROM "users" WHERE ("users"."age" > :p0 AND "users"."name" = :p1);
--   :p0 = 18 (PDO type 1)
--   :p1 = 'Diogo' (PDO type 2)

-- [select_or_and_not]
SELECT "users".* FROM "users" WHERE (("users"."age" = :p0 OR "users"."age" = :p1) AND NOT ("users"."deleted_at" IS NULL));
--   :p0 = 18 (PDO type 1)
--   :p1 = 21 (PDO type 1)

-- [select_in_between_like]
SELECT "users".* FROM "users" WHERE ("users"."id" IN (:p0, :p1, :p2) AND "users"."age" BETWEEN :p3 AND :p4 AND "users"."name" LIKE :p5);
--   :p0 = 1 (PDO type 1)
--   :p1 = 2 (PDO type 1)
--   :p2 = 3 (PDO type 1)
--   :p3 = 18 (PDO type 1)
--   :p4 = 65 (PDO type 1)
--   :p5 = 'D%' (PDO type 2)

-- [select_case_insensitive]
SELECT "users".* FROM "users" WHERE "users"."name" ILIKE :p0;
--   :p0 = 'd%' (PDO type 2)

-- [select_join_disambiguates]
SELECT "users"."id", "posts"."id" AS "posts__id", "posts"."title" AS "posts__title" FROM "users" LEFT JOIN "posts" ON "posts"."author_id" = "users"."id";

-- [select_alias]
SELECT "autor"."id", "autor"."name" FROM "users" AS "autor";

-- [select_group_having]
SELECT "users"."age", COUNT(*) FROM "users" GROUP BY "users"."age" HAVING COUNT(*) > :p0;
--   :p0 = 1 (PDO type 1)

-- [select_aggregates]
SELECT COUNT(DISTINCT "users"."id"), SUM("users"."age"), COALESCE("users"."name", :p0) FROM "users";
--   :p0 = 'sem nome' (PDO type 2)

-- [select_order_nulls]
SELECT "users".* FROM "users" ORDER BY "users"."age" DESC NULLS LAST, "users"."name" ASC;

-- [select_pagination]
SELECT "users".* FROM "users" WHERE "users"."name" = :p0 LIMIT :p1 OFFSET :p2;
--   :p0 = 'Diogo' (PDO type 2)
--   :p1 = 10 (PDO type 1)
--   :p2 = 20 (PDO type 1)

-- [select_offset_only]
SELECT "users".* FROM "users" OFFSET :p0;
--   :p0 = 20 (PDO type 1)

-- [select_distinct]
SELECT DISTINCT "users"."age" FROM "users";

-- [select_subquery_in]
SELECT "users".* FROM "users" WHERE "users"."id" IN (SELECT author_id FROM posts WHERE published = :p0);
--   :p0 = true (PDO type 5)

-- [select_raw_fragment]
SELECT "users".* FROM "users" WHERE EXTRACT(YEAR FROM "users"."deleted_at") = :p0;
--   :p0 = 2026 (PDO type 1)

-- [insert_single]
INSERT INTO "users" ("name", "age") VALUES (:p0, :p1);
--   :p0 = 'Diogo' (PDO type 2)
--   :p1 = 38 (PDO type 1)

-- [insert_multi]
INSERT INTO "users" ("name", "age") VALUES (:p0, :p1), (:p2, :p3);
--   :p0 = 'Diogo' (PDO type 2)
--   :p1 = 38 (PDO type 1)
--   :p2 = 'Ana' (PDO type 2)
--   :p3 = 29 (PDO type 1)

-- [update_where]
UPDATE "users" SET "name" = :p0, "age" = :p1 WHERE "users"."id" = :p2;
--   :p0 = 'Novo' (PDO type 2)
--   :p1 = 40 (PDO type 1)
--   :p2 = 1 (PDO type 1)

-- [update_full_table]
UPDATE "users" SET "age" = :p0;
--   :p0 = 0 (PDO type 1)

-- [delete_where]
DELETE FROM "users" WHERE "users"."id" = :p0;
--   :p0 = 1 (PDO type 1)

-- [delete_full_table]
DELETE FROM "users";

-- [insert_returning]
INSERT INTO "users" ("name") VALUES (:p0) RETURNING "id", "name";
--   :p0 = 'Diogo' (PDO type 2)

-- [update_returning]
UPDATE "users" SET "name" = :p0 WHERE "users"."id" = :p1 RETURNING "id", "name";
--   :p0 = 'Novo' (PDO type 2)
--   :p1 = 1 (PDO type 1)

-- [delete_returning]
DELETE FROM "users" WHERE "users"."id" = :p0 RETURNING "id";
--   :p0 = 1 (PDO type 1)
