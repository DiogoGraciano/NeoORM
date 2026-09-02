<?php

declare(strict_types=1);

namespace Tests\Unit\Schema;

use Diogodg\Neoorm\Schema\Exception\InvalidIdentifierException;
use Diogodg\Neoorm\Schema\Naming\ConstraintNamer;
use Diogodg\Neoorm\Schema\Naming\IdentifierValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\UnitTestCase;

final class NamingTest extends UnitTestCase
{
    #[DataProvider('validIdentifiers')]
    public function testValidIdentifiersAreAccepted(string $identifier): void
    {
        $this->assertTrue(IdentifierValidator::isValid($identifier));
    }

    public static function validIdentifiers(): iterable
    {
        yield 'simples' => ['users'];
        yield 'com underscore' => ['schedule_employee'];
        yield 'começando com underscore' => ['_neoorm_migrations'];
        yield 'com dígitos' => ['tabela2'];
        yield 'maiúsculas' => ['MinhaTabela'];
    }

    /**
     * Nomes entram em DDL, onde não há como parametrizar. Esta lista é o que
     * separa configuração de injeção.
     */
    #[DataProvider('invalidIdentifiers')]
    public function testDangerousIdentifiersAreRejected(string $identifier): void
    {
        $this->assertFalse(IdentifierValidator::isValid($identifier));

        $this->expectException(InvalidIdentifierException::class);
        IdentifierValidator::normalize($identifier);
    }

    public static function invalidIdentifiers(): iterable
    {
        yield 'vazio' => [''];
        yield 'com espaço' => ['minha tabela'];
        yield 'com ponto e vírgula' => ['users; DROP TABLE x'];
        yield 'com aspas' => ['"users"'];
        yield 'com crase' => ['`users`'];
        yield 'com hífen' => ['user-name'];
        yield 'começando com dígito' => ['2fa'];
        yield 'com parêntese' => ['count(*)'];
        yield 'com acento' => ['usuário'];
        yield 'com ponto' => ['schema.tabela'];
    }

    /**
     * O PostgreSQL dobra identificadores não citados para minúsculas. Fixar
     * minúsculas no IR é o que faz o round-trip fechar nos dois bancos.
     */
    public function testNormalizationLowercasesAndTrims(): void
    {
        $this->assertSame('users', IdentifierValidator::normalize('  Users  '));
        $this->assertSame('minhatabela', IdentifierValidator::normalize('MinhaTabela'));
    }

    public function testTheErrorMessageNamesTheContextAndTheOffendingValue(): void
    {
        try {
            IdentifierValidator::normalize('minha tabela', 'Nome de tabela');
            $this->fail('deveria ter recusado');
        } catch (InvalidIdentifierException $e) {
            $this->assertStringContainsString('Nome de tabela', $e->getMessage());
            $this->assertStringContainsString('minha tabela', $e->getMessage());
        }
    }

    public function testGeneratedNamesFollowTheDocumentedShape(): void
    {
        $this->assertSame('city_pk', ConstraintNamer::primaryKey('city'));
        $this->assertSame('city_ibge_unique', ConstraintNamer::unique('city', ['ibge']));
        $this->assertSame('city_state_index', ConstraintNamer::index('city', ['state']));
        $this->assertSame('city_a_b_unique_index', ConstraintNamer::index('city', ['a', 'b'], true));
        $this->assertSame(
            'city_state_state_id_fk',
            ConstraintNamer::foreignKey('city', ['state'], 'state', ['id']),
        );
    }

    public function testNameGenerationIsDeterministic(): void
    {
        // Gerar nomes ad hoc em cada caminho de código era o que fazia o mesmo
        // schema produzir snapshots diferentes.
        $this->assertSame(
            ConstraintNamer::foreignKey('city', ['state'], 'state', ['id']),
            ConstraintNamer::foreignKey('city', ['state'], 'state', ['id']),
        );
    }

    public function testNamesWithinTheLimitAreLeftUntouched(): void
    {
        $name = str_repeat('a', ConstraintNamer::MAX_LENGTH);

        $this->assertSame($name, ConstraintNamer::truncate($name));
    }

    public function testLongNamesAreTruncatedToTheLimit(): void
    {
        $name = str_repeat('a', 200);

        $this->assertSame(ConstraintNamer::MAX_LENGTH, strlen(ConstraintNamer::truncate($name)));
    }

    /**
     * O ponto do hash. Truncar sem ele faria duas FKs de nomes longos que só
     * diferem no fim colapsarem no mesmo identificador — e a segunda falharia
     * no banco com "constraint já existe", ou pior, sobrescreveria a primeira.
     */
    public function testLongNamesThatShareAPrefixStayDistinctAfterTruncation(): void
    {
        $prefix = str_repeat('a', 70);

        $first = ConstraintNamer::truncate($prefix . '_primeira_coluna');
        $second = ConstraintNamer::truncate($prefix . '_segunda_coluna');

        $this->assertNotSame($first, $second);
        $this->assertSame(ConstraintNamer::MAX_LENGTH, strlen($first));
        $this->assertSame(ConstraintNamer::MAX_LENGTH, strlen($second));
    }

    public function testTruncationIsStableAcrossCalls(): void
    {
        $name = str_repeat('coluna_muito_longa_', 10);

        $this->assertSame(ConstraintNamer::truncate($name), ConstraintNamer::truncate($name));
    }

    public function testEveryGeneratedNameRespectsTheLimitEvenForLongInputs(): void
    {
        $longTable = str_repeat('t', 60);
        $longColumn = str_repeat('c', 60);

        $names = [
            ConstraintNamer::primaryKey($longTable),
            ConstraintNamer::unique($longTable, [$longColumn]),
            ConstraintNamer::index($longTable, [$longColumn]),
            ConstraintNamer::foreignKey($longTable, [$longColumn], $longTable, [$longColumn]),
            ConstraintNamer::check($longTable, 'x > 0'),
        ];

        foreach ($names as $name) {
            $this->assertLessThanOrEqual(ConstraintNamer::MAX_LENGTH, strlen($name), $name);
        }
    }

    /**
     * O limite é o menor dos dois bancos. Usar o do MySQL (64) faria o
     * PostgreSQL (63) truncar por conta própria, em silêncio — e o nome no
     * snapshot deixaria de bater com o nome no banco.
     */
    public function testTheLimitIsTheStricterOfTheTwoEngines(): void
    {
        $this->assertSame(63, ConstraintNamer::MAX_LENGTH);
    }
}
