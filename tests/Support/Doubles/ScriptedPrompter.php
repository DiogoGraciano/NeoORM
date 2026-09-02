<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

use Diogodg\Neoorm\Migrations\Diff\Prompter;

/**
 * Respostas prontas, e o registro do que foi perguntado.
 *
 * Guardar as perguntas e as opções oferecidas é o que permite afirmar coisas que só
 * se veem do lado de fora: que a pergunta é sobre o que desapareceu (e não sobre o
 * que apareceu), e que um destino já escolhido não é oferecido de novo.
 */
final class ScriptedPrompter implements Prompter
{
    /** @var list<string> */
    public array $questions = [];

    /** @var list<list<string>> */
    public array $offered = [];

    /**
     * @param list<string|null|bool> $answers consumidas em ordem; null significa "nenhuma"
     */
    public function __construct(private array $answers = [])
    {
    }

    public function choose(string $question, array $options): ?string
    {
        $this->questions[] = $question;
        $this->offered[] = array_values($options);

        $answer = array_shift($this->answers);

        return is_string($answer) ? $answer : null;
    }

    public function confirm(string $question, bool $default = false): bool
    {
        $this->questions[] = $question;

        $answer = array_shift($this->answers);

        return is_bool($answer) ? $answer : $default;
    }
}
