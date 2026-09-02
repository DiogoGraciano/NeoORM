<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Codegen;

/**
 * As escolhas do projeto sobre o código gerado.
 *
 * Não vem do `.env`: são constantes do projeto, não do ambiente. Trocar
 * `decimalAsFloat` entre desenvolvimento e produção geraria DTOs diferentes para o
 * mesmo schema — e como os arquivos são commitados, a divergência apareceria como
 * diff em quem rodasse o gerador na outra máquina.
 *
 * Os padrões são os corretos; cada flag existe como via de saída para quem tem um
 * motivo, não como sugestão de configuração.
 */
final readonly class GeneratorOptions
{
    /**
     * @param bool $decimalAsFloat DECIMAL como float. Conveniente para cálculo direto,
     *        perde precisão acima do que float representa — DECIMAL(19,4) de dinheiro
     *        é o caso clássico.
     * @param bool $temporalAsString datas como string, sem conversão. Faz sentido em
     *        ETL de volume, onde o custo de construir um DateTimeImmutable por linha
     *        pesa e ninguém vai olhar a data.
     * @param bool $bigIntAsString BIGINT como numeric-string. Necessário só quando os
     *        ids passam de PHP_INT_MAX, caso em que o mysqlnd já devolve string e o
     *        tipo `int` seria mentira.
     */
    public function __construct(
        public bool $decimalAsFloat = false,
        public bool $temporalAsString = false,
        public bool $bigIntAsString = false,
    ) {
    }
}
