# research-panorama Specification

## Purpose
Panorama de um recorte da produção dos docentes do CECH: totais sem repetição entre currículos, produção ao longo do tempo, tipos de publicação, assuntos em destaque e pontes de coautoria entre departamentos e entre grandes áreas. O mesmo bloco aparece nos Indicadores, nas páginas do Explorar e na página de cada departamento, e os números também são entregues pela API pública (`public-api`).

## Requirements
### Requirement: Publicação única
O sistema SHALL contar cada publicação uma única vez nos totais do panorama, mesmo quando ela está declarada em vários currículos. Duas declarações são a mesma publicação quando têm o mesmo DOI (sem espaços nas pontas e sem diferença de maiúsculas) ou, quando não há DOI, o mesmo tipo, o mesmo ano e o mesmo título (sem espaços nas pontas e sem diferença de maiúsculas).

#### Scenario: Mesmo DOI em dois currículos
- **WHEN** dois docentes do CECH declaram em seus currículos um artigo com o DOI `10.5555/abc`
- **THEN** o panorama conta uma publicação

#### Scenario: Sem DOI, mesmo tipo, ano e título
- **WHEN** dois currículos declaram um livro sem DOI com o mesmo título e o mesmo ano
- **THEN** o panorama conta uma publicação

#### Scenario: Mesmo título em anos diferentes
- **WHEN** dois currículos declaram, sem DOI, trabalhos do mesmo tipo e título, um em 2016 e outro em 2019
- **THEN** o panorama conta duas publicações

### Requirement: Recorte pelo filtro da pesquisa
O sistema SHALL calcular o panorama sobre as publicações que atendem ao filtro compartilhado da pesquisa (descrito em `expert-search`). As condições de texto, tema, tipo e período valem para a publicação; as de departamento, grande área, área de atuação e vínculo valem para o docente dono do currículo em que a publicação foi declarada. Sem nenhum filtro, o panorama MUST cobrir toda a produção do portal.

#### Scenario: Filtro de departamento
- **WHEN** o panorama é pedido com `departamento=ED`
- **THEN** entram só as publicações declaradas em currículos de docentes do departamento ED ou de uma grafia equivalente desse código

#### Scenario: Sem filtro
- **WHEN** o panorama é pedido sem parâmetros de filtro
- **THEN** os totais cobrem todas as publicações de todos os currículos do portal

### Requirement: Números do recorte
O sistema SHALL apresentar quatro números para o recorte: publicações únicas ("Sem repetição entre currículos"), docentes com produção (docentes distintos donos de currículos com publicação no recorte), publicações compartilhadas (publicações únicas que têm pelo menos dois docentes do CECH reconhecidos entre os autores) e pares de coautoria (pares distintos de docentes do CECH que publicam juntos no recorte).

#### Scenario: Dois docentes com duas obras em comum e um livro individual
- **WHEN** Ana e Bruno, do mesmo departamento, assinam juntos duas publicações e Ana tem ainda um livro só dela, e o panorama é filtrado por esse departamento
- **THEN** o panorama mostra 3 publicações, 2 docentes com produção, 2 publicações compartilhadas e 1 par de coautoria

#### Scenario: Mesmos números na API
- **WHEN** alguém consulta `/api/v1/metricas` (descrita em `public-api`) com o mesmo filtro
- **THEN** a resposta traz `publications`, `researchers`, `latestYear`, `sharedWorks`, `pairs`, `years`, `kinds`, `topics` e `bridges` com os valores do panorama

### Requirement: Produção ao longo do tempo
O sistema SHALL mostrar o número de publicações únicas por ano, do primeiro ao último ano com publicação no recorte, entre 1950 e o ano corrente, preenchendo com zero os anos intermediários sem publicação. Publicações sem ano ou fora desse intervalo MUST ficar fora da série. O gráfico de barras SHALL ter uma tabela alternativa ("Ver os dados em tabela") com os mesmos valores, do ano mais recente ao mais antigo.

#### Scenario: Anos com lacuna
- **WHEN** o recorte tem publicações apenas em 2016 e em 2019
- **THEN** a série vai de 2016 a 2019 e mostra zero em 2017 e em 2018

#### Scenario: Recorte sem publicações com ano
- **WHEN** nenhuma publicação do recorte tem ano válido
- **THEN** o bloco mostra "Nenhuma publicação com ano neste recorte." no lugar do gráfico

### Requirement: Tipos de publicação
O sistema SHALL listar, para o recorte, o número de publicações únicas de cada tipo de produção, com o nome legível do tipo, do tipo mais frequente ao menos frequente. O bloco MUST ficar oculto quando o recorte não tem publicações.

#### Scenario: Artigos e livros
- **WHEN** o recorte tem 5 artigos e 2 livros únicos
- **THEN** a lista mostra os artigos com 5 antes dos livros com 2

### Requirement: Assuntos em destaque
O sistema SHALL listar até 12 assuntos do índice temático, de origem palavra-chave ou híbrida e presentes na produção de pelo menos 2 docentes, ordenados pela soma das ocorrências na produção completa dos docentes do recorte (não só nas publicações que atendem ao filtro). Quando o recorte é um assunto, esse próprio assunto MUST ficar fora da lista. Cada assunto SHALL levar à sua página em `/explorar/assunto/{slug}`.

#### Scenario: Página de um assunto
- **WHEN** o panorama é do assunto "Educação"
- **THEN** "Educação" não aparece entre os assuntos em destaque

#### Scenario: Recorte sem docentes
- **WHEN** nenhum docente tem publicação no recorte
- **THEN** o bloco mostra "Sem assuntos indexados para este recorte."

#### Scenario: Sem filtro
- **WHEN** o panorama não tem filtro
- **THEN** os assuntos em destaque consideram a produção de todos os docentes

### Requirement: Pontes entre departamentos e entre grandes áreas
O sistema SHALL montar duas matrizes simétricas que contam pares de coautoria do recorte (não publicações) pelo grupo de cada docente: o departamento (`DCSo` lido como `CS`; sem departamento, "Outros") e a grande área predominante (a mais frequente nas áreas de atuação do docente; sem nenhuma, "Outros"). A diagonal MUST mostrar os pares dentro do mesmo grupo e um par entre grupos MUST somar nas duas células simétricas. Entram os grupos presentes em algum par, em ordem alfabética do código.

#### Scenario: Par dentro do mesmo departamento
- **WHEN** o único par do recorte é formado por dois docentes do mesmo departamento
- **THEN** a matriz de departamentos tem um grupo e o valor 1 na diagonal

#### Scenario: Par entre departamentos
- **WHEN** um docente de ED e um de PS publicam juntos
- **THEN** as células ED × PS e PS × ED valem 1 cada

#### Scenario: Rótulos legíveis
- **WHEN** a matriz de grandes áreas é exibida
- **THEN** as linhas trazem o nome completo da grande área, as colunas o nome curto, e cada célula informa os dois grupos e o número de pares
- **AND** cada matriz fica oculta quando o recorte não tem pares

### Requirement: Guarda dos resultados calculados
O sistema SHALL guardar o panorama calculado para cada filtro por até um dia e MUST descartar esses resultados quando o cache de páginas públicas é limpo.

#### Scenario: Dados reimportados
- **WHEN** currículos são reimportados e o cache de páginas públicas é limpo
- **THEN** o próximo panorama pedido é recalculado com os dados novos
