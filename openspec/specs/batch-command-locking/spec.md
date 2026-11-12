# batch-command-locking Specification

## Purpose
Impedir que dois processos do mesmo comando de escrita em lote rodem ao mesmo tempo no servidor, o que gravaria dados pela metade ou em duplicidade.

## Requirements

### Requirement: Execução única dos comandos de escrita em lote
Os comandos `app:import:lattes`, `app:import:repository`, `app:index:topics`, `app:curriculum:normalize`, `app:thesaurus:sync-authors`, `app:journals:pipeline`, `app:database:restore`, `app:index:projects` e `app:cache:warm` MUST NOT rodar em duplicidade no mesmo servidor. Enquanto um deles roda, uma segunda execução do mesmo comando SHALL sair na hora, sem alterar o banco, avisando que o comando já está em execução.

#### Scenario: Importação disparada duas vezes
- **WHEN** `app:import:lattes` está rodando e o administrador o inicia de novo em outro terminal
- **THEN** a segunda execução avisa que o comando já está em execução e termina sem importar nada

#### Scenario: Aquecimento disparado duas vezes
- **WHEN** `app:cache:warm` está rodando e o administrador o inicia de novo
- **THEN** a segunda execução avisa que o comando já está em execução, sem pedir nenhuma página, e sai com falha

#### Scenario: Comandos diferentes
- **WHEN** `app:index:topics` está rodando e o administrador inicia `app:index:projects`
- **THEN** `app:index:projects` roda normalmente

### Requirement: Liberação da trava
A trava SHALL ser liberada quando a execução termina, com sucesso, com erro ou com o processo interrompido, para que a próxima execução rode sem intervenção manual.

#### Scenario: Execução que falhou
- **WHEN** `app:journals:pipeline` termina com erro num passo
- **THEN** uma nova execução do pipeline começa normalmente

#### Scenario: Processo interrompido
- **WHEN** `app:curriculum:normalize` é interrompido com Ctrl+C
- **THEN** a próxima execução não encontra o comando como em execução
