<?php

declare(strict_types=1);

namespace Tests\App\SchemaModels\Loader;

/**
 * Um enum ao lado dos models.
 *
 * Não tem `table()` e portanto não descreve tabela nenhuma. O loader tem que ignorá-lo em
 * silêncio: um arquivo em `PATH_MODEL` que não é model não é erro, é rotina — enums,
 * traits, value objects e classes base moram lá o tempo todo.
 */
enum WidgetStatus: string
{
    case Active = 'active';
    case Retired = 'retired';
}
