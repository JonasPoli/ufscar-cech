## MODIFIED Requirements

### Requirement: Publicações recentes
A página inicial SHALL listar os 10 registros de produção mais recentes, do ano mais novo para o mais antigo e, no mesmo ano, do registro gravado por último, cada um com tipo, ano, selo do periódico ou Qualis quando houver, título, veículo, docente dono do currículo (com link para o perfil) e link para o DOI em nova aba quando houver. O link "Ver todas" SHALL levar à busca no modo Publicações com o período a partir do ano anterior, ordenada das mais recentes.

#### Scenario: Publicação com DOI
- **WHEN** um dos registros recentes tem DOI
- **THEN** o item mostra um link para o DOI que abre em nova aba

#### Scenario: Ver todas
- **WHEN** em 2026 o visitante clica em "Ver todas"
- **THEN** abre `/busca?modo=publicacoes&de=2025`, com a lista de publicações, e não a capa da busca
