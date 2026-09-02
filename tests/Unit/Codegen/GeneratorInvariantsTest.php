<?php

declare(strict_types=1);

namespace Tests\Unit\Codegen;

use Diogodg\Neoorm\Codegen\CodegenException;
use Diogodg\Neoorm\Codegen\Generator;
use Diogodg\Neoorm\Codegen\Naming;
use Diogodg\Neoorm\Schema\ColumnDefinition;
use Diogodg\Neoorm\Schema\PrimaryKeyDefinition;
use Diogodg\Neoorm\Schema\SchemaDefinition;
use Diogodg\Neoorm\Schema\TableDefinition;
use Diogodg\Neoorm\Schema\Type\TypeSpec;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Factory\CodegenSchemas;
use Tests\Support\UnitTestCase;

/**
 * O que o gerador se recusa a emitir, e por quê.
 *
 * Os arquivos são commitados: um DTO sutilmente errado entra no repositório e passa a
 * ser lido como verdade. Recusar é sempre melhor que emitir na dúvida.
 */
final class GeneratorInvariantsTest extends UnitTestCase
{
    private function generator(): Generator
    {
        return new Generator('App\\Models\\Generated');
    }

    /**
     * @param list<\Diogodg\Neoorm\Codegen\GeneratedFile> $files
     */
    private function contentsOf(array $files, string $path): string
    {
        foreach ($files as $file) {
            if ($file->relativePath === $path) {
                return $file->contents;
            }
        }

        $this->fail("O gerador não produziu {$path}.");
    }

    /**
     * `TableDefinition::autoIncrementColumn()` devolve a PRIMEIRA que encontra, em
     * silêncio. Duas colunas auto incremento fariam o Insert omitir a errada.
     */
    public function testTwoAutoIncrementColumnsAreRefused(): void
    {
        $schema = new SchemaDefinition([
            new TableDefinition(
                name: 'broken',
                columns: [
                    new ColumnDefinition('id', TypeSpec::parse('INT'), notNull: true, autoIncrement: true),
                    new ColumnDefinition('seq', TypeSpec::parse('INT'), notNull: true, autoIncrement: true),
                ],
                primaryKey: new PrimaryKeyDefinition('pk_broken', ['id']),
            ),
        ]);

        $this->expectException(CodegenException::class);
        $this->expectExceptionMessageMatches('/mais de uma coluna auto incremento/');

        $this->generator()->generate($schema);
    }

    public function testAnAutoIncrementColumnOutsideThePrimaryKeyIsRefused(): void
    {
        $schema = new SchemaDefinition([
            new TableDefinition(
                name: 'broken',
                columns: [
                    new ColumnDefinition('id', TypeSpec::parse('INT'), notNull: true),
                    new ColumnDefinition('seq', TypeSpec::parse('INT'), notNull: true, autoIncrement: true),
                ],
                primaryKey: new PrimaryKeyDefinition('pk_broken', ['id']),
            ),
        ]);

        $this->expectException(CodegenException::class);
        $this->expectExceptionMessageMatches('/não faz parte da chave primária/');

        $this->generator()->generate($schema);
    }

    /**
     * `in-progress` e `in_progress` produzem o mesmo `InProgress`. Emitir dois cases
     * iguais geraria arquivo que nem parseia — então a coluna desiste do enum e fica
     * string, que é degradação, não falha.
     */
    public function testACollidingEnumFallsBackToStringInsteadOfEmittingBrokenCode(): void
    {
        $files = $this->generator()->generate(CodegenSchemas::collidingEnum());

        $paths = array_map(static fn ($file): string => $file->relativePath, $files);

        $this->assertNotContains('Enums/JobsState.php', $paths);

        $row = $this->contentsOf($files, 'Rows/JobsRow.php');

        $this->assertStringContainsString('public string $state', $row);
        $this->assertStringNotContainsString('JobsState', $row);
    }

    /**
     * Toda tabela produz linha, payload e referência; o registry sai uma vez só.
     */
    public function testEveryTableProducesItsThreeArtifacts(): void
    {
        $files = $this->generator()->generate(CodegenSchemas::matrix());
        $paths = array_map(static fn ($file): string => $file->relativePath, $files);

        foreach (['Rows/UsersRow.php', 'Inserts/UsersInsert.php', 'UsersTable.php',
                  'Rows/ScheduleUserRow.php', 'Inserts/ScheduleUserInsert.php', 'ScheduleUserTable.php',
                  'Enums/UsersStatus.php', 'Tables.php'] as $expected) {
            $this->assertContains($expected, $paths);
        }

        $this->assertCount(1, array_filter($paths, static fn (string $p): bool => $p === 'Tables.php'));
    }

    /**
     * Sem coluna opcional, o INSERT não tem parâmetro com default — e a tabela de
     * junção com chave composta é exatamente esse caso.
     */
    public function testATableWithoutOptionalColumnsHasNoDefaults(): void
    {
        $files = $this->generator()->generate(CodegenSchemas::matrix());
        $insert = $this->contentsOf($files, 'Inserts/ScheduleUserInsert.php');

        $this->assertStringContainsString('public int $schedule_id,', $insert);
        $this->assertStringNotContainsString('= null', $insert);
    }

    /**
     * Todo arquivo carrega o marcador: é ele que autoriza o writer a remover órfão. Sem
     * marcador, o writer trata como escrito à mão e preserva.
     */
    public function testEveryFileCarriesTheGeneratedMarker(): void
    {
        foreach ($this->generator()->generate(CodegenSchemas::matrix()) as $file) {
            $this->assertStringContainsString(
                '@generated by neoorm',
                $file->contents,
                "{$file->relativePath} saiu sem o marcador.",
            );
        }
    }

    // ----------------------------------------------------------------- Naming

    #[DataProvider('classNames')]
    public function testTableNamesBecomeClassNames(string $table, string $expected): void
    {
        $this->assertSame($expected, Naming::studly($table));
    }

    /**
     * @return iterable<string,array{string,string}>
     */
    public static function classNames(): iterable
    {
        yield 'simples' => ['users', 'Users'];
        yield 'com underscore' => ['schedule_user', 'ScheduleUser'];
        yield 'vários underscores' => ['a_b_c', 'ABC'];
    }

    #[DataProvider('enumCaseNames')]
    public function testEnumValuesBecomeCaseNames(string $value, ?string $expected): void
    {
        $this->assertSame($expected, Naming::enumCase($value));
    }

    /**
     * @return iterable<string,array{string,string|null}>
     */
    public static function enumCaseNames(): iterable
    {
        yield 'simples' => ['ativo', 'Ativo'];
        yield 'com hífen' => ['in-progress', 'InProgress'];
        yield 'com espaço' => ['muito ativo', 'MuitoAtivo'];
        // Identificador PHP não começa com dígito.
        yield 'começando com dígito' => ['12', '_12'];
        // Sem nada aproveitável: o emissor cai para string.
        yield 'só símbolos' => ['---', null];
        yield 'vazio' => ['', null];
    }
}
