<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Migrations\Snapshot;

use Diogodg\Neoorm\Migrations\Exception\MigrationException;
use Diogodg\Neoorm\Schema\SchemaDefinition;
use JsonException;

/**
 * Snapshot para JSON e de volta, byte a byte.
 *
 * É aqui que sistemas desse tipo morrem. Se a serialização não for determinística,
 * cada `generate` cospe uma migração espúria: o schema não mudou, mas o arquivo
 * mudou, então o differ vê diferença onde não houve, e em duas semanas ninguém
 * confia mais no comando. As regras que impedem isso:
 *
 * 1. Todo mapa de dados é ordenado por chave (`ksort` com `SORT_STRING`) antes de
 *    serializar — tabelas, colunas, índices, uniques, FKs, checks. Cada nó do IR
 *    já faz isso no seu `toArray()`.
 * 2. As chaves da estrutura fixa são escritas em ordem alfabética à mão, não
 *    ordenadas em tempo de execução: uma ordem literal é mais determinística que
 *    uma ordenação, e legível no diff.
 * 3. `columnOrder` é a ÚNICA lista ordenada de nomes do arquivo, e o differ não a
 *    compara — ordem de coluna não vale um ALTER em nenhum dos dois bancos.
 * 4. Literais preservam o tipo nativo do JSON (`0`, não `"0"`); expressões vêm
 *    canonicalizadas por `DefaultValue`.
 * 5. Os flags de encoding são fixos, incluindo `JSON_PRESERVE_ZERO_FRACTION`, sem
 *    o qual um default `1.0` viraria `1` e o snapshot deixaria de bater com ele
 *    mesmo depois de uma volta pelo disco.
 *
 * O teste que sustenta tudo isso é `toJson(fromJson(toJson(x))) === toJson(x)`,
 * comparado byte a byte.
 */
final class SnapshotSerializer
{
    /**
     * Público porque o `journal.json` fica no mesmo diretório e é commitado do mesmo
     * jeito: dois arquivos vizinhos com regras de encoding diferentes seria uma
     * inconsistência que alguém acabaria "consertando" no lado errado.
     */
    public const ENCODE_FLAGS = JSON_PRETTY_PRINT
        | JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_PRESERVE_ZERO_FRACTION
        | JSON_THROW_ON_ERROR;

    /**
     * @return array<string,mixed>
     */
    public function toArray(Snapshot $snapshot): array
    {
        return [
            '_meta' => $snapshot->meta->toArray(),
            'dialect' => $snapshot->dialect,
            'id' => $snapshot->id,
            'prevId' => $snapshot->prevId,
            'tables' => $snapshot->schema->toArray(),
            'version' => $snapshot->version,
        ];
    }

    /**
     * @param array<string,mixed> $data
     */
    public function fromArray(array $data): Snapshot
    {
        $data = $this->upcast($data);

        foreach (['dialect', 'id', 'tables'] as $required) {
            if (!array_key_exists($required, $data)) {
                throw new MigrationException(
                    "Snapshot sem a chave obrigatória '{$required}'. O arquivo está truncado ou não é um snapshot.",
                );
            }
        }

        /** @var array<string,array<string,mixed>> $tables */
        $tables = (array) $data['tables'];
        /** @var array<string,mixed> $meta */
        $meta = (array) ($data['_meta'] ?? []);

        return new Snapshot(
            dialect: (string) $data['dialect'],
            id: (string) $data['id'],
            prevId: isset($data['prevId']) ? (string) $data['prevId'] : null,
            schema: SchemaDefinition::fromArray($tables),
            meta: SnapshotMeta::fromArray($meta),
            version: (int) ($data['version'] ?? Snapshot::FORMAT_VERSION),
        );
    }

    /**
     * Termina com exatamente uma quebra de linha.
     *
     * Sem ela, todo editor configurado para acrescentá-la produziria um arquivo
     * "modificado" logo depois do `generate`, e um `git diff` sujo é o começo de
     * alguém commitar um snapshot editado à mão.
     */
    public function toJson(Snapshot $snapshot): string
    {
        return json_encode($this->asJson($this->toArray($snapshot)), self::ENCODE_FLAGS) . "\n";
    }

    /**
     * Os campos do formato que são MAPAS, e por isso viram `{}` quando vazios.
     *
     * Sem esta lista, um array PHP vazio sai como `[]` e o tipo JSON de um campo
     * passa a depender de ele ter conteúdo: `"indexes": {...}` numa tabela com
     * índices e `"indexes": []` na tabela ao lado. Round-trip em PHP funciona nos
     * dois casos, mas um snapshot é lido por gente em code review e por ferramenta
     * de fora, e um campo que troca de tipo conforme o conteúdo é um campo que
     * ninguém consegue descrever. Acertar isto antes do primeiro snapshot
     * commitado é muito mais barato que depois.
     *
     * O `*` casa um nível qualquer. O que não está aqui é lista de verdade e
     * continua `[]`: `columnOrder`, `columns.*.type.values`, as listas de colunas de
     * índice, unique, FK e chave primária.
     */
    private const MAP_PATHS = [
        '_meta.renamedColumns',
        '_meta.renamedTables',
        'tables',
        'tables.*',
        'tables.*.checks',
        'tables.*.checks.*',
        'tables.*.columns',
        'tables.*.columns.*',
        'tables.*.columns.*.default',
        'tables.*.columns.*.type',
        'tables.*.foreignKeys',
        'tables.*.foreignKeys.*',
        'tables.*.indexes',
        'tables.*.indexes.*',
        'tables.*.options',
        'tables.*.primaryKey',
        'tables.*.uniqueConstraints',
        'tables.*.uniqueConstraints.*',
    ];

    /**
     * @param array<array-key,mixed> $data
     */
    private function asJson(array $data, string $path = ''): mixed
    {
        $converted = [];

        foreach ($data as $key => $value) {
            $childPath = $path === '' ? (string) $key : "{$path}.{$key}";
            $converted[$key] = is_array($value) ? $this->asJson($value, $childPath) : $value;
        }

        if ($converted === [] && self::isMapPath($path)) {
            return new \stdClass();
        }

        return $converted;
    }

    private static function isMapPath(string $path): bool
    {
        foreach (self::MAP_PATHS as $pattern) {
            if (self::pathMatches($pattern, $path)) {
                return true;
            }
        }

        return false;
    }

    private static function pathMatches(string $pattern, string $path): bool
    {
        $patternParts = explode('.', $pattern);
        $pathParts = explode('.', $path);

        if (count($patternParts) !== count($pathParts)) {
            return false;
        }

        foreach ($patternParts as $index => $part) {
            if ($part !== '*' && $part !== $pathParts[$index]) {
                return false;
            }
        }

        return true;
    }

    public function fromJson(string $json): Snapshot
    {
        try {
            /** @var array<string,mixed> $data */
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new MigrationException(
                'Snapshot não é JSON válido: ' . $e->getMessage()
                . '. Snapshots são gerados, não editados à mão.',
                previous: $e,
            );
        }

        if (!is_array($data)) {
            throw new MigrationException('Snapshot precisa ser um objeto JSON.');
        }

        return $this->fromArray($data);
    }

    /**
     * Traz um snapshot de formato antigo para o formato corrente.
     *
     * Hoje só existe a versão 1 e a função é identidade. Ela existe agora, e não
     * quando for necessária, porque o ponto de extensão precisa estar no caminho de
     * leitura desde o primeiro arquivo escrito — snapshots são artefatos
     * versionados no repositório, e um projeto com cem migrações no histórico não
     * pode exigir que todas sejam regeneradas para o formato mudar.
     *
     * Versão futura desconhecida é erro, não aviso: ler um arquivo de um formato que
     * esta versão não entende e adivinhar o resto produziria um diff plausível e
     * errado.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function upcast(array $data): array
    {
        $version = (int) ($data['version'] ?? Snapshot::FORMAT_VERSION);

        if ($version === Snapshot::FORMAT_VERSION) {
            return $data;
        }

        if ($version > Snapshot::FORMAT_VERSION) {
            throw new MigrationException(
                "Snapshot no formato {$version}, mais novo que o formato "
                . Snapshot::FORMAT_VERSION . ' que esta versão da NeoORM entende. Atualize a biblioteca.',
            );
        }

        throw new MigrationException(
            "Snapshot no formato {$version}, que não tem caminho de conversão para o formato "
            . Snapshot::FORMAT_VERSION . '.',
        );
    }
}
