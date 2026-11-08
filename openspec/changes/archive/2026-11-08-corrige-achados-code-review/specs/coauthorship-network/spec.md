## MODIFIED Requirements

### Requirement: Publicações de um par
As publicações de um par (entregues por `GET /api/v1/grafo/par`, descrita em `public-api`) SHALL ser as publicações únicas do recorte em que os dois docentes aparecem como autores: o total real e até 200 delas, da mais recente para a mais antiga, cada uma com título, ano, tipo, veículo, ISSN, DOI e até 10 autores (os do CECH com link para o perfil; acima de 10, "et al."), junto com os dados dos dois docentes.

#### Scenario: Par com duas publicações
- **WHEN** alguém pede as publicações de Ana e Bruno, que têm um trabalho de 2016 e outro de 2019 em comum
- **THEN** a resposta traz `count` igual a 2 e o trabalho de 2019 em primeiro lugar

#### Scenario: Par num recorte
- **WHEN** as publicações de Ana e Bruno são pedidas com `departamento=ED`
- **THEN** entram só as publicações em comum declaradas em currículos de docentes de ED

#### Scenario: Par com mais publicações do que a lista
- **WHEN** um par tem 260 publicações em comum
- **THEN** a lista traz 200 e `count` é 260
