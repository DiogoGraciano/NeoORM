<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Schema;

/**
 * O que toda definição nomeada de um schema tem em comum: um nome já normalizado
 * e uma forma serializável.
 *
 * Existe porque `TableDefinition` guarda quatro mapas de coisas nomeadas — únicos,
 * índices, foreign keys e checks — e os indexa e serializa com dois helpers
 * genéricos. Sem um contrato, o limite desses genéricos só podia ser `object`, e
 * `$item->name` passava a ser uma promessa de docblock que nada verificava: o dia
 * em que uma definição nova esquecesse a propriedade, o erro apareceria em runtime,
 * na serialização do snapshot, longe da causa.
 *
 * A propriedade é declarada com hook de leitura (PHP 8.4) em vez de um `getName()`:
 * as implementações são `final readonly` com `public string $name`, e um método
 * obrigaria a reescrever todas elas para expressar o que a propriedade já diz.
 */
interface NamedDefinition
{
    public string $name { get; }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array;
}
