Root de models só para `ModelSchemaLoaderTest`.

Precisa ser um diretório separado de `tests/App/Models/` porque `PATH_MODEL` é varrido
por INTEIRO: um model posto ali para exercitar o loader entraria também no schema dos
testes de ORM, e passaria a ser criado no banco de todo mundo.

Este arquivo `.md` também é fixture: prova que o loader ignora o que não é `.php` em vez
de tropeçar.
