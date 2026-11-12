## MODIFIED Requirements

### Requirement: Docentes em destaque
A página inicial SHALL listar 8 docentes, de qualquer vínculo, ordenados pelo número de artigos Qualis A1 declarados e, no empate, pelo total de produções e depois pela ordem de cadastro, cada um com foto ou iniciais, nome, selo "N A1" quando há artigos A1, departamento (ou "CECH / UFSCar") e período do vínculo quando informado, com link para o perfil. As duas contagens MUST ser feitas sem cruzar os artigos A1 com as demais produções do docente.

#### Scenario: Docente sem artigo A1
- **WHEN** um docente entra na lista sem nenhum artigo A1
- **THEN** o cartão dele não mostra o selo A1

#### Scenario: Contagens do destaque
- **WHEN** um docente tem 3 artigos A1 entre 40 produções declaradas
- **THEN** o selo mostra "3 A1" e a ordenação usa 3 artigos A1 e 40 produções

#### Scenario: Empate completo
- **WHEN** dois docentes têm o mesmo número de artigos A1 e o mesmo total de produções
- **THEN** aparece primeiro o que foi cadastrado antes, sempre na mesma ordem
