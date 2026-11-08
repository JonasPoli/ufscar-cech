## MODIFIED Requirements

### Requirement: Enriquecimento de orientação existente
Quando o docente já tem uma orientação do mesmo tipo (mestrado ou doutorado) que corresponde à obra pelo orientando ou pelo título, o sistema SHALL enriquecer essa orientação em vez de criar outra, preenchendo apenas os campos vazios do Repositório (link, Handle, UUID, data de defesa, programa, centro, campus, resumo, assuntos, DOI, ORCID do orientando, título alternativo). Situação e curso vindos do Lattes MUST ficar intactos.

#### Scenario: Critério de correspondência
- **WHEN** há mais de uma orientação candidata do mesmo tipo
- **THEN** vale a mais forte: orientando com o mesmo nome normalizado ou título idêntico; depois um título contido no outro (título da obra com mais de 15 caracteres); por último um nome contido no outro, com palavras inteiras e pelo menos dois nomes no menor
- **AND** uma orientação que já tem outro Handle ou UUID do Repositório nunca é usada

#### Scenario: Orientação do Lattes encontrada pelo orientando
- **WHEN** o Lattes do docente tem a dissertação de `Maria Silva` e o Repositório traz a dissertação de `Maria Silva`, com resumo e link
- **THEN** a orientação existente ganha o link, o resumo e os demais dados vazios, e é contada como enriquecida
- **AND** o link que a orientação já tinha não é trocado pelo do Repositório

#### Scenario: Orientação em andamento já defendida
- **WHEN** a orientação encontrada está em andamento no Lattes
- **THEN** a situação gravada continua "em andamento", mas a orientação passa a contar como concluída nas páginas e nos indicadores, porque tem o Handle do Repositório

#### Scenario: Programa do Repositório
- **WHEN** a orientação do Lattes é enriquecida com o programa "PPGE"
- **THEN** o curso do Lattes não muda, o programa fica guardado à parte e é exibido quando o Lattes não informa o curso

#### Scenario: Nome parecido de outro aluno
- **WHEN** o Lattes tem a dissertação de `Maria Silva` e o CSV traz, antes dela, a de `Maria Silva Santos`, com outro título
- **THEN** a dissertação de `Maria Silva` enriquece o registro do Lattes e a de `Maria Silva Santos` vira uma orientação nova
