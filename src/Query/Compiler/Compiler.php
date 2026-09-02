<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Query\Compiler;

use Diogodg\Neoorm\Dialect\Dialect;
use Diogodg\Neoorm\Query\State\DeleteState;
use Diogodg\Neoorm\Query\State\InsertState;
use Diogodg\Neoorm\Query\State\SelectState;
use Diogodg\Neoorm\Query\State\UpdateState;

/**
 * A porta de entrada da compilação: estado + dialeto entram, SQL e binds saem.
 *
 * Sem PDO em lugar nenhum deste pacote. É o que cumpre a restrição central do projeto —
 * a suíte unitária compila SQL para os dois bancos sem nenhum servidor no ar, e o
 * `UnitTestCase` prova isso reprovando qualquer teste que abra conexão.
 */
final class Compiler
{
    public function __construct(
        private readonly SelectCompiler $select = new SelectCompiler(),
        private readonly InsertCompiler $insert = new InsertCompiler(),
        private readonly UpdateCompiler $update = new UpdateCompiler(),
        private readonly DeleteCompiler $delete = new DeleteCompiler(),
        private readonly CountCompiler $count = new CountCompiler(),
    ) {
    }

    public function compileSelect(SelectState $state, Dialect $dialect): CompiledQuery
    {
        return $this->select->compile($state, $dialect);
    }

    /**
     * Quantas linhas o SELECT devolveria — que não é o mesmo que trocar as colunas por
     * `COUNT(*)` quando há `GROUP BY` ou `DISTINCT`. Ver {@see CountCompiler}.
     */
    public function compileCount(SelectState $state, Dialect $dialect): CompiledQuery
    {
        return $this->count->compile($state, $dialect);
    }

    public function compileInsert(InsertState $state, Dialect $dialect): CompiledQuery
    {
        return $this->insert->compile($state, $dialect);
    }

    public function compileUpdate(UpdateState $state, Dialect $dialect): CompiledQuery
    {
        return $this->update->compile($state, $dialect);
    }

    public function compileDelete(DeleteState $state, Dialect $dialect): CompiledQuery
    {
        return $this->delete->compile($state, $dialect);
    }
}
