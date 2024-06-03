<?php
namespace app\db;

class agenda extends db{
    public function __construct(){
        parent::__construct("agenda");
    }

    public function get($value="",string $column="id",int $limit = 1){
        $retorno = false;

        if($limit){
            $this->addLimit($limit);
        }

        if ($value && in_array($column,$this->getColumns()))
            $retorno = $this->addFilter($column,"=",$value)->selectAll();
        
        if (is_array($retorno) && count($retorno) == 1)
            return $retorno[0];

        return $retorno?:$this->getObject();
    }

    public function getAll(){
        return $this->selectAll();
    }

}
class agendaServico extends db{
    public function __construct(){
        parent::__construct("agenda_servico");
    }

    public function get($value="",string $column="id",int $limit = 1){
        $retorno = false;

        if($limit){
            $this->addLimit($limit);
        }

        if ($value && in_array($column,$this->getColumns()))
            $retorno = $this->addFilter($column,"=",$value)->selectAll();
        
        if (is_array($retorno) && count($retorno) == 1)
            return $retorno[0];

        return $retorno?:$this->getObject();
    }

    public function getAll(){
        return $this->selectAll();
    }

}
class agendamento extends db{
    public function __construct(){
        parent::__construct("agendamento");
    }

    public function get($value="",string $column="id",int $limit = 1){
        $retorno = false;

        if($limit){
            $this->addLimit($limit);
        }

        if ($value && in_array($column,$this->getColumns()))
            $retorno = $this->addFilter($column,"=",$value)->selectAll();
        
        if (is_array($retorno) && count($retorno) == 1)
            return $retorno[0];

        return $retorno?:$this->getObject();
    }

    public function getAll(){
        return $this->selectAll();
    }

}
class agendamentoItem extends db{
    public function __construct(){
        parent::__construct("agendamento_item");
    }

    public function get($value="",string $column="id",int $limit = 1){
        $retorno = false;

        if($limit){
            $this->addLimit($limit);
        }

        if ($value && in_array($column,$this->getColumns()))
            $retorno = $this->addFilter($column,"=",$value)->selectAll();
        
        if (is_array($retorno) && count($retorno) == 1)
            return $retorno[0];

        return $retorno?:$this->getObject();
    }

    public function getAll(){
        return $this->selectAll();
    }

}
class agendaFuncionario extends db{
    public function __construct(){
        parent::__construct("agenda_funcionario");
    }

    public function get($value="",string $column="id",int $limit = 1){
        $retorno = false;

        if($limit){
            $this->addLimit($limit);
        }

        if ($value && in_array($column,$this->getColumns()))
            $retorno = $this->addFilter($column,"=",$value)->selectAll();
        
        if (is_array($retorno) && count($retorno) == 1)
            return $retorno[0];

        return $retorno?:$this->getObject();
    }

    public function getAll(){
        return $this->selectAll();
    }

}
class agendaUsuario extends db{
    public function __construct(){
        parent::__construct("agenda_usuario");
    }

    public function get($value="",string $column="id",int $limit = 1){
        $retorno = false;

        if($limit){
            $this->addLimit($limit);
        }

        if ($value && in_array($column,$this->getColumns()))
            $retorno = $this->addFilter($column,"=",$value)->selectAll();
        
        if (is_array($retorno) && count($retorno) == 1)
            return $retorno[0];

        return $retorno?:$this->getObject();
    }

    public function getAll(){
        return $this->selectAll();
    }

}
class cidade extends db{
    public function __construct(){
        parent::__construct("cidade");
    }

    public function get($value="",string $column="id",int $limit = 1){
        $retorno = false;

        if($limit){
            $this->addLimit($limit);
        }

        if ($value && in_array($column,$this->getColumns()))
            $retorno = $this->addFilter($column,"=",$value)->selectAll();
        
        if (is_array($retorno) && count($retorno) == 1)
            return $retorno[0];

        return $retorno?:$this->getObject();
    }

    public function getAll(){
        return $this->selectAll();
    }

}
class cliente extends db{
    public function __construct(){
        parent::__construct("cliente");
    }

    public function get($value="",string $column="id",int $limit = 1){
        $retorno = false;

        if($limit){
            $this->addLimit($limit);
        }

        if ($value && in_array($column,$this->getColumns()))
            $retorno = $this->addFilter($column,"=",$value)->selectAll();
        
        if (is_array($retorno) && count($retorno) == 1)
            return $retorno[0];

        return $retorno?:$this->getObject();
    }

    public function getAll(){
        return $this->selectAll();
    }

}
class empresa extends db{
    public function __construct(){
        parent::__construct("empresa");
    }

    public function get($value="",string $column="id",int $limit = 1){
        $retorno = false;

        if($limit){
            $this->addLimit($limit);
        }

        if ($value && in_array($column,$this->getColumns()))
            $retorno = $this->addFilter($column,"=",$value)->selectAll();
        
        if (is_array($retorno) && count($retorno) == 1)
            return $retorno[0];

        return $retorno?:$this->getObject();
    }

    public function getAll(){
        return $this->selectAll();
    }
 
}
class endereco extends db{
    public function __construct(){
        parent::__construct("endereco");
    }

    public function get($value="",string $column="id",int $limit = 1){
        $retorno = false;

        if($limit){
            $this->addLimit($limit);
        }

        if ($value && in_array($column,$this->getColumns()))
            $retorno = $this->addFilter($column,"=",$value)->selectAll();
        
        if (is_array($retorno) && count($retorno) == 1)
            return $retorno[0];

        return $retorno?:$this->getObject();
    }

    public function getAll(){
        return $this->selectAll();
    }

}
class estado extends db{
    public function __construct(){
        parent::__construct("estado");
    }

    public function get($value="",string $column="id",int $limit = 1){
        $retorno = false;

        if($limit){
            $this->addLimit($limit);
        }

        if ($value && in_array($column,$this->getColumns()))
            $retorno = $this->addFilter($column,"=",$value)->selectAll();
        
        if (is_array($retorno) && count($retorno) == 1)
            return $retorno[0];

        return $retorno?:$this->getObject();
    }

    public function getAll(){
        return $this->selectAll();
    }
  
}
class funcionario extends db{
    public function __construct(){
        parent::__construct("funcionario");
    }

    public function get($value="",string $column="id",int $limit = 1){
        $retorno = false;

        if($limit){
            $this->addLimit($limit);
        }

        if ($value && in_array($column,$this->getColumns()))
            $retorno = $this->addFilter($column,"=",$value)->selectAll();
        
        if (is_array($retorno) && count($retorno) == 1)
            return $retorno[0];

        return $retorno?:$this->getObject();
    }

    public function getAll(){
        return $this->selectAll();
    }
 
}
class funcionarioGrupoFuncionario extends db{
    public function __construct(){
        parent::__construct("funcionario_grupo_funcionario");
    }

    public function get($value="",string $column="id",int $limit = 1){
        $retorno = false;

        if($limit){
            $this->addLimit($limit);
        }

        if ($value && in_array($column,$this->getColumns()))
            $retorno = $this->addFilter($column,"=",$value)->selectAll();
        
        if (is_array($retorno) && count($retorno) == 1)
            return $retorno[0];

        return $retorno?:$this->getObject();
    }

    public function getAll(){
        return $this->selectAll();
    }

}
class grupoFuncionario extends db{
    public function __construct(){
        parent::__construct("grupo_funcionario");
    }

    public function get($value="",string $column="id",int $limit = 1){
        $retorno = false;

        if($limit){
            $this->addLimit($limit);
        }

        if ($value && in_array($column,$this->getColumns()))
            $retorno = $this->addFilter($column,"=",$value)->selectAll();
        
        if (is_array($retorno) && count($retorno) == 1)
            return $retorno[0];

        return $retorno?:$this->getObject();
    }

    public function getAll(){
        return $this->selectAll();
    }
 
}
class grupoServico extends db{
    public function __construct(){
        parent::__construct("grupo_servico");
    }

    public function get($value="",string $column="id",int $limit = 1){
        $retorno = false;

        if($limit){
            $this->addLimit($limit);
        }

        if ($value && in_array($column,$this->getColumns()))
            $retorno = $this->addFilter($column,"=",$value)->selectAll();
        
        if (is_array($retorno) && count($retorno) == 1)
            return $retorno[0];

        return $retorno?:$this->getObject();
    }

    public function getAll(){
        return $this->selectAll();
    }
 
}
class servico extends db{
    public function __construct(){
        parent::__construct("servico");
    }

    public function get($value="",string $column="id",int $limit = 1){
        $retorno = false;

        if($limit){
            $this->addLimit($limit);
        }

        if ($value && in_array($column,$this->getColumns()))
            $retorno = $this->addFilter($column,"=",$value)->selectAll();
        
        if (is_array($retorno) && count($retorno) == 1)
            return $retorno[0];

        return $retorno?:$this->getObject();
    }

    public function getAll(){
        return $this->selectAll();
    }

}
class status extends db{
    public function __construct(){
        parent::__construct("status");
    }

    public function get($value="",string $column="id",int $limit = 1){
        $retorno = false;

        if($limit){
            $this->addLimit($limit);
        }

        if ($value && in_array($column,$this->getColumns()))
            $retorno = $this->addFilter($column,"=",$value)->selectAll();
        
        if (is_array($retorno) && count($retorno) == 1)
            return $retorno[0];

        return $retorno?:$this->getObject();
    }

    public function getAll(){
        return $this->selectAll();
    }

}
class servicoFuncionario extends db{
    public function __construct(){
        parent::__construct("servico_funcionario");
    }

    public function get($value="",string $column="id",int $limit = 1){
        $retorno = false;

        if($limit){
            $this->addLimit($limit);
        }

        if ($value && in_array($column,$this->getColumns()))
            $retorno = $this->addFilter($column,"=",$value)->selectAll();
        
        if (is_array($retorno) && count($retorno) == 1)
            return $retorno[0];

        return $retorno?:$this->getObject();
    }

    public function getAll(){
        return $this->selectAll();
    }

}
class servicoGrupoServico extends db{
    public function __construct(){
        parent::__construct("servico_grupo_servico");
    }

    public function get($value="",string $column="id",int $limit = 1){
        $retorno = false;

        if($limit){
            $this->addLimit($limit);
        }

        if ($value && in_array($column,$this->getColumns()))
            $retorno = $this->addFilter($column,"=",$value)->selectAll();
        
        if (is_array($retorno) && count($retorno) == 1)
            return $retorno[0];

        return $retorno?:$this->getObject();
    }

    public function getAll(){
        return $this->selectAll();
    }

   
}
class usuario extends db{
    public function __construct(){
        parent::__construct("usuario");
    }

    public function get($value="",string $column="id",int $limit = 1){
        $retorno = false;

        if($limit){
            $this->addLimit($limit);
        }

        if ($value && in_array($column,$this->getColumns()))
            $retorno = $this->addFilter($column,"=",$value)->setDebug()->selectAll();
        
        if (is_array($retorno) && count($retorno) == 1)
            return $retorno[0];

        return $retorno?:$this->getObject();
    }

    public function getAll(){
        return $this->selectAll();
    }

}