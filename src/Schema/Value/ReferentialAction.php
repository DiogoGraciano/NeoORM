<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Schema\Value;

use Diogodg\Neoorm\Schema\Exception\SchemaException;

/**
 * Ação referencial de uma foreign key.
 *
 * O ponto delicado é canonical(): MySQL reporta uma regra omitida como
 * RESTRICT, PostgreSQL reporta como NO ACTION. Os dois significam a mesma coisa
 * para constraints não postergáveis. Guardá-los como valores distintos faria
 * toda FK sem regra explícita parecer alterada em todo `check`, para sempre —
 * e o default do DSL dos models é justamente "RESTRICT".
 */
enum ReferentialAction: string
{
    case NoAction = 'NO ACTION';
    case Cascade = 'CASCADE';
    case SetNull = 'SET NULL';
    case SetDefault = 'SET DEFAULT';

    /**
     * Interpreta a grafia vinda do DSL ou do catálogo, já dobrando RESTRICT em
     * NO ACTION.
     */
    public static function canonical(string $raw): self
    {
        $normalized = strtoupper(trim((string) preg_replace('/\s+/', ' ', $raw)));

        return match ($normalized) {
            'CASCADE' => self::Cascade,
            'SET NULL', 'SETNULL' => self::SetNull,
            'SET DEFAULT', 'SETDEFAULT' => self::SetDefault,
            'RESTRICT', 'NO ACTION', 'NOACTION', '' => self::NoAction,
            default => throw new SchemaException("Ação referencial inválida: '{$raw}'"),
        };
    }

    /**
     * Se a ação precisa ser escrita no SQL. NO ACTION é o padrão dos dois
     * bancos, então omiti-la mantém o DDL gerado mais próximo do que a
     * introspecção devolve.
     */
    public function isDefault(): bool
    {
        return $this === self::NoAction;
    }
}
