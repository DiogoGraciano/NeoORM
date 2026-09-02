<?php

declare(strict_types=1);

namespace Tests\Unit\Diff;

use Diogodg\Neoorm\Migrations\Diff\InteractiveRenameResolver;
use Diogodg\Neoorm\Migrations\Diff\MapRenameResolver;
use Diogodg\Neoorm\Migrations\Diff\NoRenameResolver;
use Diogodg\Neoorm\Migrations\Diff\Prompter;
use Diogodg\Neoorm\Migrations\Diff\SchemaDiffer;
use Diogodg\Neoorm\Migrations\Exception\MigrationException;
use Diogodg\Neoorm\Migrations\Snapshot\Snapshot;
use Diogodg\Neoorm\Migrations\Snapshot\SnapshotMeta;
use Diogodg\Neoorm\Schema\SchemaDefinition;
use Tests\Support\Concerns\IrAssertions;
use Tests\Support\Doubles\ScriptedPrompter;
use Tests\Support\Factory\Ir;
use Tests\Support\UnitTestCase;

/**
 * Renomeação: a única coisa que o differ não consegue deduzir.
 *
 * Olhando dois snapshots, uma coluna renomeada e uma coluna removida junto com
 * outra adicionada são o mesmo par de fatos. Adivinhar por semelhança apagaria
 * dados quando o chute errasse, então a decisão entra de fora — e o differ
 * continua puro.
 */
final class RenameTest extends UnitTestCase
{
    use IrAssertions;

    private function city(string $table = 'city', string $column = 'name'): SchemaDefinition
    {
        return Ir::schema([Ir::table($table, [Ir::id(), Ir::column($column, 'VARCHAR', 120, notNull: true)])]);
    }

    private function snapshot(SchemaDefinition $schema, SnapshotMeta $meta = new SnapshotMeta()): Snapshot
    {
        return new Snapshot('pgsql', '0001', '0000', $schema, $meta);
    }

    // ------------------------------------------------------- sem resolvedor

    /**
     * O padrão é tratar remoção como remoção. Quem impede isso de apagar uma coluna
     * por engano não é o differ, é o comando: diante de uma forma ambígua,
     * `migration:generate` não escreve nada e sai com erro.
     */
    public function testWithoutAResolverARenameLooksLikeADropAndAnAdd(): void
    {
        $operations = (new SchemaDiffer(new NoRenameResolver()))->diff(
            $this->snapshot($this->city()),
            $this->snapshot($this->city('city', 'title')),
        );

        $this->assertOperationsAre(
            [
                "adiciona a coluna 'city.title' (VARCHAR(120))",
                "remove a coluna 'city.name'",
            ],
            $operations,
        );
    }

    // ------------------------------------------------- registrado no snapshot

    /**
     * O snapshot de destino é autoridade sobre o que já foi decidido, e é consultado
     * antes de qualquer resolvedor. É o que faz um `generate` em CI, sem terminal e sem
     * ninguém para responder, chegar ao mesmo resultado que a máquina de quem gerou a
     * migração.
     */
    public function testARenameRecordedInTheSnapshotNeedsNoResolver(): void
    {
        $operations = (new SchemaDiffer(new NoRenameResolver()))->diff(
            $this->snapshot($this->city()),
            $this->snapshot($this->city('city', 'title'), new SnapshotMeta([], ['city.name' => 'title'])),
        );

        $this->assertOperationsAre(["renomeia a coluna 'city.name' para 'title'"], $operations);
    }

    public function testATableRenameRecordedInTheSnapshotIsHonoured(): void
    {
        $operations = (new SchemaDiffer())->diff(
            $this->snapshot($this->city()),
            $this->snapshot($this->city('town'), new SnapshotMeta(['city' => 'town'])),
        );

        $this->assertOperationsAre(["renomeia a tabela 'city' para 'town'"], $operations);
    }

    /**
     * A fronteira da P3, que é a regra mais fácil de errar no differ: operação ANTES
     * do rename usa o nome ANTIGO da tabela — é o nome que está no banco naquele
     * instante — e operação depois usa o novo.
     *
     * Aqui isso aparece em dois lugares: o índice é derrubado em `city` e recriado em
     * `town`, e a renomeação da coluna já vem com o nome novo da tabela.
     */
    public function testOperationsBeforeTheRenameUseTheOldNameAndAfterTheNewOne(): void
    {
        $from = Ir::schema([Ir::table(
            'city',
            [Ir::id(), Ir::column('label', 'VARCHAR', 120, notNull: true)],
            indexes: [Ir::index('city', ['label'])],
        )]);

        $to = Ir::schema([Ir::table(
            'town',
            [Ir::id(), Ir::column('name', 'VARCHAR', 120, notNull: true)],
            indexes: [Ir::index('town', ['name'])],
        )]);

        $operations = (new SchemaDiffer())->diff(
            $this->snapshot($from),
            $this->snapshot($to, new SnapshotMeta(['city' => 'town'], ['city.label' => 'name'])),
        );

        $this->assertOperationsAre(
            [
                "remove o índice 'city_label_index' de 'city'",
                "renomeia a tabela 'city' para 'town'",
                "renomeia a coluna 'town.label' para 'name'",
                "cria o índice 'town_name_index' em 'town' (name)",
            ],
            $operations,
        );
    }

    /**
     * Renomear e alterar a mesma coluna na mesma migração: o rename vem primeiro, e o
     * ALTER já usa o nome novo.
     */
    public function testAColumnCanBeRenamedAndAlteredInTheSameMigration(): void
    {
        $from = Ir::schema([Ir::table('city', [Ir::id(), Ir::column('label', 'VARCHAR', 60, notNull: true)])]);
        $to = Ir::schema([Ir::table('city', [Ir::id(), Ir::column('name', 'VARCHAR', 200, notNull: true)])]);

        $operations = (new SchemaDiffer())->diff(
            $this->snapshot($from),
            $this->snapshot($to, new SnapshotMeta([], ['city.label' => 'name'])),
        );

        $this->assertOperationsAre(
            [
                "renomeia a coluna 'city.label' para 'name'",
                "altera a coluna 'city.name' (tipo)",
            ],
            $operations,
        );
    }

    /**
     * Renomear uma coluna que está num índice não derruba o índice: as duas engines
     * atualizam a referência sozinhas, e o differ mapeia a coluna antiga para o nome
     * novo antes de comparar.
     */
    public function testRenamingAColumnDoesNotChurnAnIndexWhoseNameDidNotChange(): void
    {
        $from = Ir::schema([Ir::table(
            'city',
            [Ir::id(), Ir::column('label', 'VARCHAR', 120, notNull: true)],
            indexes: [Ir::index('city', ['label'])],
        )]);

        $to = Ir::schema([Ir::table(
            'city',
            [Ir::id(), Ir::column('name', 'VARCHAR', 120, notNull: true)],
            // Mesmo nome de índice, coluna renomeada por baixo.
            indexes: [new \Diogodg\Neoorm\Schema\IndexDefinition('city_label_index', ['name'])],
        )]);

        $operations = (new SchemaDiffer())->diff(
            $this->snapshot($from),
            $this->snapshot($to, new SnapshotMeta([], ['city.label' => 'name'])),
        );

        $this->assertOperationsAre(["renomeia a coluna 'city.label' para 'name'"], $operations);
    }

    /**
     * Renomear a tabela derruba e recria índices e restrições, porque os nomes gerados
     * embutem o nome da tabela. É correto — o banco preserva os nomes antigos num
     * rename, e o snapshot novo declara os novos — e é uma consequência assumida de
     * casar restrições por nome, que é o que impede confundir dois índices sobre as
     * mesmas colunas.
     */
    public function testRenamingATableRebuildsItsNamedConstraints(): void
    {
        $from = Ir::schema([Ir::table(
            'city',
            [Ir::id(), Ir::column('ibge', 'INT')],
            uniques: [Ir::unique('city', ['ibge'])],
        )]);

        $to = Ir::schema([Ir::table(
            'town',
            [Ir::id(), Ir::column('ibge', 'INT')],
            uniques: [Ir::unique('town', ['ibge'])],
        )]);

        $operations = (new SchemaDiffer())->diff(
            $this->snapshot($from),
            $this->snapshot($to, new SnapshotMeta(['city' => 'town'])),
        );

        $this->assertOperationsAre(
            [
                "remove a restrição de unicidade 'city_ibge_unique' de 'city'",
                "renomeia a tabela 'city' para 'town'",
                "adiciona a restrição de unicidade 'town_ibge_unique' em 'town' (ibge)",
            ],
            $operations,
        );
    }

    // ------------------------------------------------------ MapRenameResolver

    public function testTheMapResolverAcceptsTableAndColumnSyntax(): void
    {
        $operations = (new SchemaDiffer(new MapRenameResolver(['city.name:title'])))->diff(
            $this->snapshot($this->city()),
            $this->snapshot($this->city('city', 'title')),
        );

        $this->assertOperationsAre(["renomeia a coluna 'city.name' para 'title'"], $operations);
    }

    public function testTheMapResolverHandlesTableRenames(): void
    {
        $operations = (new SchemaDiffer(new MapRenameResolver(['city:town'])))->diff(
            $this->snapshot($this->city()),
            $this->snapshot($this->city('town')),
        );

        $this->assertOperationsAre(["renomeia a tabela 'city' para 'town'"], $operations);
    }

    /**
     * O ponto distingue tabela de coluna, e só funciona porque `IdentifierValidator`
     * recusa ponto dentro de um identificador — não há ambiguidade possível entre uma
     * tabela chamada `city.name` e a coluna `name` da tabela `city`.
     */
    public function testTheDotIsWhatSeparatesTableFromColumn(): void
    {
        $resolver = new MapRenameResolver(['city:town', 'city.name:title']);

        $this->assertSame(['city' => 'town'], $resolver->resolveTables(['city'], ['town']));
        $this->assertSame(['name' => 'title'], $resolver->resolveColumns('city', ['name'], ['title']));
    }

    /**
     * Um par que não corresponde a nenhuma diferença real é erro, não silêncio: quem
     * digitou o nome errado precisa saber, porque a alternativa é a migração remover a
     * coluna achando que foi isso que se pediu.
     */
    public function testAMisspelledSourceIsAnError(): void
    {
        $this->expectException(MigrationException::class);
        $this->expectExceptionMessageMatches('/não desapareceu do schema/');

        (new SchemaDiffer(new MapRenameResolver(['city.nome:title'])))->diff(
            $this->snapshot($this->city()),
            $this->snapshot($this->city('city', 'title')),
        );
    }

    public function testAMisspelledTargetIsAnError(): void
    {
        $this->expectException(MigrationException::class);
        $this->expectExceptionMessageMatches('/não apareceu no schema novo/');

        (new SchemaDiffer(new MapRenameResolver(['city.name:titulo'])))->diff(
            $this->snapshot($this->city()),
            $this->snapshot($this->city('city', 'title')),
        );
    }

    public function testMalformedRenameSyntaxIsRefused(): void
    {
        $this->expectException(MigrationException::class);
        $this->expectExceptionMessageMatches('/antiga:nova/');

        new MapRenameResolver(['city']);
    }

    /** Mover coluna entre tabelas não é renomeação, e a sintaxe não a permite. */
    public function testAQualifiedTargetIsRefused(): void
    {
        $this->expectException(MigrationException::class);
        $this->expectExceptionMessageMatches('/mover coluna entre tabelas/i');

        new MapRenameResolver(['city.name:town.title']);
    }

    // ---------------------------------------------- InteractiveRenameResolver

    /**
     * A pergunta é sobre o que SUMIU, não sobre o que apareceu: é a remoção que
     * destrói dados, e "nenhuma das opções" leva a um DROP. Essa é a decisão que
     * merece a pergunta.
     */
    public function testTheInteractiveResolverAsksAboutWhatDisappeared(): void
    {
        $prompter = new ScriptedPrompter(['title']);

        $operations = (new SchemaDiffer(new InteractiveRenameResolver($prompter)))->diff(
            $this->snapshot($this->city()),
            $this->snapshot($this->city('city', 'title')),
        );

        $this->assertOperationsAre(["renomeia a coluna 'city.name' para 'title'"], $operations);
        $this->assertStringContainsString("'city.name' desapareceu", $prompter->questions[0]);
    }

    public function testAnsweringNoneProducesADropAndAnAdd(): void
    {
        $operations = (new SchemaDiffer(new InteractiveRenameResolver(new ScriptedPrompter([null]))))->diff(
            $this->snapshot($this->city()),
            $this->snapshot($this->city('city', 'title')),
        );

        $this->assertOperationsAre(
            [
                "adiciona a coluna 'city.title' (VARCHAR(120))",
                "remove a coluna 'city.name'",
            ],
            $operations,
        );
    }

    /**
     * Um nome de destino já escolhido sai das opções seguintes: uma coluna não pode ser
     * o destino de duas renomeações, e oferecê-la de novo convidaria a um par de
     * operações contraditórias que só falharia no banco.
     */
    public function testATargetIsOfferedOnlyOnce(): void
    {
        $from = Ir::schema([Ir::table('t', [
            Ir::id(),
            Ir::column('a', 'INT'),
            Ir::column('b', 'INT'),
        ])]);

        $to = Ir::schema([Ir::table('t', [
            Ir::id(),
            Ir::column('x', 'INT'),
            Ir::column('y', 'INT'),
        ])]);

        $prompter = new ScriptedPrompter(['x', 'y']);

        (new SchemaDiffer(new InteractiveRenameResolver($prompter)))->diff(
            $this->snapshot($from),
            $this->snapshot($to),
        );

        $this->assertSame([['x', 'y'], ['y']], $prompter->offered);
    }

    /**
     * Se o prompter devolver algo fora da lista, tratar como "nenhuma" é mais seguro
     * que aceitar: aceitar geraria um RenameColumn para uma coluna que não existe.
     */
    public function testAnAnswerOutsideTheOfferedOptionsIsIgnored(): void
    {
        $operations = (new SchemaDiffer(new InteractiveRenameResolver(new ScriptedPrompter(['inventado']))))
            ->diff($this->snapshot($this->city()), $this->snapshot($this->city('city', 'title')));

        $this->assertTrue($operations->hasDestructive());
    }

    /**
     * O snapshot vem antes do resolvedor, e o resolvedor só é chamado para o que sobra.
     */
    public function testTheSnapshotWinsOverTheResolver(): void
    {
        $prompter = new ScriptedPrompter([]);

        $operations = (new SchemaDiffer(new InteractiveRenameResolver($prompter)))->diff(
            $this->snapshot($this->city()),
            $this->snapshot($this->city('city', 'title'), new SnapshotMeta([], ['city.name' => 'title'])),
        );

        $this->assertOperationsAre(["renomeia a coluna 'city.name' para 'title'"], $operations);
        $this->assertSame([], $prompter->questions, 'não deveria ter perguntado nada');
    }

    // ----------------------------------------------------------- candidatas

    public function testCandidatesReportsTheAmbiguityForTheCommandToDecide(): void
    {
        $candidates = (new SchemaDiffer())->candidates(
            $this->snapshot($this->city()),
            $this->snapshot($this->city('city', 'title')),
        );

        $this->assertFalse($candidates->isEmpty());
        $this->assertSame(['city'], $candidates->ambiguousColumnTables());
        $this->assertSame(['name'], $candidates->columns['city']['removed']);
        $this->assertSame(['title'], $candidates->columns['city']['added']);
        $this->assertStringContainsString('--rename city.antiga:nova', $candidates->describe()[0]);
    }

    /**
     * Ambiguidade exige os dois lados. Só remoções é uma remoção, só criações é uma
     * criação, e nenhuma das duas precisa de pergunta.
     */
    public function testOnlyRemovalsOrOnlyAdditionsAreNotAmbiguous(): void
    {
        $withExtra = Ir::schema([Ir::table('city', [
            Ir::id(),
            Ir::column('name', 'VARCHAR', 120, notNull: true),
            Ir::column('ibge', 'INT'),
        ])]);

        $onlyRemoved = (new SchemaDiffer())->candidates(
            $this->snapshot($withExtra),
            $this->snapshot($this->city()),
        );
        $onlyAdded = (new SchemaDiffer())->candidates(
            $this->snapshot($this->city()),
            $this->snapshot($withExtra),
        );

        $this->assertTrue($onlyRemoved->isEmpty());
        $this->assertTrue($onlyAdded->isEmpty());
    }

    public function testAResolvedRenameIsNoLongerACandidate(): void
    {
        $candidates = (new SchemaDiffer())->candidates(
            $this->snapshot($this->city()),
            $this->snapshot($this->city('city', 'title'), new SnapshotMeta([], ['city.name' => 'title'])),
        );

        $this->assertTrue($candidates->isEmpty());
    }

    public function testTableAmbiguityIsReportedSeparately(): void
    {
        $candidates = (new SchemaDiffer())->candidates(
            $this->snapshot($this->city()),
            $this->snapshot($this->city('town')),
        );

        $this->assertTrue($candidates->hasTableAmbiguity());
        $this->assertSame(['city'], $candidates->removedTables);
        $this->assertSame(['town'], $candidates->addedTables);
        $this->assertStringContainsString('--rename antiga:nova', $candidates->describe()[0]);
    }
}
