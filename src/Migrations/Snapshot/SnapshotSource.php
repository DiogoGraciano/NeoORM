<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Migrations\Snapshot;

/**
 * De onde este snapshot veio.
 *
 * Não é metadado decorativo: as duas origens têm FIDELIDADE diferente, e há uma
 * comparação que só é confiável entre dois snapshots declarados.
 *
 * O caso é a expressão de um CHECK. O PostgreSQL a reescreve ao armazená-la:
 * `status in ('novo','usado')` volta do catálogo como
 * `((status)::text = ANY ((ARRAY['novo'::character varying, ...])::text[]))`. Não é
 * formatação — é uma reescrita semântica que nenhum normalizador razoável reverte. Entre
 * dois arquivos de snapshot, as duas expressões vieram do mesmo lugar e comparar texto
 * normalizado funciona; contra um banco, comparar acusaria diferença em toda execução.
 *
 * Por isso o differ trata divergência de EXPRESSÃO de CHECK como advisória quando um dos
 * lados foi introspectado — e só a divergência de expressão. Um CHECK que existe de um
 * lado e não do outro é adição ou remoção de verdade, e continua gerando DDL: suprimir
 * isso também custaria a `db:push` a capacidade de criar restrições CHECK.
 */
enum SnapshotSource: string
{
    /** Veio dos models ou de um arquivo de snapshot. Fidelidade total. */
    case Declared = 'declared';

    /** Veio do catálogo de um banco. Expressões de CHECK podem estar reescritas. */
    case Introspected = 'introspected';
}
