<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Codegen;

/**
 * O tipo PHP de uma coluna, nas quatro formas em que o gerador precisa dele.
 *
 * Separar `native` de `docblock` é o que permite ser preciso sem inventar tipo: a
 * assinatura declara `string`, que é o que o PHP entende, e o docblock declara
 * `numeric-string`, que é o que o analisador estático entende. Uma coisa é o
 * contrato de runtime, outra é a informação que o PHPStan usa.
 */
final readonly class PhpType
{
    /**
     * @param string $base tipo nativo sem nulabilidade: 'int', 'string', 'DateTimeImmutable', 'mixed'
     * @param string $baseDocblock refinamento para o analisador: 'numeric-string', 'int<0, max>'
     * @param string|null $import FQCN a importar, quando o tipo não é escalar
     * @param string $caster método de `Runtime\Casting\Cast` que converte o valor lido
     */
    public function __construct(
        public string $base,
        public string $baseDocblock,
        public bool $nullable = false,
        public ?string $import = null,
        public string $caster = 'string',
    ) {
    }

    /**
     * O tipo como vai na assinatura.
     *
     * `mixed` nunca ganha `?`: `?mixed` é erro de parse, e `mixed` já contém null.
     * É o caso das colunas JSON, e o ponto onde uma concatenação ingênua de `'?'`
     * geraria arquivo que não compila.
     */
    public function native(): string
    {
        if ($this->base === 'mixed') {
            return 'mixed';
        }

        return ($this->nullable ? '?' : '') . $this->base;
    }

    public function docblock(): string
    {
        if ($this->baseDocblock === 'mixed') {
            return 'mixed';
        }

        return $this->nullable ? $this->baseDocblock . '|null' : $this->baseDocblock;
    }

    /**
     * O método de `Cast` a chamar. A nulabilidade decide qual variante, e não um
     * `if` dentro do caster — assim o tipo de retorno é exato dos dois lados.
     */
    public function castMethod(): string
    {
        return $this->nullable ? 'nullable' . ucfirst($this->caster) : $this->caster;
    }

    public function asNullable(bool $nullable = true): self
    {
        return new self($this->base, $this->baseDocblock, $nullable, $this->import, $this->caster);
    }

    /**
     * Troca o tipo por uma classe — o caso do enum gerado, cujo nome só o emissor
     * conhece, porque depende do nome da tabela e da coluna.
     */
    public function asClass(string $fqcn, string $caster): self
    {
        $short = ($position = strrpos($fqcn, '\\')) === false ? $fqcn : substr($fqcn, $position + 1);

        return new self($short, $short, $this->nullable, $fqcn, $caster);
    }
}
