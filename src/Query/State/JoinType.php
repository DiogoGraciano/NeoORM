<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Query\State;

/**
 * Os tipos de join que os dois bancos escrevem igual.
 *
 * `RIGHT` e `FULL` estão aqui porque são padrão; `CROSS` fica de fora porque não tem
 * cláusula `ON`, e representá-lo com o mesmo nó exigiria um `ON` opcional que só faz
 * sentido num caso — a estrutura passaria a admitir `INNER JOIN` sem condição, que é
 * um produto cartesiano acidental.
 */
enum JoinType: string
{
    case Inner = 'INNER JOIN';
    case Left = 'LEFT JOIN';
    case Right = 'RIGHT JOIN';
    case Full = 'FULL JOIN';
}
