## MODIFIED Requirements

### Requirement: Curadoria do tesauro de instituições
O sistema SHALL oferecer a administradores, em `/admin/institutions`, a lista (as primeiras 500 em ordem de nome oficial), o cadastro e a edição de nome oficial, nome curto, sigla, tipo e natureza jurídica, o acréscimo de uma variante na edição e a exclusão com token CSRF. Depois de cadastrar, editar, fundir, importar ou excluir, o índice de instituições usado na normalização MUST ser refeito.

#### Scenario: Exclusão de instituição
- **WHEN** uma instituição é excluída
- **THEN** as formações e atuações ligadas a ela ficam sem instituição

#### Scenario: Importação depois de uma exclusão
- **WHEN** uma instituição é excluída pelo painel e em seguida um currículo que a cita é importado
- **THEN** a normalização não tenta gravar o id excluído e a importação termina sem erro
