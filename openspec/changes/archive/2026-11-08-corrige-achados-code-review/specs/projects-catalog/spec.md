## ADDED Requirements

### Requirement: Projetos sem título
Um projeto sem título (gravado como "Projeto sem título" pela importação por HTML) MUST NOT ser agrupado com outros: cada registro sem título é um item próprio do catálogo.

#### Scenario: Dois docentes com projeto sem título no mesmo ano
- **WHEN** dois docentes têm, cada um, um projeto sem título iniciado em 2019
- **THEN** o catálogo mostra dois itens, cada um com a equipe e os financiadores do seu registro
