<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Migrations\Exception;

/**
 * O arquivo `.sql` mudou depois de o banco já ter recebido parte dele.
 *
 * O hash guardado na tabela de controle não bate com o hash do arquivo em disco. Isso
 * significa que o banco recebeu um SQL e o repositório hoje contém outro — e como não há
 * migração `down`, não existe caminho automático de volta.
 *
 * Recusar é a única resposta honesta. Reaplicar seria executar o arquivo novo por cima de
 * um banco que já tem o antigo; ignorar seria deixar dois ambientes divergirem para
 * sempre acreditando que estão na mesma versão. O conserto é uma migração corretiva para
 * FRENTE, com o arquivo antigo restaurado.
 *
 * Duas situações produzem isto, e a mensagem distingue as duas porque o conserto difere:
 * a migração já ter sido aplicada por inteiro, e a migração ter parado no meio e o
 * arquivo ter sido editado antes da retomada.
 *
 * O hash normaliza fim de linha e espaço no fim, então um checkout com CRLF não dispara
 * isto: o que se detecta é edição de conteúdo.
 */
final class MigrationTamperedException extends MigrationException
{
    /**
     * @param bool $resumed a migração parou no meio e seria RETOMADA, em vez de já estar
     *                      aplicada por inteiro
     */
    public function __construct(
        public readonly string $tag,
        public readonly string $expectedHash,
        public readonly string $actualHash,
        public readonly bool $resumed = false,
        public readonly int $appliedIndex = 0,
    ) {
        parent::__construct($resumed
            ? self::resumedMessage($tag, $expectedHash, $actualHash, $appliedIndex)
            : self::appliedMessage($tag, $expectedHash, $actualHash));
    }

    private static function appliedMessage(string $tag, string $expected, string $actual): string
    {
        return "A migração '{$tag}' foi editada depois de aplicada.\n"
            . "  hash aplicado: {$expected}\n"
            . "  hash do arquivo: {$actual}\n"
            . 'Restaure o arquivo como ele foi aplicado e escreva uma migração nova com a correção. '
            . 'Não há down: editar uma migração já aplicada não desfaz o que o banco já tem.';
    }

    /**
     * A retomada é o caso mais perigoso dos dois, e o menos óbvio.
     *
     * A linha de controle diz de qual statement continuar, e esse índice foi contado no
     * arquivo ANTIGO. Retomar do mesmo índice num arquivo editado pula um pedaço que
     * nunca rodou e executa outro que talvez já tenha rodado — sem que nada no banco
     * registre a diferença.
     */
    private static function resumedMessage(
        string $tag,
        string $expected,
        string $actual,
        int $appliedIndex,
    ): string {
        return "A migração '{$tag}' parou no meio e o arquivo foi editado desde então.\n"
            . "  hash aplicado: {$expected}\n"
            . "  hash do arquivo: {$actual}\n"
            . "  statements já aplicados: {$appliedIndex}\n"
            . "Retomar executaria o arquivo novo a partir do índice {$appliedIndex}, que foi contado "
            . 'no arquivo antigo. Restaure o arquivo como ele estava para retomar; se o banco não '
            . 'chegou a receber nada, apague a linha desta tag na tabela de controle e rode de novo.';
    }
}
