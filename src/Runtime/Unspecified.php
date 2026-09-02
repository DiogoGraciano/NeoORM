<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Runtime;

/**
 * "Não informado", distinto de NULL.
 *
 * Existe para um caso só, e um caso real: coluna **nullable com DEFAULT**. Ali
 * "insira NULL" e "deixe o banco aplicar o default" são resultados diferentes, e
 * `null` só consegue significar um dos dois. Sem o marcador, o payload de INSERT teria
 * que escolher qual dos dois é impossível de expressar.
 *
 * É `enum` e não classe singleton porque `Unspecified::Value` é expressão constante —
 * logo, válida como valor padrão de parâmetro, que é exatamente onde ela aparece no
 * DTO gerado. E o analisador estático estreita `instanceof Unspecified` sem ajuda.
 *
 * Nas outras combinações o marcador não aparece: coluna NOT NULL com default usa
 * `?T = null` (null = omite a coluna, e NULL seria ilegal mesmo), e coluna nullable sem
 * default também usa `?T = null` (omitir e gravar NULL dão no mesmo).
 */
enum Unspecified
{
    case Value;
}
