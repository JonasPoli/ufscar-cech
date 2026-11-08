# public-api Specification

## Purpose
Oferecer em `/api/v1` uma API JSON pública e somente leitura da pesquisa do CECH, com os mesmos filtros das páginas públicas e sem expor nada além do que essas páginas já mostram.

## Requirements
### Requirement: Índice da API
O sistema SHALL responder em `GET /api/v1` com o nome da API, a versão `1`, a lista de filtros aceitos (`q`, `exata`, `departamento`, `tema`, `grande_area`, `area`, `vinculo`, `tipo`, `de`, `ate`) e os endereços dos recursos (catálogo, busca, publicações, métricas, grafo, par do grafo, docente e projetos).

#### Scenario: Descoberta dos recursos
- **WHEN** um cliente consulta `GET /api/v1`
- **THEN** recebe JSON com `name`, `version`, `filters` e `endpoints`

### Requirement: Respostas públicas em cache
O sistema SHALL responder os recursos de dados da API apenas a `GET`, com cabeçalho de cache público de uma hora (`max-age=3600`), e SHALL descartar um `tema` desconhecido em vez de recusar a requisição.

#### Scenario: Cache das respostas
- **WHEN** um cliente consulta `GET /api/v1/catalogo`
- **THEN** a resposta é pública e pode ser guardada por 3600 segundos

#### Scenario: Tema inexistente
- **WHEN** a requisição traz um `tema` cujo slug não existe
- **THEN** o filtro de tema é ignorado e a resposta é calculada sem ele

### Requirement: Catálogo de filtros
O sistema SHALL responder em `GET /api/v1/catalogo` com os departamentos, as grandes áreas, as áreas, os temas, os tipos de produção e as situações de vínculo que podem ser usados como filtro.

#### Scenario: Valores para montar filtros
- **WHEN** um cliente consulta o catálogo
- **THEN** recebe `departments`, `majorAreas`, `areas`, `topics`, `types` e `affiliations`

### Requirement: Busca, publicações e métricas
O sistema SHALL oferecer `GET /api/v1/busca` nos modos `especialistas` (padrão), `publicacoes` e `pesquisador`, com `pagina` e `ordem` (padrão `relevancia`), `GET /api/v1/publicacoes` (ordem padrão `recentes`) e `GET /api/v1/metricas`, todos com os filtros da API e com o filtro aplicado repetido na resposta.

#### Scenario: Busca sem filtro
- **WHEN** `GET /api/v1/busca` é chamado sem termo e sem nenhum filtro
- **THEN** a resposta tem status 400 com `error: "Informe ao menos um termo (q) ou um filtro."`

#### Scenario: Busca de especialistas
- **WHEN** `GET /api/v1/busca?q=educação` é chamado sem `modo`
- **THEN** a resposta traz `mode: "especialistas"`, o `filter` aplicado e os resultados da busca de especialistas

### Requirement: Grafo de coautoria
O sistema SHALL oferecer `GET /api/v1/grafo`, com os filtros da API, foco opcional num docente (`docente`, por slug, ID Lattes ou ID interno) e limite de ligações (`ligacoes`, padrão 120, de 0 a 5000, em que 0 significa todas), e `GET /api/v1/grafo/par?a=<id>&b=<id>`, com as obras em comum de dois docentes.

#### Scenario: Docente do foco inexistente
- **WHEN** `docente` não corresponde a nenhum docente
- **THEN** a resposta tem status 404 com `error: "Docente não encontrado."`

#### Scenario: Limite acima do máximo
- **WHEN** `ligacoes=9000`
- **THEN** o grafo é limitado a 5000 ligações

#### Scenario: Par inválido
- **WHEN** `a` ou `b` falta, não é positivo ou os dois são iguais
- **THEN** a resposta tem status 400 com `error: "Informe dois docentes diferentes (a e b)."`

### Requirement: Ficha do docente
O sistema SHALL responder em `GET /api/v1/docentes/{slug}` com a ficha pública do docente, localizado pelo slug, pelo ID Lattes ou, para números com menos de 10 dígitos, pelo ID interno.

#### Scenario: Docente pelo ID Lattes
- **WHEN** um cliente consulta `GET /api/v1/docentes/1234567890123456`
- **THEN** recebe a ficha do docente com esse ID Lattes: nome, slug, iniciais, departamento, foto, situação e período do vínculo, `lattesId`, `lattesUrl`, `orcid`, `lastLattesUpdate` (`AAAA-MM-DD`), `profileUrl`, `stats` (publicações e rede), `years`, `kinds` e até 12 `topics`

#### Scenario: Docente inexistente
- **WHEN** o identificador não corresponde a nenhum docente
- **THEN** a resposta tem status 404 com `error: "Docente não encontrado."`
