## ADDED Requirements

### Requirement: Listas guardadas no cache da pesquisa
As listas de periódicos de cada base e das bases somadas, para cada área do filtro, SHALL ser calculadas uma vez e guardadas no cache da pesquisa por até um dia. Elas MUST ser recalculadas depois que o cache de páginas públicas é limpo, como ao fim do pipeline de periódicos.

#### Scenario: Visão geral e lista completa
- **WHEN** um visitante abre `/periodicos` e outro abre `/periodicos/scopus` no mesmo dia, ambas fora do cache de páginas
- **THEN** a lista da Scopus é calculada uma vez e as duas páginas mostram os mesmos periódicos e totais

#### Scenario: Pipeline publicado
- **WHEN** o pipeline de periódicos termina com o passo cache-clear
- **THEN** a próxima visita a `/periodicos` recalcula as listas com os vínculos novos
