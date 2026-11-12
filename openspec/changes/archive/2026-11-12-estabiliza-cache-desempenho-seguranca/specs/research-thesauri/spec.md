## ADDED Requirements

### Requirement: Chaves estrangeiras religadas depois da limpeza do tesauro
Ao apagar o tesauro de autores (`app:thesaurus:sync-authors --clean`), o sistema SHALL religar a verificação de chaves estrangeiras da conexão ao fim da limpeza, inclusive quando um dos comandos da limpeza falha. A falha SHALL ser informada.

#### Scenario: Falha no meio da limpeza
- **WHEN** um dos comandos que apagam o tesauro de autores falha
- **THEN** a verificação de chaves estrangeiras volta a valer na conexão e o comando informa o erro

#### Scenario: Limpeza concluída
- **WHEN** `--clean` é confirmado e a limpeza termina sem erro
- **THEN** a verificação de chaves estrangeiras está ligada quando a sincronização começa
