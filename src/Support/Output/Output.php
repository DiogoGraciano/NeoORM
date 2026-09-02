<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Support\Output;

/**
 * Para onde vai o texto que um comando quer mostrar.
 *
 * Existe porque o `Migrate` antigo dava `echo` de seis lugares diferentes, e isso
 * fazia duas coisas ao mesmo tempo: tornava o comando impossível de testar sem
 * capturar saída, e impossível de usar fora de um terminal — num job, num deploy,
 * numa rota administrativa.
 *
 * O default na biblioteca é `NullOutput`. Só o entrypoint de CLI instala o
 * `EchoOutput`.
 */
interface Output
{
    public function write(string $message): void;

    public function success(string $message): void;

    public function warning(string $message): void;

    public function error(string $message): void;
}
