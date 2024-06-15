<?php
namespace app\db;

class agenda extends tableClassAbstract{
    public function __construct(){
        parent::__construct("agenda");
    }
}
class agendaServico extends tableClassAbstract{
    public function __construct(){
        parent::__construct("agenda_servico");
    }
}
class agendamento extends tableClassAbstract{
    public function __construct(){
        parent::__construct("agendamento");
    }
}
class agendamentoItem extends tableClassAbstract{
    public function __construct(){
        parent::__construct("agendamento_item");
    }
}
class agendaFuncionario extends tableClassAbstract{
    public function __construct(){
        parent::__construct("agenda_funcionario");
    }
}
class agendaUsuario extends tableClassAbstract{
    public function __construct(){
        parent::__construct("agenda_usuario");
    }
}
class cidade extends tableClassAbstract{
    public function __construct(){
        parent::__construct("cidade");
    }
}
class cliente extends tableClassAbstract{
    public function __construct(){
        parent::__construct("cliente");
    }
}
class empresa extends tableClassAbstract{
    public function __construct(){
        parent::__construct("empresa");
    }
}
class endereco extends tableClassAbstract{
    public function __construct(){
        parent::__construct("endereco");
    }
}
class estado extends tableClassAbstract{
    public function __construct(){
        parent::__construct("estado");
    }
}
class funcionario extends tableClassAbstract{
    public function __construct(){
        parent::__construct("funcionario");
    }
}
class funcionarioGrupoFuncionario extends tableClassAbstract{
    public function __construct(){
        parent::__construct("funcionario_grupo_funcionario");
    }
}
class grupoFuncionario extends tableClassAbstract{
    public function __construct(){
        parent::__construct("grupo_funcionario");
    }
}
class grupoServico extends tableClassAbstract{
    public function __construct(){
        parent::__construct("grupo_servico");
    }
}
class servico extends tableClassAbstract{
    public function __construct(){
        parent::__construct("servico");
    }
}
class status extends tableClassAbstract{
    public function __construct(){
        parent::__construct("status");
    }
}
class servicoFuncionario extends tableClassAbstract{
    public function __construct(){
        parent::__construct("servico_funcionario");
    }
}
class servicoGrupoServico extends tableClassAbstract{
    public function __construct(){
        parent::__construct("servico_grupo_servico");
    }
}
class usuario extends tableClassAbstract{
    public function __construct(){
        parent::__construct("usuario");
    } 
}