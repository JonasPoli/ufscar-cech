# Evolução: Periódicos por base de indexação (`/periodicos`), áreas CAPES e tesauro de periódicos

Documento de desenho (Parte A) e plano de execução para subagentes (Parte B). Medições de referência: `scratchpad/dossie_fatos.md` da sessão de 2026-10-30 (banco local = cópia de produção). Tudo o que está aqui foi decidido; quem implementa não escolhe alternativas.

**Fatos conferidos no banco em 2026-10-30:** 83.528 periódicos; 13.469 artigos, 11.516 vinculados, **2.405 linhas com artigo**; artigos por base (por linha): Scopus 3.036, WoS 2.324, Latindex 1.733, PubMed 1.200, SciELO 1.101; `academic_database`: scopus=1, wos=2, lens=3, pubmed=4, openalex=5, crossref=6, scielo=7, generic=8, latindex=9, doaj=10 (**use sempre o acrônimo, nunca o id: produção pode divergir**). CAPES (gov.br, consultado hoje): 3 colégios, 9 grandes áreas, **50 áreas de avaliação** (lista completa no Apêndice A). `journal_name_variants`: 49.490 linhas, todas `alternative`, `normalized_name` 100% distinto (índice único é viável).

---

# Parte A — Desenho

## A0. Resumo em nove pontos

1. Página pública **`/periodicos` — "Periódicos em que o CECH publica"**: cinco listas (Scopus, PubMed, SciELO, Web of Science, Latindex), ordenadas pela quantidade de artigos distintos dos docentes, sem posições numeradas, com filtro por área CAPES e nota metodológica. A palavra *ranking* não aparece em lugar nenhum.
2. Taxonomia-alvo: **CAPES, 9 grandes áreas + 50 áreas de avaliação**, tabela `capes_areas` com ids fixos; um periódico tem 1 área principal e até 2 secundárias; "Multidisciplinar" é a grande área, "Interdisciplinar" é a área usada quando a evidência se espalha.
3. Fragmentação resolvida **sem apagar nada**: coluna `canonical_journal_id` (família) + `display_title` calculado por ISSN a partir das fontes; a página agrega por família.
4. Vínculo artigo→periódico corrigido na raiz: `normalizeString` via `Transliterator`, resolução **ISSN → nome exato (título/variante) → sem parênteses → chave compacta**, sem palpite por palavras-chave; nomes ambíguos (mais de uma família) nunca resolvem sozinhos.
5. **Tesauro como mecanismo permanente**: índice único em `normalized_name`, aprendizado automático de grafias do Lattes confirmadas por ISSN (`variation_type='lattes'`), fila de revisão `journal_name_candidates` alimentada pela indexação, com tela admin; conflitos tratados no formulário e no merge.
6. Classificação de área: artefato portátil de evidências (`journal_evidence.jsonl.gz`, chaveado por ISSN) construído localmente a partir de WoS, Scopus (ASJC), DOAJ, Latindex, OpenAlex (snapshot completo), SciELO (ArticleMeta) e PubMed; carregado em `journal_area_evidence`; crosswalks em `config/journal_areas/*.json`; pontuação com pesos, limiares e confiança; curadoria manual nunca sobrescrita; **o campo `area` recebe o rótulo canônico** (ex.: `EDUCAÇÃO / CIÊNCIAS HUMANAS`) e o valor original fica preservado em `area_raw`.
7. IA só offline: lotes JSONL processados por subagentes Sonnet nesta máquina, importados como evidência `ai`; nenhuma chave em produção.
8. Produção: código via git, artefatos via `scp` para `docs/banco/derived/`, um comando orquestrador `app:journals:pipeline` executa a sequência; tudo chaveado por ISSN ou título normalizado, nunca por id.
9. Qualidade: cobertura ≥ 97% das famílias visíveis e ≥ 98% de todas as linhas com área, precisão ≥ 90% (grande área) em amostra revisada, auditoria antes/depois dos vínculos, testes automatizados listados em A9.

---

## A1. Página pública

### A1.1 Nome, URL e defesa perante editores

| Item | Decisão | Porquê |
|---|---|---|
| Rota | `GET /periodicos` (`app_pub_journals_index`) e `GET /periodicos/{base}` (`app_pub_journals_database`, `base` ∈ `scopus|pubmed|scielo|wos|latindex`) | Segue o padrão `/departamentos` + `/departamento/{x}`; URL neutra, descritiva. |
| Título H1 | **Periódicos em que o CECH publica** | Descreve um fato (onde os docentes publicam), não um juízo. |
| `<title>` | `Periódicos por base de indexação \| Portal CECH UFSCar` | Mesmo padrão das outras páginas. |
| Rótulo no menu | `Periódicos` (entre "Indicadores" e "Departamentos", nos três lugares: nav desktop `base.html.twig:224-240`, menu móvel `:323-380`, rodapé `:496-501`) | Curto e neutro. |
| Palavras proibidas em tudo que é público (rota, título, meta, HTML, classes CSS, `data-*`, ids de elementos, comentários Twig) | `ranking`, `rank`, `top`, `melhores`, `classificação de periódicos`, `posição`, `#1` | É o que um editor contestaria. Teste automatizado verifica `ranking`/`rank` ausentes no HTML (A9). |
| Posições numeradas | **Não existem.** Nem `1º`, nem `#1`, nem `loop.index`. A lista é ordenada por quantidade decrescente, empate por ordem alfabética do nome exibido. | Ordenar por uma contagem é fato; numerar transforma em pódio. |
| O que se exibe por periódico | nome exibido, ISSN(s), selo Qualis, chips das áreas CAPES, **N artigos** (distintos) e **N docentes** | Quantidades da própria produção do CECH; nada de métricas externas (fator de impacto, citações). O selo Qualis entrou em 31/10/2026, a pedido do coordenador, revendo a decisão inicial de não exibi-lo: é o mesmo selo dos artigos, informativo, e não define a ordem da lista. |
| Nota metodológica | Caixa "Como esta página é feita" acima das listas + seção "Metodologia e fontes" no fim, com as datas das listas (texto pronto em A1.4) | Transparência é a defesa: o leitor sabe que mede presença nas listas públicas das bases e a produção registrada no Lattes. |
| Datas das listas | Scopus: lista de títulos de jul./2026; DOAJ: 26/07/2026; SciELO: 26/08/2026; Web of Science, PubMed e Latindex: listas obtidas em ago./2026 (arquivos de 25/08/2026) | Constante `JournalsByDatabaseService::SOURCE_NOTES`, editável. |
| Classes CSS | `journal-list`, `journal-item`, `journal-count`, `journal-areas` | Sem termos de ordenação/avaliação. |

### A1.2 Layout e comportamento

- **Cabeçalho** (padrão de `templates/pub/department/index.html.twig:9-20`): selo "Produção científica", H1, subtítulo, três números globais em pílulas (periódicos com artigos, artigos distintos, docentes) — vindos do mesmo serviço.
- **Filtro**: um `<form method="GET">` com um único `<select name="area">` (classes iguais a `templates/pub/main/search.html.twig:25`), `onchange="this.form.submit()"` + botão "Filtrar" (funciona sem JS). Opções: `Todas as áreas` (valor vazio) e, para cada grande área, um `<optgroup label="Grande área">` cuja primeira opção é a própria grande área (`Ciências Humanas — todas`) seguida das suas áreas de avaliação. Valor = `slug` de `capes_areas`. Nada de novas dependências de frontend.
- **Cinco seções**, nesta ordem fixa: **Scopus, PubMed, SciELO, Web of Science, Latindex**. Cada seção é um `glass-card` com `data-base="<acronym>"`, logo/nome da base (de `academic_database`), contadores da seção ("N periódicos · M artigos distintos"), a lista (`<ol>` **sem marcadores numéricos**: `list-none`) e, quando a base tiver mais de 25 periódicos no filtro atual, o link **"Ver todos os N periódicos"** → `/periodicos/{base}?area=...`.
- **Página por base** (`/periodicos/{base}`): mesma estrutura com uma única seção e a lista completa (sem paginação: máximo hoje ≈ 720 linhas em Scopus; HTML de ~150 KB é aceitável e cria um único arquivo de cache). Link "← Todas as bases".
- **Linha de periódico**: nome exibido (`COALESCE(display_title, title)`), abaixo em texto pequeno ISSN(s) formatados e os chips de área (principal em destaque, secundárias discretas); à direita `N artigos` e `N docentes`. Ao lado do ISSN, o selo Qualis do sistema (`qualis_badge`, com o ícone `award` e a cor do estrato): o estrato vem de `production_items.qualis` dos artigos da família; havendo mais de um, vale o de mais artigos distintos e, no empate, o mais alto (`JournalsByDatabaseService::fetchQualis`). Barra horizontal proporcional ao maior valor da seção (`style="width: X%"`), rotulada por `aria-label="N artigos"`.
- **Estado vazio** por seção: "Nenhum periódico desta base com artigos na área selecionada."
- **Cache de páginas** (`PageCacheService` grava um arquivo por URL com query string): URLs válidas são finitas — 1 página × 60 valores de `area` + 5 bases × 60 = **366 arquivos no máximo**. Parâmetros inválidos retornam **404** (não são gravados, pois só respostas 200 entram no cache). Após qualquer execução do pipeline, rodar `app:cache:clear`.
- **Validação de parâmetros**: `area` deve casar `/^[a-z0-9-]{1,80}$/` e existir em `capes_areas.slug`, senão `createNotFoundException`. `base` restrito pelo `requirements` da rota. Qualquer outro parâmetro é ignorado.
- **Contagem**: artigos **distintos** por família de periódico, com a mesma chave de deduplicação do site (`StatisticsService.php:255-275`): DOI normalizado ou `TITLE:ano:título`. Coautorias internas contam uma vez. Docentes = `COUNT(DISTINCT researcher_id)`. Entram todos os 415 currículos (o site não filtra aposentados em contagens de produção).
- **Sitemap**: adicionar `/periodicos` e as cinco `/periodicos/{base}` em `SeoController::sitemap` (`changefreq weekly`, `priority 0.8`).
- **SEO/OG**: blocos `title`, `meta_description`, `og_title`, `og_description` como em `department/index.html.twig:3-6`.

### A1.3 SQL principal (validado em 2026-10-30 no banco local, forma com tabelas derivadas; CTE não roda em `dbal:run-sql`)

Executa em < 0,1 s. Validado substituindo `COALESCE(q.canonical_journal_id, q.id)` por `q.id` (coluna ainda não existe); os 12 primeiros do Scopus saíram coerentes com o dossiê (Revista Brasileira de Educação Especial 118 artigos/33 docentes; Temas em Psicologia 70; Paidéia 40 distintos de 46 linhas).

```sql
-- Parâmetros: :acronym (scopus|pubmed|scielo|wos|latindex), :areaId (opcional), :limit
SELECT q.id, COALESCE(q.display_title, q.title) AS name,
       q.issn, q.issn_e, q.issn_imp,
       a.n_articles, a.n_researchers, a.year_min, a.year_max
FROM (
    SELECT f.fid,
           COUNT(DISTINCT CASE WHEN pi.doi IS NOT NULL AND TRIM(pi.doi) <> ''
                               THEN CONCAT('DOI:', LOWER(TRIM(pi.doi)))
                               ELSE CONCAT('TITLE:', COALESCE(pi.year, ''), ':', LOWER(TRIM(pi.title))) END) AS n_articles,
           COUNT(DISTINCT pi.researcher_id) AS n_researchers,
           MIN(pi.year) AS year_min, MAX(pi.year) AS year_max
    FROM production_items pi
    JOIN (SELECT id, COALESCE(canonical_journal_id, id) AS fid FROM qualis_journals) f ON f.id = pi.qualis_journal_id
    WHERE pi.item_type = 'ARTIGO'
    GROUP BY f.fid
) a
JOIN (
    SELECT DISTINCT f.fid
    FROM qualis_journal_academic_database qb
    JOIN academic_database ad ON ad.id = qb.academic_database_id AND ad.acronym = :acronym
    JOIN (SELECT id, COALESCE(canonical_journal_id, id) AS fid FROM qualis_journals) f ON f.id = qb.qualis_journal_id
) d ON d.fid = a.fid
JOIN qualis_journals q ON q.id = a.fid
-- Filtro de área (só quando :areaId informado): grande área (parent) ou área de avaliação
-- JOIN journal_areas ja ON ja.journal_id = a.fid
-- JOIN capes_areas ca ON ca.id = ja.area_id AND (ca.id = :areaId OR ca.parent_id = :areaId)
ORDER BY a.n_articles DESC, name ASC
LIMIT :limit;
```

Observações: (1) com o filtro, usar `SELECT DISTINCT` ou agrupar por `q.id`, porque uma família pode ter mais de uma área sob a mesma grande área; (2) chips de área em segunda consulta: `SELECT ja.journal_id, ca.slug, ca.name, ja.is_primary FROM journal_areas ja JOIN capes_areas ca ON ca.id = ja.area_id WHERE ja.journal_id IN (:ids) ORDER BY ja.is_primary DESC, ja.score DESC`; (3) totais da seção = a mesma consulta sem `LIMIT`, agregada em PHP (`count`, `sum`); (4) números globais do cabeçalho: periódicos = famílias com `n_articles > 0`; artigos distintos = `COUNT(DISTINCT chave)` sobre artigos com `qualis_journal_id` não nulo; docentes = `COUNT(DISTINCT researcher_id)` idem.

### A1.4 Textos públicos prontos (pt-BR)

- `<title>`: `Periódicos por base de indexação | Portal CECH UFSCar`
- `meta_description`: `Periódicos científicos em que os docentes do Centro de Educação e Ciências Humanas da UFSCar publicam artigos, agrupados pela base em que estão indexados (Scopus, PubMed, SciELO, Web of Science e Latindex) e pela área de conhecimento.`
- Selo: `Produção científica`
- H1: `Periódicos em que o CECH publica`
- Subtítulo: `Periódicos com artigos de docentes do CECH registrados no Currículo Lattes, agrupados pela base em que estão indexados e pela área de avaliação da CAPES.`
- Caixa "Como esta página é feita" (acima das listas):
  > Cada linha é um periódico em que docentes do CECH publicaram artigos registrados no Currículo Lattes. Os números são a quantidade de **artigos distintos** (coautorias entre docentes do Centro contam uma vez) e de **docentes** que publicaram nele. A ordem é apenas decrescente de quantidade de artigos, com empates em ordem alfabética, e **não representa avaliação de qualidade, prestígio ou impacto** dos periódicos. A presença em cada base foi verificada nas listas públicas de títulos das próprias bases e pode conter omissões; a área de conhecimento é atribuída automaticamente a partir das categorias informadas pelas bases, segundo a taxonomia da CAPES, e pode conter imprecisões (ver "Metodologia e fontes", no fim da página).
- Seção "Metodologia e fontes" (fim da página):
  > **Fonte dos artigos:** Currículos Lattes dos docentes do CECH importados neste portal; são considerados os itens do tipo "artigo em periódico". **Identificação do periódico:** pelo ISSN informado no currículo e, na sua falta, pelo título, com um tesauro de variações de nomes mantido pela equipe do portal. **Listas das bases:** Scopus (lista de títulos de julho de 2026), SciELO (coleção Brasil, agosto de 2026), Web of Science (listas de periódicos da Clarivate, agosto de 2026), PubMed (lista de periódicos da NLM, agosto de 2026), Latindex (catálogo, agosto de 2026). **Área de conhecimento:** taxonomia de áreas de avaliação da CAPES (9 grandes áreas, 50 áreas), atribuída automaticamente a partir das categorias das próprias bases (Web of Science, Scopus/ASJC, DOAJ, Latindex, OpenAlex e SciELO) e, quando elas faltam, pelo título do periódico, inclusive com apoio de inteligência artificial; por ser automática, pode conter imprecisões. Um periódico pode ter mais de uma área e recebe "Interdisciplinar" quando suas categorias se distribuem por mais de uma grande área. **Qualis:** estrato da CAPES registrado nos artigos do periódico neste portal; se houver mais de um, aparece o de mais artigos. O selo é informativo e não define a ordem da lista. **Correções:** envie para o contato do portal. Dados atualizados em {{ updatedAt|date('d/m/Y') }}.
- Cabeçalho de seção: `{{ base.name }}` + `{{ n }} periódicos · {{ m }} artigos distintos`
- Link: `Ver todos os {{ n }} periódicos`
- Vazio: `Nenhum periódico desta base com artigos na área selecionada.`
- Página por base, H1: `Periódicos indexados em {{ base.name }} com artigos do CECH`

---

## A2. Taxonomia-alvo e granularidade

- **Tabela `capes_areas`** com **ids fixos** (iguais em local e produção porque a migration os insere): grandes áreas `100..900` (nível 1), áreas de avaliação `grande×100 + n` (nível 2). Lista completa no **Apêndice A** (59 linhas). Cópia em `config/journal_areas/taxonomy.json`; teste garante igualdade entre JSON e tabela.
- **Filtro** em dois níveis no mesmo select (grande área = todas as suas áreas + a própria grande área; área = só ela). Nível 2 é necessário: 1.038 dos docentes estão em "Ciências Humanas" e só o nível 2 separa Educação, Psicologia, Sociologia, História...
- **Múltiplas áreas:** sim. Cada família tem **1 principal** (`is_primary=1`) e **até 2 secundárias**, todas em `journal_areas`. O filtro por área inclui periódicos em que ela é principal **ou** secundária.
- **Atribuição a uma grande área (nível 1) sem área específica** é permitida quando a evidência é genérica (ASJC `3300`, WoS "Social Sciences, Interdisciplinar", "Humanities, Multidisciplinary" etc.).
- **Multidisciplinar/Interdisciplinar:** uma revista recebe `interdisciplinar` (905) quando (a) a fonte a rotula explicitamente (ASJC `1000`, WoS "Multidisciplinary Sciences"/"Multidisciplinary"/"General", Latindex "Multidisciplinarias", DOAJ "General Works"), ou (b) nenhuma área atinge 34% da evidência e as áreas com ≥ 15% pertencem a ≥ 2 grandes áreas diferentes (regra de espalhamento, A6.4). `ensino` (904) vem de WoS "Education, Scientific Disciplines", de regras de título ("Ensino de", "Educação Matemática", "Science Education"...) e da IA/curadoria; `materiais`, `biotecnologia`, `ciencias-ambientais` vêm diretamente dos crosswalks.

---

## A3. Modelo de dados — DDL exato

Doctrine exige entidade para cada tabela (sem `schema_filter`, `migrations:diff` tentaria apagar tabelas não mapeadas). Entidades novas em `src/Entity/`: `CapesArea`, `JournalArea`, `JournalAreaEvidence`, `JournalNameCandidate`; campos novos em `QualisJournal` e `ProductionItem`. A migration é gerada com `make:migration` e **complementada à mão** com o seed de `capes_areas`, a verificação prévia do índice único e a coluna derivada de `production_items`.

```sql
CREATE TABLE capes_areas (
  id SMALLINT UNSIGNED NOT NULL,
  parent_id SMALLINT UNSIGNED DEFAULT NULL,
  level TINYINT UNSIGNED NOT NULL,                 -- 1 grande área, 2 área de avaliação
  slug VARCHAR(80) NOT NULL,
  name VARCHAR(150) NOT NULL,
  college VARCHAR(80) NOT NULL,                    -- colégio CAPES
  sort_order SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_capes_area_slug (slug),
  KEY idx_capes_area_parent (parent_id),
  CONSTRAINT fk_capes_area_parent FOREIGN KEY (parent_id) REFERENCES capes_areas (id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB;
-- + 59 INSERTs do Apêndice A

CREATE TABLE journal_areas (
  id INT AUTO_INCREMENT NOT NULL,
  journal_id INT NOT NULL,
  area_id SMALLINT UNSIGNED NOT NULL,
  is_primary TINYINT(1) NOT NULL DEFAULT 0,
  score DECIMAL(5,4) NOT NULL DEFAULT 0,           -- parcela da evidência (0..1); 1 para manual
  confidence VARCHAR(10) NOT NULL,                 -- high | medium | low | manual
  method VARCHAR(20) NOT NULL,                     -- auto | manual
  run_id VARCHAR(40) DEFAULT NULL,                 -- ex.: 20261101-1030
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_journal_area (journal_id, area_id),
  KEY idx_journal_area_area (area_id, is_primary),
  CONSTRAINT fk_journal_area_journal FOREIGN KEY (journal_id) REFERENCES qualis_journals (id) ON DELETE CASCADE,
  CONSTRAINT fk_journal_area_area FOREIGN KEY (area_id) REFERENCES capes_areas (id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB;

CREATE TABLE journal_area_evidence (
  id INT AUTO_INCREMENT NOT NULL,
  journal_id INT NOT NULL,
  source VARCHAR(20) NOT NULL,       -- wos|scopus|doaj|latindex|openalex|scielo_area|scielo_wos|scielo_desc|pubmed|legacy|title_rule|ai
  record_key VARCHAR(100) NOT NULL,  -- id do registro na fonte (ou md5 de issns+título); serve para deduplicar na família
  label VARCHAR(255) NOT NULL,       -- rótulo/código bruto da fonte (ex.: 3304, "Education & Educational Research")
  weight DECIMAL(6,4) NOT NULL DEFAULT 1,  -- fração do rótulo dentro do registro (soma 1 por registro); openalex = share
  source_title VARCHAR(500) DEFAULT NULL,
  batch VARCHAR(100) DEFAULT NULL,   -- data do artefato (AAAA-MM-DD) ou nome do lote de IA
  payload JSON DEFAULT NULL,         -- extra da fonte: issns, works, country, publisher, model...
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_jae_journal (journal_id),
  KEY idx_jae_source_batch (source, batch),
  CONSTRAINT fk_jae_journal FOREIGN KEY (journal_id) REFERENCES qualis_journals (id) ON DELETE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB;

CREATE TABLE journal_name_candidates (
  id INT AUTO_INCREMENT NOT NULL,
  normalized_name VARCHAR(500) NOT NULL,           -- forma do tesauro (maiúsculas, StringNormalizer)
  raw_name VARCHAR(500) NOT NULL,                  -- grafia mais frequente no Lattes
  issn_raw VARCHAR(50) DEFAULT NULL,               -- ISSN mais frequente visto com esse nome (pode ser nulo)
  articles_count INT NOT NULL DEFAULT 0,
  researchers_count INT NOT NULL DEFAULT 0,
  suggested_journal_id INT DEFAULT NULL,
  suggestion_method VARCHAR(20) DEFAULT NULL,      -- issn | keywords | none
  status VARCHAR(20) NOT NULL DEFAULT 'pending',   -- pending | accepted | rejected | ambiguous
  resolved_journal_id INT DEFAULT NULL,
  sample_titles JSON DEFAULT NULL,                 -- até 3 títulos de artigos
  first_seen_at DATETIME NOT NULL,
  last_seen_at DATETIME NOT NULL,
  reviewed_at DATETIME DEFAULT NULL,
  reviewed_by VARCHAR(100) DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_jnc_norm (normalized_name),
  KEY idx_jnc_status (status, articles_count),
  CONSTRAINT fk_jnc_suggested FOREIGN KEY (suggested_journal_id) REFERENCES qualis_journals (id) ON DELETE SET NULL,
  CONSTRAINT fk_jnc_resolved FOREIGN KEY (resolved_journal_id) REFERENCES qualis_journals (id) ON DELETE SET NULL
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB;

ALTER TABLE qualis_journals
  ADD area_raw LONGTEXT DEFAULT NULL,                      -- valor original de `area`, antes do rótulo canônico
  ADD normalized_title VARCHAR(500) DEFAULT NULL,          -- StringNormalizer::normalizeString(title, true)
  ADD canonical_journal_id INT DEFAULT NULL,               -- NULL = a própria linha é a canônica
  ADD canonical_method VARCHAR(20) DEFAULT NULL,           -- issn | title | manual
  ADD display_title VARCHAR(500) DEFAULT NULL,
  ADD display_title_source VARCHAR(30) DEFAULT NULL,       -- scielo|doaj|latindex|scopus|wos|openalex|pubmed|lattes|title|manual
  ADD areas_locked TINYINT(1) NOT NULL DEFAULT 0,
  ADD KEY idx_journal_norm_title (normalized_title),
  ADD KEY idx_journal_canonical (canonical_journal_id),
  ADD CONSTRAINT fk_journal_canonical FOREIGN KEY (canonical_journal_id) REFERENCES qualis_journals (id) ON DELETE SET NULL;

-- Preserva o valor original do campo antes de qualquer classificação
UPDATE qualis_journals SET area_raw = area WHERE area IS NOT NULL AND area <> '';

-- Coluna DERIVADA (permitida pela regra do Lattes: journal_name bruto continua intocado)
ALTER TABLE production_items
  ADD journal_name_normalized VARCHAR(500) DEFAULT NULL,
  ADD KEY idx_prod_journal_name_norm (journal_name_normalized);

-- Trava de integridade do tesauro (a migration aborta se houver duplicatas; hoje há 0)
ALTER TABLE journal_name_variants ADD UNIQUE KEY uniq_journal_variant_norm (normalized_name);
```

Regras das colunas:
- `normalized_title` e `journal_name_normalized` são preenchidos pelos setters das entidades (`setTitle`, `setJournalName`) e por **todo INSERT/UPDATE em SQL cru** que grave `title`/`journal_name` (importador `JournalDatabaseImporterService.php:257,290`, `AdminJournalController::batchInsertJournals`). Para as linhas existentes, o comando `app:journals:normalize-titles` preenche as duas colunas (idempotente).
- `area` é o campo "Área de Avaliação / Conhecimento" pedido pelo usuário: **recebe o rótulo canônico da área principal** (`EDUCAÇÃO / CIÊNCIAS HUMANAS`; grande área sozinha: `CIÊNCIAS HUMANAS`), gerado por `CapesTaxonomy::areaLabel($slug)`, em todas as linhas da família. Só `JournalAreaClassifier` e `JournalAreaRepository::replaceManual` escrevem nele. `area_raw` guarda o valor original (migration + cópia feita pelo classificador quando encontrar texto que não seja rótulo canônico, caso de linhas importadas depois). Família sem classificação: `area` volta a `area_raw`. Os importadores continuam com `COALESCE(NULLIF(area,""),?)`, que não sobrescreve valor existente.
- `journal_name_variants.variation_type` ganha valores: `alternative` (importadores/legado), `lattes` (aprendida com confirmação por ISSN), `curated` (aceita na fila ou por arquivo de overrides), `manual` (digitada no formulário admin). Sem mudança de esquema.

---

## A4. Fragmentação (famílias) e nome exibido

**Problema:** a mesma revista existe em várias linhas (impresso/online do Qualis, SciELO importado só por título, JCR sem ISSN). Paidéia: linha 11322 (Scopus, 40 artigos distintos) e 65044 (SciELO, 0 artigos, sem ISSN) — na contagem por linha a Paidéia some do SciELO. Por família, SciELO vai de 1.101 para ≈ 2.277 artigos.

**Decisão: agrupar sem mesclar.** `canonical_journal_id` aponta para a linha canônica; nada é apagado nem movido; `UPDATE qualis_journals SET canonical_journal_id = NULL WHERE canonical_method <> 'manual'` desfaz tudo. O merge físico do admin (`EntityMergeService::mergeJournals`) continua existindo para quem quiser mesclar de fato.

**Algoritmo (`app:journals:families`, determinístico, idempotente):**
1. Extrair ISSNs **válidos** (regex `\d{4}-?\d{3}[\dXx]`, dígito verificador correto, `App\Service\Journal\Issn::extractAll`) das 4 colunas de cada linha (resolve os 222 casos com dois ISSNs no mesmo campo).
2. Union-find de ISSNs: unir os ISSNs de cada linha e os ISSNs de cada registro do artefato de evidências (pares impresso/online declarados por WoS, Scopus, DOAJ, Latindex, OpenAlex, SciELO), **apenas registros com ≤ 3 ISSNs**.
3. Linhas que compartilham um grupo de ISSN formam uma família (`canonical_method='issn'`).
4. Linha **sem ISSN válido**: `normalized_title` exato igual ao `normalized_title` de linhas com ISSN **ou** ao título normalizado de um registro do artefato → se aponta para **exatamente um** grupo de ISSN que tenha linha no cadastro, entra nele (`canonical_method='title'`). Sem remoção de parênteses (dossiê: "Issue brief (…)" virou família falsa de 10). Mais de um grupo = ambígua, fica isolada e é contada no relatório.
5. Canônica da família: mais artigos (`COUNT production_items ARTIGO`), depois mais ISSNs válidos, depois menor `id`. A canônica fica com `canonical_journal_id = NULL`; membros recebem o id dela. Linhas com `canonical_method='manual'` nunca são alteradas e famílias que contêm uma delas usam a canônica manual.
6. **Nome exibido** (`display_title`, gravado em todas as linhas da família, exceto quando `display_title_source='manual'`): primeiro título disponível nesta ordem de fontes casadas por ISSN da família — `scielo` (ArticleMeta v100) → `doaj` → `latindex` (`tit_propio`) → `scopus` → `wos` → `openalex` → `pubmed` → `lattes` (grafia de `journal_name` mais frequente entre os artigos da família, mínimo 2 ocorrências) → `title` (título da canônica em caixa de título: `mb_convert_case(mb_strtolower(t), MB_CASE_TITLE)` com conectivos `de da do das dos e em a o as os para com of the and y la el del di` em minúsculas, exceto na primeira palavra). Fontes em português primeiro porque Scopus/WoS perdem acentos ("Paideia", "Revista Brasileira de Ciencia Avicola").
7. Relatório: famílias, famílias com >1 linha, linhas sem ISSN unidas por título, ambíguas, artigos por base por linha × por família (esperado: SciELO 1.101 → ≈ 2.277; Scopus 3.036 → ≈ 3.430; WoS 2.324 → ≈ 2.399; PubMed 1.200 → ≈ 1.273; Latindex 1.733 → ≈ 1.802).

Por que o nome exibido não é o título da linha: linhas 23639 ("REVISTA BRASILEIRA DE HISTÓRIA & CIÊNCIAS SOCIAIS", ISSN 0102-6909) e 23600 ("ILHA - REVISTA DE ANTROPOLOGIA", ISSNs da Revista de Antropologia/USP) têm ISSN certo e título errado; os 19 e 28 artigos são, pelo Lattes, da RBCS e da Revista de Antropologia. As fontes casadas por ISSN dão o nome certo.

---

## A5. Vínculo artigo→periódico e tesauro

### A5.1 Causa-raiz e correção da normalização
`JournalResolverService::normalizeString` (`src/Service/Thesaurus/JournalResolverService.php:58-68`) usa `@iconv('UTF-8','ASCII//TRANSLIT')`, que neste macOS trunca ("história da educação" → "?o"); 991 linhas colidem em chaves como `o`, `tica`, `gica impresso`. Também não colapsa espaços ("(UNESP)" gera espaço duplo). Em Linux o comportamento é outro: o banco de produção pode ter vínculos diferentes dos locais; por isso tudo é medido pela auditoria (A5.6) nos dois ambientes.

**Nova implementação (única, compartilhada):**
```php
public static function normalizeString(?string $str): string
{
    if ($str === null || trim($str) === '') { return ''; }
    $str = html_entity_decode($str, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $str = StringNormalizer::normalizeString($str, false);   // Transliterator: acentos fora, pontuação → espaço, espaços colapsados, minúsculas
    $str = preg_replace('/[^a-z0-9]+/', ' ', $str);
    return trim(preg_replace('/\s+/', ' ', $str));
}
```
Forma do tesauro (gravada em `normalized_name`, `normalized_title`, `journal_name_normalized`): `StringNormalizer::normalizeString($s, true)` — é a mesma string em maiúsculas (exceto letras não latinas, irrelevantes aqui). Testes obrigatórios: `História da Educação` → `historia da educacao`; `Ciência e Educação (UNESP)` → `ciencia e educacao unesp`; `EDUCAÇÃO & SOCIEDADE` → `educacao sociedade`; `Psicologia: Teoria e Pesquisa (UnB. Impresso)` → `psicologia teoria e pesquisa unb impresso`.

### A5.2 Etapas de resolução (modo lote e modo on-demand idênticos)
Entrada: `journal_name`, `issn` do artigo. Família = `COALESCE(canonical_journal_id, id)`.
1. **ISSN**: para cada candidato extraído (`Issn::extractAll` sem exigir dígito válido, 8 caracteres), procurar nas 4 colunas normalizadas; primeiro acerto vence.
2. **Nome exato**: chave `normalizeString(nome)` nos títulos (`normalized_title`) e variantes (`normalized_name`). Se as linhas encontradas pertencem a **uma só família** → vínculo com a linha de menor id entre as encontradas com esse título, ou a linha da variante. Se a mais de uma família → **ambíguo, não resolve** (vai para a fila com `status='ambiguous'`).
3. **Sem parênteses**: mesma busca com `preg_replace('/\([^)]*\)/', '', nome)` normalizado. Modo lote: a chave sem parênteses dos títulos já é indexada em `loadFullMaps` (`:169-172`), portanto os dois lados são cobertos. Modo on-demand: consultar `normalized_title`/`normalized_name` com a forma limpa do nome do artigo; títulos do cadastro que tenham parênteses são alcançados pela etapa 4.
4. **Chave compacta**: remover dos dois lados os tokens `a o as os e de da do das dos em the of and y la el los las del` (depois da etapa 3); só vale se a chave tiver **≥ 2 tokens**; mesma regra de unicidade de família. Resolve "Educação e Sociedade" → "EDUCAÇÃO & SOCIEDADE" (24 artigos) e "Ciência e Educação (UNESP)" → "CIÊNCIA & EDUCAÇÃO" (9), que hoje dependem do palpite por palavras-chave. No modo lote: mapa `compactMap`; no modo on-demand: coluna não existe → consultar `normalized_title` candidatas por `LIKE` com o primeiro e o último token (`WHERE normalized_title LIKE :t1% AND normalized_title LIKE %:tn`) e comparar a chave compacta em PHP (≤ 50 linhas).
5. **Não resolvido**: `qualis_journal_id = NULL`; o nome entra na fila (A5.4) com sugestão por palavras-chave (`cleanKeywords`, `keywordMap`) **apenas como sugestão**, nunca como vínculo. O palpite automático por palavras-chave é removido porque erra ("Revista Logos (FEUC)" → "Logos (Lithuania)", "Cadernos de Psicologia (UFMG)" → "BOLETIM DE PSICOLOGIA", "Revista do Professor" → "CADERNOS PARA O PROFESSOR"); os acertos atuais são convertidos em variantes `curated` numa revisão única (Parte B, T23), de modo que não se perde vínculo correto.
6. O modo on-demand **abandona** `title LIKE '%kw%'` (`lookupInDatabase` etapa 5, linha 358), que podia pintar um selo Qualis errado na renderização.

### A5.3 Tesauro como caminho principal (requisito novo do usuário)
Recomendações do coordenador aceitas integralmente, com estes detalhes:
- **Aprendizado de grafias do Lattes com confirmação por ISSN** (`app:journals:names:learn`): para cada `journal_name_normalized` de artigos cujo vínculo veio da etapa ISSN e que não é título nem variante de nenhuma linha, agrupar por família; se o nome aponta para **uma só família** e aparece em **≥ 2 artigos**, criar variante na **linha canônica** da família com `variation_type='lattes'`, `variation_name` = grafia bruta mais frequente. Com 1 artigo: entra na fila com `suggestion_method='issn'` (um clique para aceitar). Se o mesmo nome aparece com ISSNs de famílias diferentes: fila com `status='ambiguous'`. Medido hoje: 369 nomes candidatos, **146 aprendidos automaticamente**, 223 vão à fila, 0 conflitos. Nenhum dado bruto é tocado.
- **Nome usado por mais de uma família nunca resolve sozinho** (A5.2, etapas 2–4). Casos reais: Cadernos de Pesquisa, Educação em Revista, Movimento, Perspectiva, Physis, Measurement.
- **Trava de integridade**: índice único em `normalized_name` (viável: 49.490 distintos) + serviço `JournalVariantGuard::findConflict(string $normalizedName, ?int $journalId): ?array{kind: 'variant'|'title', journalId: int, title: string}` consultado por **todos** os criadores de variantes (`AdminJournalController.php:145,222,242,489`, `EntityMergeService.php:341`, importador `:328`, aprendizado, fila). O formulário admin mostra: "A variação «X» já pertence ao periódico #id «Título». Remova-a daqui ou mescle os periódicos." e não grava.
- **Merge físico** ganha correções obrigatórias (hoje, apagar a linha-fonte zera `production_items.qualis_journal_id` via `ON DELETE SET NULL` e, com o índice único, inserir variantes repetidas falharia): antes de remover as fontes, `UPDATE production_items SET qualis_journal_id = :master WHERE qualis_journal_id IN (:sources)`; `UPDATE qualis_journals SET canonical_journal_id = :master WHERE canonical_journal_id IN (:sources)`; `UPDATE journal_area_evidence SET journal_id = :master WHERE journal_id IN (:sources)` (ignorando duplicatas); `DELETE FROM journal_areas WHERE journal_id IN (:sources)`; variantes das fontes são **reaproveitadas** (`setJournal(master)`) e só se cria variante nova para títulos ainda inexistentes no tesauro.

### A5.4 Fila de revisão de nomes (`journal_name_candidates`)
- **Quem alimenta:** a própria indexação. `indexAllProductionsJournalAndDatabases` (`CurriculumNormalizationService.php:324`) e `normalizeResearcher` (`:67`, chamado na importação de cada currículo em `LattesXmlParserService.php:233` e `LattesHtmlParserService.php:236`) chamam, ao final, `JournalNameQueueService::rebuild()`: uma consulta agrupa os artigos `ARTIGO` com `qualis_journal_id IS NULL` por `journal_name_normalized`, calcula contagens, grafia mais frequente, ISSN mais frequente, 3 títulos de amostra e a sugestão (`issn` se o ISSN do artigo aponta para alguma linha — o que só ocorre para nomes ambíguos —, `keywords` via `cleanKeywords`, ou `none`); faz `INSERT ... ON DUPLICATE KEY UPDATE` das contagens/amostras, mantém `status` existente, zera contagens dos pendentes que sumiram e apaga pendentes com `articles_count = 0`. Custo: 3 consultas, < 1 s.
- **Tamanho hoje (simulado com a normalização corrigida, sem palavras-chave):** 1.147 nomes / 2.148 artigos; **299 nomes com ≥ 2 artigos (1.300 artigos)**; 707 nomes têm ISSN válido no Lattes que não existe no cadastro (candidatos a linha nova via evidências, A6.2); ambíguos: entre 60 (exato por linha, coordenador) e 150 (incluindo sem parênteses por linha) — a implementação conta por família, portanto o número real é menor.
- **Tela admin** `/admin/journal-names` (controller próprio `AdminJournalNameController`, link no menu `templates/admin/base.html.twig:127` logo abaixo de "Periódicos & Qualis"): lista pendentes por `articles_count` desc, com grafia, ISSN visto, amostra de títulos, sugestão (título + link para editar o periódico), campo `journal_id` para escolher outro (com link "procurar" para `/admin/journals/?search=<nome>`), botões **Aceitar** (cria variante `curated` na canônica do periódico escolhido, revincula os artigos daquele nome via `CurriculumNormalizationService::reindexArticlesByNormalizedJournalName`, `status='accepted'`), **Rejeitar** (`status='rejected'`; o nome continua sem vínculo e some da lista), filtros `status`. Abas: Pendentes, Ambíguos, Aceitos, Rejeitados.
- **Currículo novo ou reimportado com nome desconhecido:** a importação roda `normalizeResearcher` → `rebuild()`; o nome aparece na fila; um aviso no painel admin de indexação mostra "N nomes de periódicos aguardando revisão" (contador na tela `/admin/journal-names`, exibido também no dashboard se houver um bloco de pendências; se não houver, só na tela).

### A5.5 Artigos sem vínculo com ISSN conhecido nas fontes
318 artigos (Discurso 28, DoisPontos 26, Educação Unisinos 12…) têm ISSN válido ausente do cadastro mas presente em DOAJ/Latindex/OpenAlex. `app:journals:evidence:load --create-missing-for-articles` cria a linha (título da fonte na ordem de prioridade de A4.6, ISSNs, `normalized_title`, `display_title`, sem Qualis, sem `area`) e, quando a fonte é uma das cinco bases da página, cria o vínculo em `qualis_journal_academic_database` (como o importador faria). Estimativa: ≈ 150 linhas novas.

### A5.6 Efeito nos indicadores das outras páginas e comprovação
`app:index:journals` regrava `qualis`, `qualis_journal_id` e `indexed_databases` de todos os artigos; isso muda Qualis na home, Figuras 11/12/14/15 de `/indicadores`, selos nas páginas de docentes e a nova página. Simulação (coordenador): 13.079 iguais, 231 mudam de periódico, 124 ganham vínculo, 35 perdem; mais 304 do palpite por palavras-chave que ficam pendentes até a revisão única (T23) e voltam como variantes `curated`.

**Comprovação (`app:journals:links:audit`):** `--snapshot=var/audit/<nome>.json` grava `{article_id: {jid, qualis, dbs[]}}` + agregados (vinculados, por estrato, por base); `--compare=var/audit/<nome>.json` imprime: artigos iguais / mudaram de periódico / ganharam / perderam; variação por estrato Qualis e por base; as 30 maiores mudanças por par (periódico antes → depois) com contagem; e verifica os três casos conhecidos: "Turismo: Visão e Ação" (sem ISSN) não recebe artigos com nome "Ciência e Educação"/"História da Educação"; "CONSTRUÇÃO PSICOPEDAGÓGICA" não recebe "Avaliação Psicológica"; "CAMINHOS DA EDUCAÇÃO MATEMÁTICA" não recebe "Educação Matemática em Revista". O mesmo comando roda em produção antes e depois.

---

## A6. Pipeline de classificação de área

### A6.1 Artefato de evidências (construído localmente, portátil, sem ids)
`app:journals:evidence:build` lê as fontes em `docs/banco/lists-to-import/` + derivados e escreve `docs/banco/derived/journal_evidence.jsonl.gz` (gzip, uma linha JSON por registro de fonte, ordenação determinística por `src`, `rec`, `title`) e `config/journal_areas/vocab/{wos,doaj,latindex,scielo_desc,asjc,openalex_subfields}.txt` (vocabulários, um rótulo por linha, versionados). Primeira linha: `{"_meta":{"built_at":"...","sources":{"scopus":{"file":"ext_list_Jul_2026.xlsx","records":48888},...}}}`.

Registro:
```json
{"src":"scopus","rec":"21101170611","issns":["20349130","22959149"],"title":"@GRH","labels":["1407"],"extra":{"publisher":"...","active":true,"type":"Journal"}}
{"src":"wos","rec":"00013072","issns":["00013072","14676281"],"title":"ABACUS-A JOURNAL OF ...","labels":["Business, Finance"],"extra":{"indexes":["SSCI"]}}
{"src":"doaj","rec":"27076946","issns":["27076946","27076954"],"title":"Revista Peruana de Ciencias de la Salud","labels":["Medicine: Public aspects of medicine"],"extra":{"keywords":["public health"],"country":"PE","publisher":"..."}}
{"src":"latindex","rec":"23289","issns":["2683166x"],"title":"(an)ecdótica","labels":["Literatura","Filología"],"extra":{"country":"México","publisher":"..."}}
{"src":"openalex","rec":"S2764905038","issns":["21945357","21945365"],"title":"Advances in intelligent systems and computing","labels":[{"code":"1702","share":0.31},{"code":"1710","share":0.22}],"extra":{"works":73533,"country":"DE","publisher":"...","in_doaj":false,"in_scielo":false,"type":"journal","alt_titles":["..."]}}
{"src":"scielo","rec":"0103-863X","issns":["0103863x","19824327"],"title":"Paidéia (Ribeirão Preto)","labels":["Human Sciences"],"extra":{"wos":["PSYCHOLOGY, MULTIDISCIPLINARY"],"desc":["PSICOLOGIA"],"status":"C","issn_types":{"0103863x":"PRINT","19824327":"ONLIN"}}}
{"src":"pubmed","rec":"7708172","issns":["01606999"],"title":"AADE editors' journal","labels":[],"extra":{"medabbr":"AADE Ed J"}}
```
Regras: ISSNs normalizados (8 caracteres, minúsculas, **só dígito verificador válido**; registro sem ISSN válido é mantido só se tiver título, para casamento por título); WoS: 21 CSVs menos `JCR 2025.csv`, registros deduplicados por conjunto de ISSNs com união de categorias e lista de índices; Scopus: aba `Scopus Sources Jul. 2026`, coluna `All Science Journal Classification Codes (ASJC)` separada por `;`; DOAJ: `Subjects` separado por `|`; Latindex: `;` como delimitador, `subtemas` separados por vírgula, `nombre_largo` = país, `nombre_edi` = editora; OpenAlex: snapshot `docs/banco/lists-to-import/openalex/snapshot/**/*.gz` (se ausente, as 402 páginas locais) — só `type = journal` com ≥ 1 ISSN; `labels` = subcampos (id numérico = código ASJC) com `share = count/Σcount` por subcampo, ordenados; SciELO: `docs/banco/derived/scielo_articlemeta.json` (A6.2); PubMed: `J_Medline.txt` em blocos.

### A6.2 Fontes auxiliares
- **OpenAlex snapshot** (`app:journals:evidence:fetch-openalex`): manifesto `https://openalex.s3.amazonaws.com/data/jsonl/sources/manifest.json` (release 2026-10-14: 136 `.gz`, 268.629 registros, 364 MB); o campo de lista chama-se `files` (conferido em 2026-10-30; o comando também aceita `entries`), cada item com `url` `s3://openalex/...` (trocar o prefixo por `https://openalex.s3.amazonaws.com/`) e `meta.content_length`; baixar para `docs/banco/lists-to-import/openalex/snapshot/<updated_date>/<arquivo>` pulando arquivos já completos. CC0, sem chave.
- **SciELO ArticleMeta** (`app:journals:evidence:fetch-scielo`): `GET https://articlemeta.scielo.org/api/v1/journal/identifiers/?collection=scl&limit=1000` → códigos; para cada um `GET .../journal/?collection=scl&issn=<código>` (≈ 1 s cada, 427 chamadas, `sleep(0.1)`, até 3 tentativas); salvar `docs/banco/derived/scielo_articlemeta.json` como `{"<código>": {"title": v100, "short": v150, "issns": [{"issn": v435._, "type": v435.t}], "area": [v441._], "wos": [v854._], "desc": [v440._], "status": v50._}}`. 422 dos 431 títulos do CSV local batem exatamente com `v100`.
- **Enriquecimento SciELO** (`app:journals:scielo:enrich`): linha vinculada à base `scielo` **sem ISSN válido** cujo `normalizeString(title)` é igual ao de exatamente um `v100` → preenche `issn_imp` (PRINT), `issn_e` (ONLIN), `issn` (código), colunas normalizadas (setters da entidade) e `display_title` = v100 se `display_title_source` ∈ {NULL, title}. Relata não casados (esperados 9: Iheringia, J. Braz. Soc. Mech. Sci. Eng., Motriz, Neotropical Entomology, Pesquisa Odontológica Brasileira, Rev. Bras. de Estudos da Presença e 3 títulos-lixo `title-<hash>`). Roda **antes** do `evidence:load`.

### A6.3 Carga (`app:journals:evidence:load <artefato> [--create-missing-for-articles] [--legacy] [--title-rules]`)
- Mapa `issn → ids` das 4 colunas (via `Issn::extractAll`) e `normalized_title → ids` só de linhas **sem ISSN válido**.
- Para cada registro: linhas-alvo = linhas com qualquer ISSN do registro; se nenhuma e o registro tem título, linhas sem ISSN com `normalized_title` igual. Para cada linha-alvo e cada rótulo: insere evidência (`weight` = 1/|labels| para fontes de rótulo simples, `share` para OpenAlex; `record_key` = `rec`), `source_title`, `batch` = data de `_meta.built_at`, `payload` = `extra` + `issns`. SciELO gera três fontes: `scielo_area` (v441), `scielo_wos` (v854), `scielo_desc` (v440).
- **Idempotência**: antes de inserir, `DELETE FROM journal_area_evidence WHERE source IN (fontes presentes no artefato)`. Nunca apaga `ai`, `legacy`, `title_rule` (cada um tem sua própria recarga).
- `--legacy`: para cada linha cujo valor original (`COALESCE(NULLIF(area_raw,''), area)`) é igual, sem acento/caixa, ao nome de uma área ou grande área CAPES (≈ 80 linhas: SOCIOLOGIA, SAÚDE COLETIVA, ENGENHARIAS III, MEDICINA I…), insere evidência `legacy` com `label` = valor bruto, `weight 1`, `record_key='area'`. O carregamento não altera `area`. "Social Sciences" (14.285 linhas, lixo do importador) e as categorias WoS com `|` são ignorados (as mesmas categorias vêm dos CSVs).
- `--title-rules`: aplica as regras de título do Apêndice C.6 ao `display_title` e aos títulos das linhas da família; insere evidência `title_rule` (`label` = slug, `weight` = 1/n, `record_key='title'`), recarregável.
- `--create-missing-for-articles`: A5.5.

### A6.4 Pontuação, limiares e confiança (`JournalAreaScorer`, função pura, testada com fixtures)
Entrada: evidências de **todas as linhas da família**, deduplicadas por (`source`, `record_key`, `label`). Pesos por fonte:

| Fonte | Peso `w` |
|---|---|
| `wos`, `scopus`, `scielo_wos` | 1,0 |
| `scielo_desc` | 0,9 |
| `legacy`, `doaj`, `latindex` | 0,8 |
| `scielo_area` (só nível 1) | 0,5 |
| `title_rule` | 0,5 |
| `openalex` | `min(1, log10(max(works,1))/3)` — 1.000 works → 1,0; 100 → 0,67; 10 → 0,33; 1 → 0 — e só rótulos com `share ≥ 0,10`, renormalizados |
| `ai` | 0,9 × `confidence` (principal) e 0,45 × `confidence` (secundária) |

Para cada fonte, rótulos → slugs pelo crosswalk (um rótulo pode dar 1 ou 2 slugs, peso dividido; `null` = ignorado); `f_s[a]` = soma dos pesos dos rótulos que caem em `a` ÷ soma dos pesos mapeados da fonte (soma 1). `share[a] = Σ_s w_s·f_s[a] / Σ_s w_s` (fontes sem rótulo mapeado não entram). Buckets podem ser de nível 1 ou 2.

Decisão:
1. `top2` = maior bucket de nível 2; `top1` = maior bucket de nível 1.
2. `top2.share ≥ 0,34` → principal = `top2`.
3. Senão, se `top1.share ≥ 0,34`: se existe nível 2 filho de `top1` com `share ≥ 0,20` → principal = esse filho; senão principal = `top1` (atribuição de grande área).
4. Senão (espalhamento): se os buckets com `share ≥ 0,15` pertencem a ≥ 2 grandes áreas → principal = `interdisciplinar`, confiança `medium`; senão principal = maior bucket, confiança `low`.
5. Secundárias: até 2 outros buckets com `share ≥ 0,25` (nível 2 preferido; nível 1 só se nenhum filho dele estiver presente; nunca a mesma área que a principal). Empates em qualquer passo: maior `share`, depois ordem alfabética do slug.
6. Confiança: `high` se `share(principal) ≥ 0,50` e `Σw ≥ 1,0` e (≥ 2 fontes contribuintes ou a única fonte ∈ {wos, scopus, scielo_wos, legacy}); `medium` se `share ≥ 0,34` e `Σw ≥ 0,5`; senão `low`. Só `title_rule` → sempre `low`. Só `ai` → `medium` se `confidence ≥ 0,8`, senão `low`.
7. Sem evidência mapeada (e fora do fallback PubMed de "Gravação") → nenhuma linha em `journal_areas` (página mostra "Área não classificada" como chip cinza; o filtro não a inclui).

Gravação (`app:journals:classify [--journal=ID] [--run-id=...]`): por família, se a **canônica** tem `areas_locked=1`, pula (curadoria vence); senão `DELETE FROM journal_areas WHERE journal_id IN (linhas da família) AND method='auto'` e insere principal + secundárias em **todas as linhas da família** (`method='auto'`, `run_id`); grava o rótulo de A3 em `qualis_journals.area` dessas linhas, copiando antes para `area_raw` o texto que não seja rótulo canônico; sem classificação, `area = area_raw`. **Fallback PubMed:** família sem nenhuma evidência mapeada e com vínculo à base `pubmed` recebe principal `ciencias-da-saude` (nível 1), `confidence='low'`, `score=0` (decisão do classificador, não do scorer puro). Resumo final: famílias classificadas por confiança, nível 1 vs 2, sem evidência, cobertura das famílias visíveis (com artigos).

### A6.5 Crosswalks (`config/journal_areas/`)
Formato único: `{"_meta": {"source": "wos", "version": "2026-10"}, "map": {"<rótulo exato>": "slug" | ["slug1","slug2"] | null}}`. Comparação de rótulos **insensível a caixa e espaços duplicados** (`mb_strtolower(trim(preg_replace('/\s+/',' ',$l)))`), porque o v854 do SciELO vem em maiúsculas. Arquivos: `crosswalk_asjc.json` (334 códigos; também serve para OpenAlex), `crosswalk_wos.json` (363), `crosswalk_doaj.json` (538), `crosswalk_latindex.json` (190), `crosswalk_scielo_area.json` (8), `crosswalk_scielo_desc.json` (465), `crosswalk_title_rules.json`. Conteúdo decidido no **Apêndice C** (tabelas completas para ASJC, Latindex, SciELO área e regras de título; âncoras + regras para WoS, DOAJ e descritores SciELO, completadas por Sonnet na T04). Teste: todo rótulo de `vocab/*.txt` tem chave no crosswalk; todo valor é slug válido ou `null`.

Política explícita: fora do perfil do CECH (saúde, biológicas, exatas, engenharias), o mapeamento vai para a **grande área** quando a subdivisão CAPES (Medicina I/II/III, Biológicas I/II/III, Engenharias I–IV) não é evidente. O filtro por grande área funciona para elas; o filtro de nível 2 é preciso onde importa (Humanas, Sociais Aplicadas, Linguística/Letras/Artes, Ensino, Saúde Coletiva, Educação Física).

### A6.6 Ruído do OpenAlex em revistas pequenas
Casos do dossiê: INTERACCOES (1 work), CADERNOS DA PEDAGOGIA (7 works), INCID → "Information Systems" 63% (seria Comunicação/Informação). Tratamento: peso logarítmico por `works_count` (A6.4), corte de `share < 0,10`, e no crosswalk ASJC `1710 Information Systems` e `3309 Library and Information Sciences` → `comunicacao-informacao-museologia` quando vêm do OpenAlex junto de `3309`; regra simples adotada: `1710` → `computacao`, mas a família com `1710` e `3309` presentes tem `1710` reatribuído a `comunicacao-informacao-museologia` (regra codificada no scorer, única exceção por par).

### A6.7 Erro "Social Sciences" do importador
`JournalDatabaseImporterService::resolveColumnPositions` (`:709-721`) reconhece `toplevelsocialsciences` (coluna-flag do xlsx) e não reconhece `allsciencejournalclassificationcodesasjc`. Correção: adicionar `allsciencejournalclassificationcodesasjc` e `asjc` à lista de `area`, remover `toplevelsocialsciences`. O importador continua gravando `area = COALESCE(NULLIF(area,""), ?)` (não sobrescreve); os 14.285 "Social Sciences" existentes ficam preservados em `area_raw` e são substituídos em `area` pelo rótulo canônico quando a família é classificada (a classificação nova não depende deles). Teste com CSV mínimo contendo as duas colunas.

### A6.8 Curadoria manual e formulário admin
- **Bloco "Área de Avaliação / Conhecimento (CAPES)"** no formulário (`templates/admin/journal/form.html.twig`, logo abaixo do input `area`): grade de checkboxes `areas[]` agrupada por grande área (59 opções, nível 1 incluído com rótulo "— grande área, sem especificar"), select `area_primary` (mesmas opções) e caixa "Travar (curadoria manual)". Ao salvar com ≥ 1 área: apaga as linhas da **canônica** da família, grava `method='manual'`, `confidence='manual'`, `score=1`, `is_primary` conforme o select (se o principal não estiver marcado, usa a primeira marcada), `areas_locked=1`, replica para as demais linhas da família. Botão "Voltar ao automático" (`POST /admin/journals/{id}/areas/unlock`): apaga manuais, `areas_locked=0`, roda `JournalAreaClassifier::classifyFamily()` para aquela família.
- Em linha **membro** de família, o bloco é somente leitura com aviso "Agrupado sob #id «Título» — edite as áreas lá".
- Abaixo do bloco: lista somente leitura "Evidências" (fonte, rótulo, peso, lote) e o resultado automático atual com confiança.
- O input `area` **mantém o rótulo "Área de Avaliação / Conhecimento"** e mostra o rótulo canônico. Fica `readonly` quando a família tem áreas (no servidor, o valor enviado é ignorado nesse caso, e a edição se faz pelo bloco) e editável como hoje quando não tem. Abaixo, texto pequeno somente leitura "Valor original importado: …" quando houver `area_raw`.
- Campo novo "Nome de exibição" (`display_title`): se preenchido, `display_title_source='manual'`.
- Índice admin (`index.html.twig:140`): mostra a área principal CAPES (com ponto colorido por confiança: verde high, âmbar medium, cinza low, azul manual) e, se não houver, o texto de `area`. Exportações CSV (`exportCsv` e `JournalDatabaseExporterService::export`) ganham colunas `area_capes_primary` e `areas_capes` (nomes separados por `;`). Importação CSV (`importCsv`) aceita coluna opcional `areas_capes` (slugs ou nomes separados por `;`) e grava como manual travado.
- **Arquivo de overrides** (`app:journals:overrides:import <arquivo.jsonl>`), portátil para produção, chaveado por ISSN ou título: linhas `{"type":"area","journal":{"issn":"0101-7330"},"primary":"educacao","secondary":["sociologia"],"lock":true}`, `{"type":"name","name":"Educação e Sociedade","journal":{"issn":"0101-7330"}}` (cria variante `curated` e revincula), `{"type":"family","issns":["0103-863X","1982-4327"],"canonical_issn":"0103-863X"}` (`canonical_method='manual'`), `{"type":"display_title","journal":{"issn":"..."},"title":"..."}`. `journal` pode ser `{"issn": ...}` ou `{"title": "<título exato>"}`; se não encontrar ou encontrar mais de uma família, reporta e pula a linha. Idempotente.

---

## A7. Papel da IA (offline, sem chave em produção)

- **Quando entra:** depois do classificador determinístico. Alvo: famílias **visíveis** (com ≥ 1 artigo) cuja confiança é `low` ou que não têm área. Estimativa: ≈ 260 sem evidência + ≈ 400 `low` ≈ **700 periódicos**; as regras de título (A6.3) reduzem isso antes. Opcional `--include-medium` para uma segunda rodada.
- **Lotes** (`app:journals:ai:export --out=docs/banco/derived/ai [--batch-size=40] [--include-medium]`): arquivos `batch_001.jsonl`… ordenados por artigos desc; cada linha: `{"key":"issn:0103863x"|"title:<normalized_title>","title":"...","other_titles":[...],"issns":["0103-863X"],"publisher":"...","country":"BR","bases":["scopus","scielo"],"articles":46,"evidence":{"wos":["..."],"asjc":["3304"],"doaj":[],"latindex":[],"openalex":[{"code":"3304","share":0.6}],"title_rule":["educacao"]},"current":{"primary":"educacao","confidence":"low"},"sample_articles":["título 1","título 2","título 3","título 4","título 5"]}`. `sample_articles`: 5 títulos de artigos da família, mais recentes primeiro. Também grava `PROMPT.md` com o texto do Apêndice D.
- **Processamento:** subagentes **Sonnet** nesta máquina, 3 lotes por subagente (≈ 40 × 3 periódicos, ≈ 30k tokens de entrada, ≈ 6k de saída cada); escrevem `batch_001.out.jsonl` com `{"key":"...","primary":"slug","secondary":["slug"],"confidence":0.0-1.0,"note":"≤ 120 caracteres"}`. Para 700 periódicos: 18 lotes → **6 subagentes**, custo total < US$ 1 (Sonnet 5.5 US$ 2/10 por MTok).
- **Importação** (`app:journals:ai:import <arquivo.out.jsonl>...`): valida slugs e intervalo de confiança, localiza família pela chave, insere evidência `ai` (`label` = slug, `weight` = 1 para principal / 0,5 para secundária, `batch` = nome do arquivo, `payload` = linha completa + `"model":"claude-sonnet"`), apagando antes as evidências `ai` do mesmo `batch`. Em seguida `app:journals:classify`. A IA é **evidência**, não escrita direta: proveniência fica registrada e a reexecução é idempotente.
- **Segunda passada, só por título, para o restante do cadastro (pedido do usuário: toda revista com valor):** depois da reclassificação, `app:journals:ai:export --scope=unclassified --out=docs/banco/derived/ai_titles --batch-size=250` exporta as famílias **sem nenhuma área** (visíveis ou não), em linhas compactas `{"key":"...","title":"...","country":"BR"|null}`. Subagentes **Haiku**, 5 lotes por subagente, gravam `.out.jsonl` no mesmo formato de saída, com `confidence` ≤ 0,6; título que não permite classificar recebe `{"key":"...","primary":null}`, que o importador conta como `unclassifiable` e pula. Importado como evidência `ai`, resulta sempre em confiança `low`.
- **Priorização de qualidade:** além dos lotes, a revisão de amostra (A9) roda nos 30 primeiros de cada base e em 100 visíveis aleatórios.

---

## A8. Execução em produção

Princípios: `docs/banco/` é gitignored → artefatos vão por `scp`; **nenhum artefato usa id**: chaves são ISSN (8 caracteres) ou `normalized_title`; a mesma sequência local é a sequência de produção; produção continua sendo a fonte da verdade do banco.

Artefatos a enviar (`scp <arq> runcloud@104.236.71.49:/home/runcloud/webapps/ufscar-cech/docs/banco/derived/`): `journal_evidence.jsonl.gz` (≈ 20 MB), `scielo_articlemeta.json`, `ai/*.out.jsonl`, `ai_titles/*.out.jsonl`, `overrides/names.jsonl`, `overrides/areas.jsonl`.

Sequência (comando único `app:journals:pipeline --derived=docs/banco/derived --env=prod`, que executa na ordem e para no primeiro erro; cada passo também pode ser rodado à mão):
1. `doctrine:migrations:migrate -n` (aborta se houver `normalized_name` duplicado — nesse caso o relatório lista as duplicatas para o admin mesclar antes).
2. `app:journals:normalize-titles`
3. `app:journals:links:audit --snapshot=var/audit/before.json`
4. `app:journals:scielo:enrich docs/banco/derived/scielo_articlemeta.json`
5. `app:journals:evidence:load docs/banco/derived/journal_evidence.jsonl.gz --create-missing-for-articles --legacy`
6. `app:journals:families` (antes da indexação: sem famílias, linhas duplicadas da mesma revista tornam o nome ambíguo e cerca de 580 artigos perderiam o vínculo temporariamente)
7. `app:index:journals` (resolver corrigido)
8. `app:journals:families` (de novo, com as contagens de artigos corrigidas, que definem a linha canônica)
9. `app:journals:names:learn`
10. `app:journals:overrides:import docs/banco/derived/overrides/names.jsonl`
11. `app:index:journals` (agora com variantes aprendidas/curadas) → `app:journals:links:audit --compare=var/audit/before.json`
12. `app:journals:evidence:load ... --title-rules` (só regras de título; precisa de `display_title`, por isso depois das famílias)
13. `app:journals:classify`
14. `app:journals:ai:import docs/banco/derived/ai/*.out.jsonl docs/banco/derived/ai_titles/*.out.jsonl`
15. `app:journals:overrides:import docs/banco/derived/overrides/areas.jsonl`
16. `app:journals:classify` → `app:journals:report`
17. `app:cache:clear`

Divergência de ids: o pipeline nunca lê ids de arquivo. Divergência de dados (linhas que só existem em produção): recebem evidência normalmente, pois o artefato traz **todas** as fontes, não só as casadas localmente; nomes novos caem na fila.

---

## A9. Qualidade mensurável

| Critério | Meta | Como verificar |
|---|---|---|
| Normalização | 4 casos de A5.1 passam | `APP_ENV=test ./vendor/bin/phpunit --filter JournalResolverServiceTest` |
| Vínculos | `audit --compare`: ≥ 11.516 artigos vinculados ao final da sequência; 3 casos conhecidos corrigidos; "artigo de outra revista" (ISSN+nome concordam contra a linha) ≤ 5 nas 44 conhecidas | `app:journals:links:audit --compare=var/audit/before.json` |
| Famílias | SciELO por família ≥ 2.200 artigos; nenhuma família > 6 linhas; ambíguas listadas | saída de `app:journals:families` |
| Cobertura | famílias visíveis com área principal ≥ 97%; linhas totais com ≥ 1 evidência ≥ 80%; **linhas totais com área ≥ 98%** (o restante, títulos sem informação, listado no relatório) | `app:journals:report` |
| Precisão | amostra: 30 primeiros de cada base (150) + 100 visíveis aleatórios; concordância ≥ 90% na grande área e ≥ 80% na área (principal ou secundária) | T27: planilha `docs/banco/derived/review/sample.tsv` revisada por Sonnet, discordâncias viram `overrides/areas.jsonl` |
| Tesauro | aprendizado: ≥ 140 variantes `lattes`; fila: pendentes com ≥ 2 artigos revisados (T23); 0 variantes duplicadas | `app:journals:names:learn` + tela admin |
| Página | 200 em `/periodicos`, `/periodicos/scopus`, `?area=educacao`; 404 em `?area=xyz` e `/periodicos/foo`; 5 `data-base` na ordem; HTML sem `ranking`/`rank`; sitemap contém `/periodicos` | `APP_ENV=test ./vendor/bin/phpunit --filter JournalsPageTest`; `php bin/console lint:twig templates` |
| Regressão | suíte completa verde: linha de base 96 testes/575 asserções + novos | `APP_ENV=test ./vendor/bin/phpunit --testdox` |
| Desempenho | consulta principal < 0,2 s por base (medido em A1.3: < 0,1 s) | `time php bin/console dbal:run-sql "..."` |

Revisão humana obrigatória (usuário): textos públicos (A1.4), amostra de 20 periódicos da primeira tela de cada base, e aprovação das decisões de A10.

---

## A10. Decisões que dependem do usuário (recomendação entre parênteses; o trabalho prossegue com ela)

1. Nome/URL `Periódicos em que o CECH publica` / `/periodicos` (**sim**).
2. Contar todos os currículos, inclusive aposentados (**sim**, coerente com o resto do site).
3. Selo Qualis nas linhas: **não** (decisão do coordenador: não foi pedido e, ao lado de uma lista ordenada, pareceria avaliação de qualidade). **Revista em 31/10/2026:** o coordenador pediu o selo nas linhas, e ele foi incluído, com uma frase na seção "Metodologia e fontes".
4. 25 periódicos por base na página principal, "ver todos" por base (**sim**).
5. Criar ≈ 150 linhas novas no cadastro para ISSNs de artigos encontrados nas fontes (**sim**).
6. Índice único em `normalized_name` e remoção do palpite por palavras-chave, com revisão única dos ≈ 300 artigos afetados (**sim**).
7. Preencher ISSNs das linhas SciELO sem ISSN via ArticleMeta (**sim**).
8. Rodar os lotes de IA com Sonnet (custo < US$ 1) (**sim**).
9. Regras de título como evidência fraca (**sim**; peso 0,5, nunca gera confiança alta).
10. O campo `area` recebe o rótulo canônico, com o original em `area_raw` (decisão do coordenador: atende ao pedido literal de preencher o campo).
11. Segunda passada de IA por título, com Haiku, para o restante do cadastro, e PubMed como fallback de grande área (decisão do coordenador: o pedido cobre toda revista cadastrada).

---

# Apêndice A — Taxonomia CAPES (seed de `capes_areas`)

Colégios: `Ciências da Vida` (100–300), `Humanidades` (400–600), `Ciências Exatas, Tecnológicas e Multidisciplinar` (700–900). `sort_order` = id.

| id | nível | pai | slug | nome |
|---|---|---|---|---|
| 100 | 1 | — | `ciencias-agrarias` | Ciências Agrárias |
| 101 | 2 | 100 | `ciencia-de-alimentos` | Ciência de Alimentos |
| 102 | 2 | 100 | `ciencias-agrarias-i` | Ciências Agrárias I |
| 103 | 2 | 100 | `medicina-veterinaria` | Medicina Veterinária |
| 104 | 2 | 100 | `zootecnia-recursos-pesqueiros` | Zootecnia / Recursos Pesqueiros |
| 200 | 1 | — | `ciencias-biologicas` | Ciências Biológicas |
| 201 | 2 | 200 | `biodiversidade` | Biodiversidade |
| 202 | 2 | 200 | `ciencias-biologicas-i` | Ciências Biológicas I |
| 203 | 2 | 200 | `ciencias-biologicas-ii` | Ciências Biológicas II |
| 204 | 2 | 200 | `ciencias-biologicas-iii` | Ciências Biológicas III |
| 300 | 1 | — | `ciencias-da-saude` | Ciências da Saúde |
| 301 | 2 | 300 | `educacao-fisica` | Educação Física, Fisioterapia, Fonoaudiologia e Terapia Ocupacional |
| 302 | 2 | 300 | `enfermagem` | Enfermagem |
| 303 | 2 | 300 | `farmacia` | Farmácia |
| 304 | 2 | 300 | `medicina-i` | Medicina I |
| 305 | 2 | 300 | `medicina-ii` | Medicina II |
| 306 | 2 | 300 | `medicina-iii` | Medicina III |
| 307 | 2 | 300 | `nutricao` | Nutrição |
| 308 | 2 | 300 | `odontologia` | Odontologia |
| 309 | 2 | 300 | `saude-coletiva` | Saúde Coletiva |
| 400 | 1 | — | `ciencias-humanas` | Ciências Humanas |
| 401 | 2 | 400 | `antropologia-arqueologia` | Antropologia / Arqueologia |
| 402 | 2 | 400 | `ciencia-politica-relacoes-internacionais` | Ciência Política e Relações Internacionais |
| 403 | 2 | 400 | `ciencias-da-religiao-teologia` | Ciências da Religião e Teologia |
| 404 | 2 | 400 | `educacao` | Educação |
| 405 | 2 | 400 | `filosofia` | Filosofia |
| 406 | 2 | 400 | `geografia` | Geografia |
| 407 | 2 | 400 | `historia` | História |
| 408 | 2 | 400 | `psicologia` | Psicologia |
| 409 | 2 | 400 | `sociologia` | Sociologia |
| 500 | 1 | — | `ciencias-sociais-aplicadas` | Ciências Sociais Aplicadas |
| 501 | 2 | 500 | `administracao-contabeis-turismo` | Administração Pública e de Empresas, Ciências Contábeis e Turismo |
| 502 | 2 | 500 | `arquitetura-urbanismo-design` | Arquitetura, Urbanismo e Design |
| 503 | 2 | 500 | `comunicacao-informacao-museologia` | Comunicação, Informação e Museologia |
| 504 | 2 | 500 | `direito` | Direito |
| 505 | 2 | 500 | `economia` | Economia |
| 506 | 2 | 500 | `planejamento-urbano-regional-demografia` | Planejamento Urbano e Regional / Demografia |
| 507 | 2 | 500 | `servico-social` | Serviço Social |
| 600 | 1 | — | `linguistica-letras-artes` | Linguística, Letras e Artes |
| 601 | 2 | 600 | `artes` | Artes |
| 602 | 2 | 600 | `linguistica-literatura` | Linguística e Literatura |
| 700 | 1 | — | `ciencias-exatas-terra` | Ciências Exatas e da Terra |
| 701 | 2 | 700 | `astronomia-fisica` | Astronomia / Física |
| 702 | 2 | 700 | `computacao` | Computação |
| 703 | 2 | 700 | `geociencias` | Geociências |
| 704 | 2 | 700 | `matematica-estatistica` | Matemática / Probabilidade e Estatística |
| 705 | 2 | 700 | `quimica` | Química |
| 800 | 1 | — | `engenharias` | Engenharias |
| 801 | 2 | 800 | `engenharias-i` | Engenharias I |
| 802 | 2 | 800 | `engenharias-ii` | Engenharias II |
| 803 | 2 | 800 | `engenharias-iii` | Engenharias III |
| 804 | 2 | 800 | `engenharias-iv` | Engenharias IV |
| 900 | 1 | — | `multidisciplinar` | Multidisciplinar |
| 901 | 2 | 900 | `biotecnologia` | Biotecnologia |
| 902 | 2 | 900 | `ciencias-ambientais` | Ciências Ambientais |
| 903 | 2 | 900 | `ciencias-humanidades-educacao-basica` | Ciências e Humanidades para a Educação Básica |
| 904 | 2 | 900 | `ensino` | Ensino |
| 905 | 2 | 900 | `interdisciplinar` | Interdisciplinar |
| 906 | 2 | 900 | `materiais` | Materiais |

Conteúdo das subdivisões usado nos crosswalks: Engenharias I = civil, sanitária/ambiental, transportes, hidráulica, construção; II = química, materiais/metalúrgica, minas, nuclear, petróleo; III = mecânica, produção, naval, aeroespacial; IV = elétrica, eletrônica, telecomunicações, biomédica, controle. Biológicas I = genética, biologia geral, morfologia, botânica/zoologia sistemática → aqui `biodiversidade` fica com ecologia, zoologia, botânica, oceanografia biológica; II = bioquímica, biofísica, fisiologia, farmacologia básica, neurociência; III = microbiologia, imunologia, parasitologia. Medicina I = clínica médica e especialidades clínicas; II = cirurgia e especialidades cirúrgicas, oncologia, ginecologia/obstetrícia, radiologia; III = pediatria, psiquiatria, medicina legal, dermatologia.

---

# Apêndice B — Formatos de arquivo (resumo)

| Arquivo | Onde | Produzido por | Lido por |
|---|---|---|---|
| `journal_evidence.jsonl.gz` | `docs/banco/derived/` | `app:journals:evidence:build` | `evidence:load`, `families` |
| `scielo_articlemeta.json` | `docs/banco/derived/` | `app:journals:evidence:fetch-scielo` | `evidence:build`, `scielo:enrich` |
| `openalex/snapshot/**/*.gz` | `docs/banco/lists-to-import/` | `app:journals:evidence:fetch-openalex` | `evidence:build` |
| `vocab/*.txt` | `config/journal_areas/` (versionado) | `evidence:build` | testes de completude dos crosswalks |
| `crosswalk_*.json`, `taxonomy.json` | `config/journal_areas/` (versionado) | T03/T04 | `AreaCrosswalk`, `CapesTaxonomy` |
| `ai/batch_NNN.jsonl`, `ai/batch_NNN.out.jsonl`, `ai/PROMPT.md` | `docs/banco/derived/ai/` | `ai:export` / subagentes | `ai:import` |
| `overrides/names.jsonl`, `overrides/areas.jsonl` | `docs/banco/derived/overrides/` | T23, T27, usuário | `overrides:import` |
| `var/audit/*.json` | `var/audit/` | `links:audit --snapshot` | `links:audit --compare` |
| `review/sample.tsv` | `docs/banco/derived/review/` | T27 | revisão humana |

# Apêndice C — Crosswalks (conteúdo decidido)

Notação: `código/rótulo → slug` ou `→ [slug1, slug2]` (peso dividido) ou `→ null` (ignorado). Slugs do Apêndice A; nível 1 permitido.

## C.1 ASJC (Scopus e subcampos do OpenAlex) — tabela completa por prefixo + exceções

Regra: o código de 4 dígitos recebe a **exceção** se listada; senão o **padrão do prefixo** (2 primeiros dígitos). Código `1000 → interdisciplinar`.

| Prefixo | Padrão | Exceções |
|---|---|---|
| 11 | `ciencias-agrarias` | 1102, 1107, 1108, 1111 → `ciencias-agrarias-i`; 1103, 1104 → `zootecnia-recursos-pesqueiros`; 1105, 1109, 1110 → `biodiversidade`; 1106 → `ciencia-de-alimentos` |
| 12 | `[ciencias-humanas, linguistica-letras-artes]` | 1202 → `historia`; 1203, 1208 → `linguistica-literatura`; 1204 → `antropologia-arqueologia`; 1205 → `[historia, linguistica-literatura]`; 1206 → `[artes, historia]`; 1207 → `[filosofia, historia]`; 1209 → `comunicacao-informacao-museologia`; 1210, 1213 → `artes`; 1211 → `filosofia`; 1212 → `ciencias-da-religiao-teologia` |
| 13 | `ciencias-biologicas` | 1302 → `medicina-i`; 1303, 1304, 1314 → `ciencias-biologicas-ii`; 1305 → `biotecnologia`; 1311 → `ciencias-biologicas-i` |
| 14 | `administracao-contabeis-turismo` | 1410 → `[administracao-contabeis-turismo, sociologia]` |
| 15 | `engenharias-ii` | — |
| 16 | `quimica` | — |
| 17 | `computacao` | — (ver A6.6 para 1710 junto de 3309) |
| 18 | `administracao-contabeis-turismo` | 1802 → `[administracao-contabeis-turismo, computacao]`; 1803 → `[engenharias-iii, administracao-contabeis-turismo]`; 1804 → `matematica-estatistica` |
| 19 | `geociencias` | 1909 → `engenharias-i`; 1912 → `astronomia-fisica` |
| 20 | `economia` | — |
| 21 | `engenharias` | 2102 → `engenharias-iv`; 2103, 2104 → `engenharias-ii`; 2105 → `[engenharias-iv, ciencias-ambientais]` |
| 22 | `engenharias` | 2202, 2203, 2206, 2209, 2210, 2212, 2213 → `engenharias-iii`; 2204, 2207, 2208 → `engenharias-iv`; 2205, 2215 → `engenharias-i`; 2211 → `[engenharias-iii, materiais]`; 2214 → `comunicacao-informacao-museologia`; 2216 → `arquitetura-urbanismo-design` |
| 23 | `ciencias-ambientais` | 2303 → `biodiversidade`; 2305 → `engenharias-i`; 2312 → `[engenharias-i, ciencias-ambientais]` |
| 24 | `ciencias-biologicas-iii` | — |
| 25 | `materiais` | — |
| 26 | `matematica-estatistica` | 2609 → `[matematica-estatistica, filosofia]`; 2614 → `computacao` |
| 27 | `ciencias-da-saude` | 2713, 2719, 2739 → `saude-coletiva`; 2735, 2738 → `medicina-iii`; 2742 → `educacao-fisica`; 2703, 2729, 2730, 2731, 2732, 2733, 2741, 2746, 2748 → `medicina-ii`; 2705, 2712, 2715, 2717, 2720, 2721, 2724, 2725, 2727, 2728, 2740, 2745 → `medicina-i`; 2709, 2736 → `farmacia`; 2723, 2726 → `ciencias-biologicas-iii`; 2702, 2710, 2716, 2722 → `ciencias-biologicas-i`; 2704, 2737 → `ciencias-biologicas-ii`; 2718 → `[saude-coletiva, computacao]` |
| 28 | `ciencias-biologicas-ii` | 2802, 2805 → `[psicologia, ciencias-biologicas-ii]`; 2803 → `medicina-iii`; 2808 → `medicina-i` |
| 29 | `enfermagem` | 2916 → `nutricao` |
| 30 | `farmacia` | — |
| 31 | `astronomia-fisica` | — |
| 32 | `psicologia` | 3204 → `[psicologia, educacao]` |
| 33 | `ciencias-humanas` | 3302, 3314 → `antropologia-arqueologia`; 3303 → `[economia, planejamento-urbano-regional-demografia]`; 3304 → `educacao`; 3305 → `[geografia, planejamento-urbano-regional-demografia]`; 3306 → `saude-coletiva`; 3307, 3311 → `engenharias-iii`; 3308 → `direito`; 3309, 3315 → `comunicacao-informacao-museologia`; 3310 → `linguistica-literatura`; 3312 → `[sociologia, ciencia-politica-relacoes-internacionais]`; 3313 → `engenharias-i`; 3316, 3318 → `[sociologia, antropologia-arqueologia]`; 3317, 3322 → `planejamento-urbano-regional-demografia`; 3319 → `[psicologia, sociologia]`; 3320 → `ciencia-politica-relacoes-internacionais`; 3321 → `administracao-contabeis-turismo` |
| 34 | `medicina-veterinaria` | — |
| 35 | `odontologia` | — |
| 36 | `ciencias-da-saude` | 3609, 3612, 3616 → `educacao-fisica`; 3611 → `farmacia`; 3605 → `saude-coletiva` |

O arquivo `crosswalk_asjc.json` deve listar explicitamente os 334 códigos da aba "ASJC Classification Codes" do xlsx (não só prefixos); a tabela acima é a regra para gerá-lo.

## C.2 Web of Science (363 rótulos) — âncoras explícitas + regras

Âncoras (humanidades, sociais, educação, saúde coletiva; rótulos exatos do vocabulário):
`Education & Educational Research`, `Education`, `Education, Special` → `educacao`; `Education, Scientific Disciplines` → `ensino`; `Psychology`, `Psychology, Applied`, `Psychology, Biological`, `Psychology, Clinical`, `Psychology, Development`, `Psychology, Experimental`, `Psychology, Mathematical`, `Psychology, Multidisciplinary`, `Psychology, Psychoanalysis`, `Psychology, Social` → `psicologia`; `Psychology, Educational` → `[psicologia, educacao]`; `Psychiatry/Psychology` → `[psicologia, medicina-iii]`; `Clinical Psychology & Psychiatry` → `[psicologia, medicina-iii]`; `Behavioral Sciences` → `[psicologia, ciencias-biologicas-ii]`; `Sociology`, `Social Issues`, `Family Studies`, `Women'S Studies` → `sociologia`; `Sociology & Social Sciences`, `Sociology/Social Sciences`, `Social Sciences, General`, `Social Sciences, Interdisciplinary`, `Social Sciences, Mathematical Methods`, `Area Studies`, `Asian Studies` → `ciencias-humanas`; `Social Sciences, Biomedical` → `saude-coletiva`; `Anthropology`, `Archaeology`, `Ethnic Studies`, `Folklore` → `antropologia-arqueologia`; `Cultural Studies` → `[sociologia, antropologia-arqueologia]`; `Political Science`, `Political Science & Public Administration`, `Political Science, Public Admin. & Development`, `International Relations` → `ciencia-politica-relacoes-internacionais`; `Public Administration` → `[administracao-contabeis-turismo, ciencia-politica-relacoes-internacionais]`; `History`, `Medieval & Renaissance Studies` → `historia`; `History & Philosophy Of Science` → `[historia, filosofia]`; `History Of Social Sciences` → `[historia, sociologia]`; `Classics`, `Classical Studies` → `[historia, linguistica-literatura]`; `Philosophy`, `Ethics` → `filosofia`; `Logic` → `[matematica-estatistica, filosofia]`; `Medical Ethics` → `[saude-coletiva, filosofia]`; `Religion`, `Religion & Theology` → `ciencias-da-religiao-teologia`; `Geography`, `Geography, Physical` → `geografia`; `Urban Studies`, `Regional & Urban Planning`, `Demography` → `planejamento-urbano-regional-demografia`; `Development Studies` → `[economia, planejamento-urbano-regional-demografia]`; `Social Work`, `Social Work & Social Policy` → `servico-social`; `Language & Linguistics`, `Linguistics`, `Literature`, `Literary Reviews`, `Literary Theory & Criticism`, `Poetry` e todo rótulo iniciado por `Literature,` → `linguistica-literatura`; `Communication`, `Information Science & Library Science`, `Library & Information Science`, `Film, Radio, Television` → `comunicacao-informacao-museologia`; `Marketing & Business Communication` → `administracao-contabeis-turismo`; `Art`, `Music`, `Theater`, `Dance`, `Performing Arts` → `artes`; `Art & Architecture` → `[artes, arquitetura-urbanismo-design]`; `Architecture` → `arquitetura-urbanismo-design`; `Law`, `Business Law & Reviews`, `Criminology & Penology` → `direito`; `Economics`, `Economics & Business`, `Business & Economics`, `Agricultural Economics & Policy` → `economia`; `Business`, `Business, Finance`, `Accounting & Finance`, `Management`, `Management & Organization`, `Hospitality, Leisure, Sport & Tourism`, `Industrial Relations & Labor`, `Employee Relations & Human Resources`, `Technology R&D Management`, `Engineering Management/General` → `administracao-contabeis-turismo`; `Humanities, Multidisciplinary` → `[ciencias-humanas, linguistica-letras-artes]`; `Multidisciplinary Sciences`, `Multidisciplinary`, `General` → `interdisciplinar`; `Public, Environmental & Occupational Health`, `Public Health & Health Care Science`, `Health Policy & Services`, `Health Care Sciences & Services`, `Environmental Medicine & Public Health`, `Primary Health Care` → `saude-coletiva`; `Sport Sciences`, `Rehabilitation`, `Audiology & Speech-Language Pathology`, `Orthopedics, Rehabilitation & Sports Medicine` → `educacao-fisica`; `Nursing` → `enfermagem`; `Nutrition & Dietetics`, `Endocrinology, Nutrition & Metabolism`, `Endocrinology, Metabolism & Nutrition` → `nutricao`; `Environmental Studies`, `Environmental Studies, Geography & Development`, `Environmental Sciences`, `Environment/Ecology`, `Green & Sustainable Science & Technology`, `Environmental Engineering & Energy` → `ciencias-ambientais`; `Ergonomics` → `engenharias-iii`.

Regras para o restante (aplicar a primeira que casar; `*` = qualquer sufixo):
- `Engineering, Civil`, `Civil Engineering`, `Construction & Building Technology`, `Environmental/Civil`, `Water Resources`, `Transportation`, `Transportation Science & Technology`, `Engineering, Environmental` → `engenharias-i`
- `Engineering, Chemical`, `Chemical Engineering`, `Engineering, Petroleum`, `Engineering, Geological`, `Geological, Petroleum & Mining Engineering`, `Mining & Mineral Processing`, `Metallurgy*`, `Nuclear*`, `Energy & Fuels` → `engenharias-ii`
- `Engineering, Mechanical`, `Mechanical Engineering`, `Engineering, Industrial`, `Engineering, Manufacturing`, `Engineering, Aerospace`, `Aerospace Engineering`, `Engineering, Marine`, `Engineering, Ocean`, `Mechanics`, `Thermodynamics`, `Operations Research & Management Science` → `engenharias-iii`
- `Engineering, Electrical*`, `Electrical & Electronics Engineering`, `Electronics & Electrical Engineering`, `Engineering, Biomedical`, `Automation & Control Systems`, `Robotics`, `Telecommunications*`, `Instruments & Instrumentation`, `Instrumentation & Measurement`, `Signal Processing*`, `Semiconductors*`, `Ai, Robotics, And Automatic Control`, `Information Technology & Communication Systems`, `Imaging Science & Photographic Technology` → `engenharias-iv`
- `Engineering`, `Engineering, Multidisciplinary`, `Engineering Mathematics` → `engenharias`
- `Materials Science*`, `Nanoscience & Nanotechnology`, `Polymer Science`, `Applied Physics/Condensed Matter/Materials Science` → `materiais`
- `Chemistry, Medicinal`, `Pharmacology*`, `Toxicology` → `farmacia`; demais `Chemistry*`, `Electrochemistry`, `Spectroscopy*`, `Crystallography`, `Physical Chemistry/Chemical Physics`, `Organic Chemistry/Polymer Science`, `Inorganic & Nuclear Chemistry`, `Analytical, Inorganic & Nuclear Chemistry` → `quimica`
- `Physics*`, `Astronomy & Astrophysics`, `Space Science*`, `Quantum Science & Technology`, `Optics*`, `Acoustics`, `Microscopy` → `astronomia-fisica`
- `Mathematics*`, `Statistics & Probability` → `matematica-estatistica`
- `Computer Science*`, `Computer Technology & Information Systems` → `computacao`
- `Geosciences*`, `Geology`, `Geochemistry & Geophysics`, `Mineralogy`, `Meteorology & Atmospheric Sciences`, `Oceanography`, `Paleontology`, `Remote Sensing`, `Earth Sciences` → `geociencias`
- `Ecology`, `Biodiversity Conservation`, `Zoology`, `Entomology*`, `Ornithology`, `Plant Sciences`, `Marine & Freshwater Biology`, `Evolutionary Biology`, `Mycology`, `Limnology`, `Plant & Animal Science`, `Animal & Plant Sciences` → `biodiversidade`
- `Fisheries`, `Aquatic Sciences`, `Animal Sciences`, `Agriculture, Dairy & Animal Science` → `zootecnia-recursos-pesqueiros`
- `Agriculture*`, `Agronomy`, `Agricultural Sciences`, `Agricultural Chemistry`, `Agricultural Engineering`, `Soil Science`, `Horticulture`, `Forestry` → `ciencias-agrarias-i`
- `Food Science*` → `ciencia-de-alimentos`; `Veterinary*` → `medicina-veterinaria`; `Biotechnology & Applied Microbiology` → `biotecnologia`
- `Microbiology`, `Immunology`, `Virology`, `Parasitology`, `Clinical Immunology & Infectious Disease` → `ciencias-biologicas-iii`
- `Genetics & Heredity`, `Molecular Biology & Genetics`, `Developmental Biology`, `Cell & Developmental Biology`, `Anatomy & Morphology`, `Reproductive Biology` → `ciencias-biologicas-i`
- `Biochemistry*`, `Biophysics`, `Cell Biology`, `Physiology`, `Neurosciences*`, `Neuroscience & Behavior`, `Biology & Biochemistry`, `Biochemical Research Methods`, `Cell & Tissue Engineering` → `ciencias-biologicas-ii`
- `Biology`, `Experimental Biology`, `Mathematical & Computational Biology` → `ciencias-biologicas`
- `Dentistry*` → `odontologia`
- `Psychiatry`, `Pediatrics`, `Substance Abuse`, `Medicine, Legal`, `Dermatology` → `medicina-iii`
- `Surgery`, `Anesthesiology`, `Anesthesia & Intensive Care`, `Obstetrics & Gynecology`, `Ophthalmology`, `Orthopedics`, `Otorhinolaryngology`, `Otolaryngology`, `Urology & Nephrology`, `Radiology*`, `Transplantation`, `Critical Care Medicine`, `Emergency Medicine`, `Oncology`, `Oncogenesis & Cancer Research`, `Reproductive Medicine`, `Andrology` → `medicina-ii`
- `Medicine, General & Internal`, `General & Internal Medicine`, `Cardiac*`, `Cardiovascular*`, `Endocrinology & Metabolism`, `Gastroenterology*`, `Hematology`, `Infectious Diseases`, `Clinical Neurology`, `Neurology`, `Neuroimaging`, `Respiratory System`, `Rheumatology`, `Allergy`, `Geriatrics & Gerontology`, `Gerontology`, `Peripheral Vascular Diseases`, `Tropical Medicine` → `medicina-i`
- `Clinical Medicine`, `Medicine, Research & Experimental`, `Medical*`, `Research/Laboratory Medicine & Medical Technology`, `Pathology`, `Integrative & Complementary Medicine` → `ciencias-da-saude`
- O que sobrar: Sonnet decide pelo significado; na dúvida, grande área; `null` só para rótulos sem conteúdo temático.

## C.3 DOAJ (538 rótulos LCC) — regras por prefixo (o prefixo mais longo que casar vence)

- `Education*` → `educacao`
- `Social Sciences: Sociology*`, `Social Sciences: Communities. Classes. Races*`, `Social Sciences: The family. Marriage. Woman*`, `Social Sciences: Social history and conditions*` → `sociologia`; `Social Sciences: Social pathology. Social and public welfare. Criminology*` → `[servico-social, sociologia]` (sufixo `: Criminology` → `direito`); `Social Sciences: Economic theory. Demography*` → `economia` (sufixo `: Demography` → `planejamento-urbano-regional-demografia`); `Social Sciences: Economic history and conditions*`, `Social Sciences: Industries. Land use. Labor*`, `Social Sciences: Finance*` → `economia` (`...Labor: Management. Industrial management*` → `administracao-contabeis-turismo`); `Social Sciences: Commerce*` → `administracao-contabeis-turismo`; `Social Sciences: Transportation and communications*` → `engenharias-i`; `Social Sciences: Statistics*` → `matematica-estatistica`; `Social Sciences: Socialism. Communism. Anarchism*` → `ciencia-politica-relacoes-internacionais`; `Social Sciences`, `Social Sciences: Social sciences (General)` → `ciencias-humanas`
- `Philosophy. Psychology. Religion: Psychology*` → `psicologia`; `...: Philosophy (General)*`, `...: Speculative philosophy*`, `...: Logic*`, `...: Ethics*`, `...: Aesthetics*` → `filosofia`; demais `Philosophy. Psychology. Religion: *` (religiões, teologia, Bíblia, denominações) → `ciencias-da-religiao-teologia`; `Philosophy. Psychology. Religion` → `[filosofia, ciencias-da-religiao-teologia]`
- `Language and Literature: Philology. Linguistics: Communication. Mass media*`, `Language and Literature: Literature (General): Journalism*` → `comunicacao-informacao-museologia`; `...Literature (General): Drama: Dramatic representation. The theater`, `...: Drama: Motion pictures`, `...: The performing arts. Show business` → `artes`; demais `Language and Literature*` → `linguistica-literatura`
- `Geography. Anthropology. Recreation: Anthropology*`, `...: Folklore`, `...: Manners and customs (General)` → `antropologia-arqueologia`; `...: Environmental sciences` → `ciencias-ambientais`; `...: Oceanography` → `geociencias`; `...: Human ecology. Anthropogeography: Settlements: Cities. Urban geography` → `planejamento-urbano-regional-demografia`; `...: Recreation. Leisure: Sports` → `educacao-fisica`; `...: Recreation. Leisure: Dancing` → `artes`; `...: Recreation. Leisure*` → `administracao-contabeis-turismo`; demais `Geography. Anthropology. Recreation*` → `geografia`
- `History (General) and history of Europe*`, `History America*`, `Auxiliary sciences of history*` → `historia` (sufixo `: Archaeology` → `antropologia-arqueologia`)
- `Fine Arts: Architecture*`, `Fine Arts: Drawing. Design. Illustration` → `arquitetura-urbanismo-design`; demais `Fine Arts*`, `Music and books on Music*` → `artes`
- `Law*` → `direito`; `Military Science*`, `Naval Science*` → `ciencia-politica-relacoes-internacionais`
- `Political science: Political institutions and public administration*` → `[ciencia-politica-relacoes-internacionais, administracao-contabeis-turismo]`; `Political science: Local government. Municipal government*` → `planejamento-urbano-regional-demografia`; `Political science: Colonies and colonization. Emigration and immigration. International migration*` → `[sociologia, planejamento-urbano-regional-demografia]`; demais `Political science*` → `ciencia-politica-relacoes-internacionais`
- `Bibliography. Library science. Information resources*`, `General Works: Museums. Collectors and collecting*` → `comunicacao-informacao-museologia`; `General Works: History of scholarship and learning. The humanities` → `ciencias-humanas`; demais `General Works*` → `interdisciplinar`
- `Medicine: Public aspects of medicine*` → `saude-coletiva`; `Medicine: Nursing*` → `enfermagem`; `Medicine: Dentistry*` → `odontologia`; `Medicine: Pharmacy and materia medica*`, `Medicine: Therapeutics. Pharmacology*` → `farmacia`; `Medicine: Internal medicine: Psychiatry*`, `Medicine: Pediatrics*`, `Medicine: Dermatology*` → `medicina-iii`; `Medicine: Surgery*`, `Medicine: Gynecology and obstetrics*`, `Medicine: Ophthalmology*`, `Medicine: Otorhinolaryngology*` → `medicina-ii`; `Medicine: Internal medicine*` → `medicina-i`; `Medicine: Physiology*` → `ciencias-biologicas-ii`; `Medicine: Sports medicine*` → `educacao-fisica`; demais `Medicine*` → `ciencias-da-saude`
- `Science: Mathematics*` → `matematica-estatistica`; `Science: Physics*`, `Science: Astronomy*` → `astronomia-fisica`; `Science: Chemistry*` → `quimica`; `Science: Geology*` → `geociencias`; `Science: Biology (General): Genetics*` → `ciencias-biologicas-i`; `Science: Biology (General): Ecology*`, `Science: Zoology*`, `Science: Botany*`, `Science: Natural history (General)*` → `biodiversidade`; `Science: Microbiology*` → `ciencias-biologicas-iii`; `Science: Physiology*`, `Science: Biology (General): Biochemistry*` → `ciencias-biologicas-ii`; `Science: Biology (General)*` → `ciencias-biologicas`; `Science: Science (General)*`, `Science` → `interdisciplinar`
- `Technology: Engineering (General). Civil engineering (General)*`, `Technology: Environmental technology. Sanitary engineering*`, `Technology: Building construction*`, `Technology: Hydraulic engineering*` → `engenharias-i`; `Technology: Chemical technology*`, `Technology: Mining engineering. Metallurgy*` → `engenharias-ii`; `Technology: Mechanical engineering and machinery*`, `Technology: Technology (General): Industrial engineering. Management engineering*`, `Technology: Manufactures*`, `Technology: Handicrafts*` → `engenharias-iii`; `Technology: Electrical engineering. Electronics. Nuclear engineering*` → `engenharias-iv`; `Technology: Home economics*` → `[nutricao, ciencias-sociais-aplicadas]`; demais `Technology*` → `engenharias`
- `Agriculture: Animal culture*`, `Agriculture: Aquaculture. Fisheries. Angling*` → `zootecnia-recursos-pesqueiros`; `Agriculture: Agriculture (General)*`, `Agriculture` → `ciencias-agrarias`; demais `Agriculture*` → `ciencias-agrarias-i`

## C.4 Latindex (190 subtemas, espanhol) — tabela completa

`Administración`, `Contabilidad`, `Economía de la empresa`, `Turismo` → `administracao-contabeis-turismo`; `Agronomía`, `Edafología`, `Silvicultura` → `ciencias-agrarias-i`; `Anatomía`, `Genética` → `ciencias-biologicas-i`; `Anestesiología`, `Cirugía`, `Ginecologia y obstetricia`, `Oftalmología`, `Oncología`, `Otorrinolaringología`, `Traumatología y ortopedia` → `medicina-ii`; `Antropología`, `Arqueología`, `Etnología`, `Folklore`, `Prehistoria` → `antropologia-arqueologia`; `Arquitectura`, `Diseño` → `arquitetura-urbanismo-design`; `Arte`, `Artes plásticas`, `Bellas artes`, `Cine`, `Danza`, `Fotografía`, `Historia del arte`, `Música`, `Pintura`, `Teatro` → `artes`; `Astronomía`, `Física` → `astronomia-fisica`; `Bibliografías`, `Bibliotecología`, `Ciencias de la comunicación`, `Ciencias de la información`, `Museología`, `Televisión` → `comunicacao-informacao-museologia`; `Biofísica`, `Bioquímica`, `Fisiología` → `ciencias-biologicas-ii`; `Biología`, `Ciencias naturales` → `ciencias-biologicas`; `Biotecnología` → `biotecnologia`; `Botánica`, `Ecología`, `Ictiología`, `Zoología` → `biodiversidade`; `Cardiología`, `Gastroenterología`, `Geriatría`, `Infectología`, `Nefrología`, `Neumología`, `Neurología`, `Reumatología` → `medicina-i`; `Cartografía`, `Geografía` → `geografia`; `Ciencia`, `Ciencia y Tecnología`, `Multidisciplinarias`, `Planeamiento científico y tecnológico` → `interdisciplinar`; `Ciencia política y administración pública`, `Defensa y seguridad`, `Relaciones internacionales` → `ciencia-politica-relacoes-internacionais`; `Ciencias atmosféricas`, `Ciencias de la tierra`, `Geodesia`, `Geofísica`, `Geología`, `Geoquímica`, `Oceanografía`, `Paleontología`, `Recursos naturales no renovables` → `geociencias`; `Ciencias exactas` → `ciencias-exatas-terra`; `Ciencias sociales y humanidades` → `ciencias-humanas`; `Ciencias y Técnicas historiográficas`, `Epigrafía`, `Historia`, `Numismática`, `Paleografía` → `historia`; `Computación`, `Inteligencia artificial-robótica` → `computacao`; `Criminología`, `Derechos humanos` e todo rótulo iniciado por `Derecho` → `direito`; `Cultura`, `Estudios de género` → `[sociologia, antropologia-arqueologia]`; `Demografía`, `Estudios regionales`, `Planificación del desarrollo`, `Planificación urbana` → `planejamento-urbano-regional-demografia`; `Deportes`, `Terapéutica y rehabilitación` → `educacao-fisica`; `Dermatología`, `Medicina legal`, `Pediatría`, `Psiquiatría` → `medicina-iii`; `Desarrollo económico`, `Econometría`, `Economía`, `Economía agrícola`, `Economía internacional`, `Hacienda pública`, `Planeación económica` → `economia`; `Educación`, `Educación media o secundaria`, `Educación preescolar`, `Educación primaria`, `Educación superior`, `Pedagogía`, `Planificación educativa`, `Tecnología educativa` → `educacao`; `Encuestas` → `[sociologia, matematica-estatistica]`; `Endocrinología y nutriología` → `[medicina-i, nutricao]`; `Energía`, `Ingeniería biomédica`, `Ingeniería de comunicaciones`, `Ingeniería de instrumentos`, `Ingeniería eléctrica`, `Telecomunicaciones`, `electrónica y control` → `engenharias-iv`; `Enfermería` → `enfermagem`; `Epidemiología`, `Estudios sociales de la salud`, `Salud pública` → `saude-coletiva`; `Estadística`, `Matemáticas` → `matematica-estatistica`; `Estudios latinoamericanos` → `[historia, sociologia]`; `Estudios locales` → `[historia, geografia]`; `Etica`, `Filosofía` → `filosofia`; `Farmacia`, `Farmacología`, `Toxicología` → `farmacia`; `Filología`, `Lingüística`, `Literatura`, `Poesía` → `linguistica-literatura`; `Finanzas` → `[economia, administracao-contabeis-turismo]`; `Ganadería`, `Pesca`, `Zootecnia` → `zootecnia-recursos-pesqueiros`; `Historia de la ciencia` → `[historia, filosofia]`; `Humanidades` → `[ciencias-humanas, linguistica-letras-artes]`; `Industria`, `Ingeniería aeronáutica`, `Ingeniería industrial`, `Ingeniería marina y portuaria`, `Ingeniería mecánica` → `engenharias-iii`; `Ingeniería`, `Tecnología` → `engenharias`; `Ingeniería civil`, `Ingeniería de transportes`, `Ingeniería hidráulica`, `Ingeniería sanitaria` → `engenharias-i`; `Ingeniería minera`, `Ingeniería petrolera`, `Ingeniería química`, `Ingeniería textil`, `Metalurgia` → `engenharias-ii`; `Ingeniería de materiales` → `materiais`; `Inmunología`, `Microbiología`, `Parasitología` → `ciencias-biologicas-iii`; `Medicina` → `ciencias-da-saude`; `Medio ambiente` → `ciencias-ambientais`; `Odontología` → `odontologia`; `Psicología` → `psicologia`; `Química` → `quimica`; `Religión` → `ciencias-da-religiao-teologia`; `Sociología` → `sociologia`; `Tecnología de alimentos` → `ciencia-de-alimentos`; `Trabajo social` → `servico-social`; `Urbanismo` → `[arquitetura-urbanismo-design, planejamento-urbano-regional-demografia]`; `Veterinaria` → `medicina-veterinaria`.

## C.5 SciELO

`crosswalk_scielo_area.json` (v441, nível 1): `Health Sciences → ciencias-da-saude`, `Human Sciences → ciencias-humanas`, `Applied Social Sciences → ciencias-sociais-aplicadas`, `Agricultural Sciences → ciencias-agrarias`, `Biological Sciences → ciencias-biologicas`, `Engineering → engenharias`, `Exact and Earth Sciences → ciencias-exatas-terra`, `Linguistics, Letters and Arts → linguistica-letras-artes`. v854 usa `crosswalk_wos.json` (comparação insensível a caixa).

`crosswalk_scielo_desc.json` (v440, 465 descritores em português, maiúsculas): regra geral — descritor igual (sem acento/caixa) ao nome de uma área/grande área → essa área; sinônimos obrigatórios: `EDUCACAO`, `EDUCAÇÃO`, `PEDAGOGIA` → `educacao`; `ENSINO`, `EDUCAÇÃO EM CIÊNCIAS`, `ENSINO DE CIÊNCIAS` → `ensino`; `LINGUÍSTICA`, `LINGUÍSTICA APLICADA`, `LETRAS`, `LITERATURA` → `linguistica-literatura`; `ARTES`, `MÚSICA`, `TEATRO`, `CINEMA` → `artes`; `CIÊNCIA DA INFORMAÇÃO`, `COMUNICAÇÃO`, `BIBLIOTECONOMIA`, `MUSEOLOGIA` → `comunicacao-informacao-museologia`; tudo que começa com `ADMINISTRAÇÃO`, `CONTABILIDADE`, `TURISMO` → `administracao-contabeis-turismo`; `CIÊNCIAS SOCIAIS` → `ciencias-humanas`; `SAÚDE COLETIVA`, `SAÚDE PÚBLICA`, `EPIDEMIOLOGIA`, `SAÚDE MENTAL` → `saude-coletiva`; `EDUCAÇÃO FÍSICA`, `FISIOTERAPIA`, `FONOAUDIOLOGIA`, `TERAPIA OCUPACIONAL` → `educacao-fisica`; `PSIQUIATRIA`, `PEDIATRIA` → `medicina-iii`; `CIRURGIA`, `ONCOLOGIA`, `GINECOLOGIA`, `OBSTETRÍCIA`, `OFTALMOLOGIA`, `ORTOPEDIA`, `RADIOLOGIA` → `medicina-ii`; `MEDICINA` → `ciencias-da-saude`; `CARDIOLOGIA`, `ENDOCRINOLOGIA`, `GASTROENTEROLOGIA`, `NEFROLOGIA`, `NEUROLOGIA`, `PNEUMOLOGIA`, `REUMATOLOGIA`, `INFECTOLOGIA`, `HEMATOLOGIA` → `medicina-i`; `FARMÁCIA`, `FARMACOLOGIA` → `farmacia`; `AGRONOMIA`, `FITOTECNIA`, `ENGENHARIA AGRÍCOLA`, `CIÊNCIA DO SOLO`, `FITOSSANIDADE` → `ciencias-agrarias-i`; `ZOOTECNIA`, `RECURSOS PESQUEIROS`, `AQUICULTURA` → `zootecnia-recursos-pesqueiros`; `CIÊNCIA E TECNOLOGIA DE ALIMENTOS` → `ciencia-de-alimentos`; `ZOOLOGIA`, `BOTÂNICA`, `ECOLOGIA`, `OCEANOGRAFIA BIOLÓGICA` → `biodiversidade`; `GENÉTICA`, `BIOLOGIA GERAL`, `MORFOLOGIA` → `ciencias-biologicas-i`; `BIOQUÍMICA`, `FISIOLOGIA`, `BIOFÍSICA`, `FISIOLOGIA VEGETAL`, `NEUROCIÊNCIAS` → `ciencias-biologicas-ii`; `MICROBIOLOGIA`, `IMUNOLOGIA`, `PARASITOLOGIA` → `ciencias-biologicas-iii`; `QUIMICA`, `QUÍMICA` → `quimica`; `FÍSICA`, `ASTRONOMIA` → `astronomia-fisica`; `MATEMÁTICA`, `ESTATÍSTICA`, `PROBABILIDADE` → `matematica-estatistica`; `GEOCIÊNCIAS`, `GEOLOGIA`, `OCEANOGRAFIA`, `OCEANOGRAFIA FÍSICA`, `OCEANOGRAFIA GEOLÓGICA`, `METEOROLOGIA` → `geociencias`; `CIÊNCIA DA COMPUTAÇÃO` → `computacao`; `ENGENHARIA CIVIL`, `ENGENHARIA SANITÁRIA`, `ENGENHARIA DE TRANSPORTES` → `engenharias-i`; `ENGENHARIA QUÍMICA`, `ENGENHARIA METALÚRGICA`, `ENGENHARIA DE MINAS`, `ENGENHARIA NUCLEAR` → `engenharias-ii`; `ENGENHARIA DE MATERIAIS` → `materiais`; `ENGENHARIA MECÂNICA`, `ENGENHARIA DE PRODUÇÃO`, `ENGENHARIA NAVAL`, `ENGENHARIA AERONÁUTICA` → `engenharias-iii`; `ENGENHARIA ELÉTRICA`, `ENGENHARIA BIOMÉDICA`, `ENGENHARIA ELETRÔNICA` → `engenharias-iv`; `CIÊNCIAS AMBIENTAIS`, `MEIO AMBIENTE` → `ciencias-ambientais`; `INTERDISCIPLINAR`, `MULTIDISCIPLINAR` → `interdisciplinar`. Os demais 300+: Sonnet decide pelo significado (são especialidades médicas/biológicas na maioria); `null` para rótulos não temáticos.

## C.6 Regras de título (`crosswalk_title_rules.json`: lista de `{"pattern": "<regex>", "slug": "..."}`, aplicadas ao título normalizado pela forma do resolver, minúsculas sem acento; todas as que casarem valem, peso dividido)

`\b(ensino|educacao matematica|educacao em ciencias|ciencia e educacao|ciencias e educacao|science education|mathematics education|physics education|chemistry education|educacion matematica|ensenanza)\b` → `ensino`; `\b(educacao|education|educational|educativa|educacional|pedagogia|pedagogica|pedagogy|pedagogical|docencia|escola|escolar)\b` → `educacao`; `\b(psicologia|psychology|psicologica|psychological|psychologie|psicologico|psicanalise|psychoanalysis)\b` → `psicologia`; `\b(linguistic|linguistics|linguistica|letras|literatura|literature|filologia|linguagem|linguagens|discurso|literary)\b` → `linguistica-literatura`; `\b(filosofia|philosophy|philosophie|philosophical|filosofica|filosofico)\b` → `filosofia`; `\b(historia|history|historica|historico|historical)\b` → `historia`; `\b(sociologia|sociology|sociologica|sociedade e estado)\b` → `sociologia`; `\b(antropologia|anthropology|antropologica|etnografia|arqueologia|archaeology)\b` → `antropologia-arqueologia`; `\b(ciencia politica|political science|politica e sociedade|relacoes internacionais|international relations)\b` → `ciencia-politica-relacoes-internacionais`; `\b(geografia|geography|geografica)\b` → `geografia`; `\b(teologia|theology|religiao|religion|religious)\b` → `ciencias-da-religiao-teologia`; `\b(biblioteconomia|ciencia da informacao|information science|library|documentacao|comunicacao|communication|jornalismo|midia|museologia|museum)\b` → `comunicacao-informacao-museologia`; `\b(direito|law|juridica|juridico|jus)\b` → `direito`; `\b(economia|economics|economic|economica)\b` → `economia`; `\b(administracao|management|business|gestao|contabil|accounting|turismo|tourism)\b` → `administracao-contabeis-turismo`; `\b(arquitetura|architecture|urbanismo|design)\b` → `arquitetura-urbanismo-design`; `\b(servico social|social work|assistencia social)\b` → `servico-social`; `\b(artes|arte|art|music|musica|teatro|theatre|theater|cinema|danca|dance)\b` → `artes`; `\b(saude coletiva|public health|saude publica|epidemiologia|epidemiology)\b` → `saude-coletiva`; `\b(educacao fisica|physical education|fisioterapia|physiotherapy|fonoaudiologia|terapia ocupacional|esporte|sport|motricidade|kinesiology)\b` → `educacao-fisica`; `\b(enfermagem|nursing)\b` → `enfermagem`; `\b(odontologia|dental|dentistry)\b` → `odontologia`; `\b(nutricao|nutrition)\b` → `nutricao`; `\b(farmacia|pharmacy|farmacologia|pharmacology)\b` → `farmacia`; `\b(materiais|materials)\b` → `materiais`; `\b(meio ambiente|ambiental|environmental|environment|sustentabilidade|sustainability)\b` → `ciencias-ambientais`; `\b(computacao|computing|computer|informatica|software)\b` → `computacao`; `\b(matematica|mathematics|mathematical|estatistica|statistics)\b` → `matematica-estatistica`; `\b(fisica|physics)\b` → `astronomia-fisica`; `\b(quimica|chemistry|chemical)\b` → `quimica`; `\b(interdisciplinar|interdisciplinary|multidisciplinar|multidisciplinary|transdisciplinar)\b` → `interdisciplinar`.

# Apêndice D — Prompt para os subagentes de classificação (copiar em `docs/banco/derived/ai/PROMPT.md`)

```
Você classifica periódicos científicos nas áreas de avaliação da CAPES. Leia o arquivo JSONL indicado; cada linha descreve um periódico (título, outros títulos, ISSNs, editora, país, bases em que está indexado, categorias já conhecidas em "evidence", classificação automática atual em "current" e até 5 títulos de artigos de docentes do CECH/UFSCar publicados nele).

Para cada linha escreva UMA linha JSON no arquivo de saída <mesmo nome>.out.jsonl:
{"key": "<copie key>", "primary": "<slug>", "secondary": ["<slug>"], "confidence": 0.0-1.0, "note": "<até 120 caracteres>"}

Slugs permitidos (use exatamente): ciencias-agrarias, ciencia-de-alimentos, ciencias-agrarias-i, medicina-veterinaria, zootecnia-recursos-pesqueiros, ciencias-biologicas, biodiversidade, ciencias-biologicas-i, ciencias-biologicas-ii, ciencias-biologicas-iii, ciencias-da-saude, educacao-fisica, enfermagem, farmacia, medicina-i, medicina-ii, medicina-iii, nutricao, odontologia, saude-coletiva, ciencias-humanas, antropologia-arqueologia, ciencia-politica-relacoes-internacionais, ciencias-da-religiao-teologia, educacao, filosofia, geografia, historia, psicologia, sociologia, ciencias-sociais-aplicadas, administracao-contabeis-turismo, arquitetura-urbanismo-design, comunicacao-informacao-museologia, direito, economia, planejamento-urbano-regional-demografia, servico-social, linguistica-letras-artes, artes, linguistica-literatura, ciencias-exatas-terra, astronomia-fisica, computacao, geociencias, matematica-estatistica, quimica, engenharias, engenharias-i, engenharias-ii, engenharias-iii, engenharias-iv, multidisciplinar, biotecnologia, ciencias-ambientais, ciencias-humanidades-educacao-basica, ensino, interdisciplinar, materiais.

Regras:
1. "primary" é a área que melhor descreve o ESCOPO DO PERIÓDICO (não o artigo do docente). Os títulos de artigos são só contexto.
2. "secondary": 0 a 2 áreas realmente cobertas pelo periódico; vazio se não houver.
3. Use "interdisciplinar" quando o escopo cobre três ou mais áreas de grandes áreas diferentes (ex.: revista geral de uma universidade). Use "ensino" para periódicos de ensino de ciências, matemática, física etc.; "educacao" para educação em geral. Use "comunicacao-informacao-museologia" para ciência da informação, biblioteconomia, comunicação, jornalismo, museologia.
4. Quando só dá para dizer a grande área, use o slug dela (ciencias-humanas, ciencias-da-saude, engenharias...).
5. "confidence": 0.9 se o título/escopo é inequívoco; 0.7 se provável; 0.5 se é palpite; 0.3 se quase não há informação (mantenha a linha mesmo assim).
6. Não invente ISSNs nem altere "key". Não pule linhas. Não escreva nada além do JSONL no arquivo de saída.
7. Boletins, jornais e revistas de divulgação recebem a área do assunto que tratam (ex.: boletim de uma sociedade de psicologia → psicologia).
```

---

# Parte B — Plano de execução para subagentes

## B0. Convenções válidas para todas as tarefas (ler antes de qualquer tarefa)

- Projeto: `/Volumes/Dados/work/cech` (Symfony 7.2, PHP 8.4, MySQL 8). Leia `AGENTS.md`. Regras fixas: **migrations sempre** (`php bin/console make:migration`; nunca `doctrine:schema:update`); **dados brutos do Lattes intocáveis** (`production_items.journal_name`, `issn`, `title`, …): enriquecimento só em colunas novas; `qualis_journals.area` só é escrito por `JournalAreaClassifier` e `JournalAreaRepository::replaceManual`, e o valor original fica em `area_raw`.
- Banco local: `cech` (dev) e `cech_test` (testes); todo comando com `APP_ENV=test` usa `cech_test`. Aplicar migrations nos dois: `php bin/console doctrine:migrations:migrate -n` e `APP_ENV=test php bin/console doctrine:migrations:migrate -n`.
- Testes: `APP_ENV=test ./vendor/bin/phpunit --testdox` (linha de base: 96 testes, 575 asserções, 3 ignorados, 1 risky, 0 falhas). Rode só `--filter <SuaClasseDeTeste>`: agentes em paralelo compartilham `cech_test`, e a suíte inteira é rodada pelo coordenador entre as ondas. Lints quando tocar nessas pastas: `php bin/console lint:twig templates`, `php bin/console lint:yaml config`.
- Consultas de leitura: `php bin/console dbal:run-sql "SELECT ..."` (só executa como consulta se começar por `SELECT`; CTE `WITH` não funciona ali — use tabelas derivadas).
- Código: PSR-12, 4 espaços, tipos nas propriedades, serviços em `src/Service/Journal/` (namespace `App\Service\Journal`), comandos em `src/Command/` com nome `app:journals:*` e classe `Journals<Algo>Command` (imite `src/Command/ImportJournalDatabaseListCommand.php` para opções/argumentos e `src/Command/ImportRepositoryCommand.php:36-40,123` para `--dry-run`). Saída com `SymfonyStyle` (`$io->table`, `$io->success`). Comandos longos: `@ini_set('memory_limit', '1024M')` como em `IndexJournalsCommand.php:28`.
- Arquivos grandes de dados vivem em `docs/banco/` (gitignored). Nada de dados em `var/` que precise sobreviver.
- Não faça commit, push nem deploy. Não acesse produção. Não altere arquivos fora da lista da sua tarefa; se precisar, pare e relate.
- Relatório final de cada tarefa: arquivos criados/alterados, comandos de aceite rodados com a saída resumida, números obtidos, dúvidas.
- Vocabulário: "família" = `COALESCE(canonical_journal_id, id)`; "linha canônica" = linha cujo `canonical_journal_id` é NULL; "forma do tesauro" = `StringNormalizer::normalizeString($s, true)` (maiúsculas); "forma do resolver" = `JournalResolverService::normalizeString($s)` (minúsculas).

## B1. Ondas (tarefas da mesma onda não tocam nos mesmos arquivos e podem rodar em paralelo)

| Onda | Tarefas | Pré-requisito |
|---|---|---|
| 0 | T01 entidades+migration · T02 normalização/ISSN · T03 taxonomia JSON+crosswalk ASJC/título/SciELO-área · T04 crosswalks WoS/DOAJ/Latindex/SciELO-desc · T05 fetchers OpenAlex/SciELO · T06 construtor do artefato (começa depois de T02) | — |
| 1 | T07 importador (ASJC, normalized_title) · T08 resolver+fila · T09 auditoria de vínculos · T10 carga de evidências · T11 famílias+nome exibido · T12 aprendizado de nomes+guarda · T13 enriquecimento SciELO | onda 0 concluída e migrations aplicadas |
| 2 | T14 classificador · T15 lotes de IA · T16 página pública · T17 admin: áreas, conflitos, merge · T18 admin: fila de nomes · T19 overrides+report+pipeline | onda 1 |
| 3 | T20 dados: fetch+artefato · T21 dados: vínculos (antes/depois) · T22 dados: famílias+nomes · T23 revisão única de nomes · T24 classificação+exportação IA · T25 IA (N subagentes) · T26 importação IA · T26b segunda passada por título (Haiku) · T27 revisão de qualidade | onda 2 |
| 4 | T28 suíte completa, lints, documentação · T29 roteiro de produção (usuário) | onda 3 |

---

## Onda 0

### T01 — Entidades, migration e taxonomia
- **Modelo:** `sonnet` (fundação; erro aqui bloqueia tudo). **Depende de:** nada.
- **Arquivos:** criar `src/Entity/CapesArea.php`, `src/Entity/JournalArea.php`, `src/Entity/JournalAreaEvidence.php`, `src/Entity/JournalNameCandidate.php`, `src/Repository/CapesAreaRepository.php`, `src/Repository/JournalAreaRepository.php`, `src/Repository/JournalAreaEvidenceRepository.php`, `src/Repository/JournalNameCandidateRepository.php`, `config/journal_areas/taxonomy.json`, `src/Service/Journal/CapesTaxonomy.php`, `migrations/Version<timestamp>.php`, `tests/Service/Journal/CapesTaxonomyTest.php`; alterar `src/Entity/QualisJournal.php`, `src/Entity/ProductionItem.php`.
- **Especificação:**
  - Entidades conforme DDL de A3 (imite estilo de `src/Entity/JournalVariation.php`: atributos `#[ORM\Table]`, `#[ORM\Index]`, getters/setters fluentes). `CapesArea`: `#[ORM\Id] #[ORM\Column(type: 'smallint', options: ['unsigned' => true])]` **sem** `GeneratedValue`; `parent` ManyToOne self; `level`, `slug`, `name`, `college`, `sortOrder`. `JournalArea`: ManyToOne `journal` (`onDelete: 'CASCADE'`), ManyToOne `area`, `isPrimary`, `score` (`decimal`, precision 5, scale 4), `confidence`, `method`, `runId`, `updatedAt`; `#[ORM\UniqueConstraint(name: 'uniq_journal_area', columns: ['journal_id','area_id'])]`. `JournalAreaEvidence`: campos de A3, `payload` tipo `json`. `JournalNameCandidate`: campos de A3, `sampleTitles` json, FKs `onDelete: 'SET NULL'`.
  - `QualisJournal`: novos campos `areaRaw` (`area_raw`, `type: 'text'`, nullable), `normalizedTitle` (`normalized_title`), `canonical` (ManyToOne self, `canonical_journal_id`, `onDelete: 'SET NULL'`), `canonicalMethod`, `displayTitle`, `displayTitleSource`, `areasLocked` (bool default false), OneToMany `areas` (`JournalArea`, cascade remove), `#[ORM\Index(columns: ['normalized_title'], name: 'idx_journal_norm_title')]`. `setTitle()` passa a fazer também `$this->normalizedTitle = StringNormalizer::normalizeString($title, true) ?: null`. Métodos: `getFamilyId(): int` (`canonical?->getId() ?? id`), `getPrimaryArea(): ?JournalArea`, `getDisplayTitleOrTitle(): string`.
  - `ProductionItem`: campo `journalNameNormalized` (`journal_name_normalized`, VARCHAR 500, nullable, índice `idx_prod_journal_name_norm`); `setJournalName()` também grava a forma do tesauro. Não mexa em nenhum outro campo.
  - `JournalVariation`: adicionar `#[ORM\UniqueConstraint(name: 'uniq_journal_variant_norm', columns: ['normalized_name'])]` na classe.
  - Migration: gere com `php bin/console make:migration`, depois edite: (a) no início de `up()`, `$this->abortIf((int)$this->connection->fetchOne("SELECT COUNT(*) FROM (SELECT normalized_name FROM journal_name_variants GROUP BY normalized_name HAVING COUNT(*) > 1) d") > 0, 'Há normalized_name duplicado em journal_name_variants; mescle antes (lista: SELECT normalized_name, COUNT(*) FROM journal_name_variants GROUP BY 1 HAVING COUNT(*)>1).');` (b) após o `CREATE TABLE capes_areas`, 59 `INSERT INTO capes_areas (id, parent_id, level, slug, name, college, sort_order) VALUES (...)` exatamente como o Apêndice A (`college` ∈ `Ciências da Vida`, `Humanidades`, `Ciências Exatas, Tecnológicas e Multidisciplinar`); (c) depois do `ALTER TABLE qualis_journals`, `UPDATE qualis_journals SET area_raw = area WHERE area IS NOT NULL AND area <> ''`; (d) `down()` reverte tudo; (e) `getDescription()` em português. Use `declare(strict_types=1)` como `migrations/Version20260911120000.php`.
  - `config/journal_areas/taxonomy.json`: `{"areas":[{"id":100,"parent":null,"level":1,"slug":"ciencias-agrarias","name":"Ciências Agrárias","college":"Ciências da Vida"}, ...]}` (59 itens, mesma ordem).
  - `CapesTaxonomy` (serviço sem estado, lê o JSON uma vez): `all(): array`, `bySlug(string): ?array`, `byId(int): ?array`, `isValidSlug(string): bool`, `parentOf(string $slug): ?string`, `levelOf(string $slug): int`, `childrenOf(string $slug): array`, `grouped(): array` (grande área → filhas, para selects), `areaLabel(string $slug): string` (maiúsculas com `mb_strtoupper`: `EDUCAÇÃO / CIÊNCIAS HUMANAS`; nível 1 sozinho: `CIÊNCIAS HUMANAS`), `isCanonicalLabel(string $text): bool`.
  - Teste `CapesTaxonomyTest` (KernelTestCase): 59 itens; 9 de nível 1; 50 de nível 2; slugs únicos; `SELECT COUNT(*) FROM capes_areas` = 59 e cada linha igual ao JSON (id, slug, name, parent_id); `areaLabel('educacao')` = `EDUCAÇÃO / CIÊNCIAS HUMANAS`; `areaLabel('ciencias-humanas')` = `CIÊNCIAS HUMANAS`; `areaLabel('interdisciplinar')` = `INTERDISCIPLINAR / MULTIDISCIPLINAR`.
- **Aceite:** `php bin/console doctrine:migrations:migrate -n` e `APP_ENV=test php bin/console doctrine:migrations:migrate -n` sem erro; `php bin/console doctrine:schema:validate` → "[OK] The mapping files are correct" e "[OK] The database schema is in sync" (se o sync acusar só diferenças pré-existentes alheias a esta tarefa, relate-as sem corrigir); `php bin/console dbal:run-sql "SELECT COUNT(*) FROM capes_areas"` → 59; `php bin/console dbal:run-sql "SELECT COUNT(*) FROM qualis_journals WHERE area_raw IS NOT NULL"` → 36.987; `php bin/console dbal:run-sql "SHOW INDEX FROM journal_name_variants"` contém `uniq_journal_variant_norm`; `APP_ENV=test ./vendor/bin/phpunit --filter CapesTaxonomyTest` verde; suíte completa sem novas falhas.
- **Não tocar:** serviços, controllers, templates, `JournalResolverService`.

### T02 — Normalização unificada e utilitário de ISSN
- **Modelo:** `haiku`. **Depende de:** nada.
- **Arquivos:** criar `src/Service/Journal/Issn.php`, `tests/Service/Journal/IssnTest.php`; alterar `src/Service/Thesaurus/JournalResolverService.php` (**somente** o método `normalizeString`, linhas 58-68) e `tests/Service/Thesaurus/JournalResolverServiceTest.php` (adicionar casos).
- **Especificação:**
  - `Issn` (classe `final`, só estáticos): `extractAll(?string $raw, bool $onlyValid = true): array` — encontra `/(\d{4})-?(\d{3}[\dXx])/` em qualquer posição, normaliza para 8 caracteres minúsculos sem hífen, remove repetidos mantendo ordem; com `$onlyValid` descarta dígito verificador inválido; `isValid(string $n8): bool` — soma dos 7 primeiros dígitos × pesos 8..2, resto mod 11, dígito = (11 − resto) mod 11, 10 → `x`; `format(string $n8): string` → `0102-6909` (`X` maiúsculo); `normalizeLoose(?string $raw): ?string` → igual a `QualisJournal::normalizeIssnString` (compatibilidade).
  - `normalizeString` conforme A5.1 (usa `StringNormalizer::normalizeString($str, false)`); **não** altere `cleanKeywords` nem outros métodos.
  - Testes: `IssnTest`: `extractAll('1806-9053, 0102-6909')` → `['18069053','01026909']`; `isValid('01026909')` true; `isValid('01026900')` false; `extractAll('0103-863X')` → `['0103863x']`; `format('0103863x')` → `0103-863X`. `JournalResolverServiceTest::testNormalizeStringAccents`: os 4 casos de A5.1 + o caso existente (`REVISTA DIREITO EM DEBATE` → `revista direito em debate`).
- **Aceite:** `APP_ENV=test ./vendor/bin/phpunit --filter 'IssnTest|JournalResolverServiceTest'` verde; `php -r 'require "vendor/autoload.php"; echo App\Service\Thesaurus\JournalResolverService::normalizeString("História da Educação"), "\n";'` imprime `historia da educacao`.
- **Não tocar:** `StringNormalizer.php`, demais métodos do resolver, banco.

### T03 — Taxonomia no código, formato dos crosswalks, ASJC, regras de título, SciELO-área
- **Modelo:** `haiku` (transcrição fiel do Apêndice C.1, C.5 e C.6). **Depende de:** nada (não precisa do banco).
- **Arquivos:** criar `config/journal_areas/crosswalk_asjc.json`, `config/journal_areas/crosswalk_title_rules.json`, `config/journal_areas/crosswalk_scielo_area.json`, `src/Service/Journal/AreaCrosswalk.php`, `tests/Service/Journal/AreaCrosswalkTest.php`; gerar `config/journal_areas/vocab/asjc.txt` (334 linhas `código<TAB>descrição`) a partir da aba "ASJC Classification Codes" de `docs/banco/lists-to-import/scopus/ext_list_Jul_2026.xlsx` (use um script PHP descartável com PhpSpreadsheet em `/tmp` ou leia via `zipfile`+XML em Python `python3 -I`; não deixe o script no projeto).
- **Especificação:** formato de A6.5. `crosswalk_asjc.json`: **todos os 334 códigos explicitamente**, valor conforme C.1 (exceção se listada, senão padrão do prefixo); `1000 → "interdisciplinar"`. `crosswalk_title_rules.json`: `{"rules":[{"pattern":"...","slug":"..."}]}` com as 31 regras de C.6 (regex sem delimitadores; o serviço usa `~...~u`). `crosswalk_scielo_area.json`: 8 pares de C.5. `AreaCrosswalk` (lê todos os `crosswalk_*.json` existentes na pasta, tolera ausência dos que T04 criará): `map(string $source, string $label): array` → lista de slugs (vazia se `null`/desconhecido), com comparação insensível a caixa/espaços; fontes aceitas: `asjc` (usado por `scopus` e `openalex`), `wos` (usado por `wos` e `scielo_wos`), `doaj`, `latindex`, `scielo_area`, `scielo_desc`; `titleRules(): array`; `matchTitle(string $normalizedTitle): array` → slugs únicos; `knownLabels(string $source): array`; `isKnown(string $source, string $label): bool`. Valida todo slug contra `CapesTaxonomy` (lança `InvalidArgumentException` com o rótulo culpado se inválido).
- **Aceite:** `APP_ENV=test ./vendor/bin/phpunit --filter AreaCrosswalkTest` verde, com testes: 334 códigos cobertos (`vocab/asjc.txt`), `map('asjc','3304')` = `['educacao']`, `map('asjc','3312')` = `['sociologia','ciencia-politica-relacoes-internacionais']`, `map('asjc','1000')` = `['interdisciplinar']`, `map('asjc','2746')` = `['medicina-ii']`, `map('asjc','1105')` = `['biodiversidade']`, `matchTitle('revista de educacao matematica')` contém `ensino`, `educacao` e `matematica-estatistica`; `php -r` carregando o JSON confirma 334 chaves.
- **Não tocar:** banco, entidades, outros crosswalks.

### T04 — Crosswalks WoS, DOAJ, Latindex e descritores SciELO (completos) + vocabulários
- **Modelo:** `sonnet` (exige julgamento). **Depende de:** nada (pode rodar junto com T03; se T03 ainda não existir, crie os JSON no formato de A6.5 mesmo assim; não crie `AreaCrosswalk`).
- **Arquivos:** criar `config/journal_areas/crosswalk_wos.json`, `crosswalk_doaj.json`, `crosswalk_latindex.json`, `crosswalk_scielo_desc.json`, `config/journal_areas/vocab/wos.txt`, `vocab/doaj.txt`, `vocab/latindex.txt`, `vocab/scielo_desc.txt`, `vocab/openalex_subfields.txt`.
- **Especificação:** extraia os vocabulários com um script descartável (Python `python3 -I`, `csv.field_size_limit(10**9)`): WoS = coluna `Web of Science Categories` separada por `|` de todos os `docs/banco/lists-to-import/webofscience/*.csv` exceto `JCR 2025.csv` (363 rótulos); DOAJ = coluna `Subjects` separada por `|` de `docs/banco/lists-to-import/DOAJ/doaj_journalcsv_20260726_2320_utf8.csv` (538); Latindex = coluna `subtemas` separada por vírgula de `docs/banco/lists-to-import/latindex/Indice_indice.csv` (delimitador `;`, BOM) (190); descritores SciELO = `v440` de **todos** os periódicos obtidos com `https://articlemeta.scielo.org/api/v1/journal/identifiers/?collection=scl&limit=1000` e `.../journal/?collection=scl&issn=<código>` (≈ 427 chamadas, `sleep 0.1`; 465 rótulos; se já existir `docs/banco/derived/scielo_articlemeta.json` de T05, leia dele); OpenAlex subfields = `topics[].subfield.id` (número) + `display_name` das páginas `docs/banco/lists-to-import/openalex/openalex_journals/pagina_*.json` (252; só para registro — o mapeamento usa `crosswalk_asjc.json`). Um rótulo por linha, ordem alfabética, UTF-8. Preencha **todos** os rótulos de cada vocabulário nos crosswalks: âncoras e regras de C.2 (WoS), C.3 (DOAJ), C.4 (Latindex, tabela completa), C.5 (SciELO desc); para os não cobertos, decida pelo significado seguindo a política de A6.5 (fora do perfil do CECH, grande área quando a subdivisão não for evidente); `null` só para rótulos sem conteúdo temático. Anote em `_meta.notes` os rótulos em que teve dúvida.
- **Aceite:** cada JSON é válido (`php -r 'json_decode(file_get_contents("config/journal_areas/crosswalk_wos.json"), flags: JSON_THROW_ON_ERROR);'`), todo valor é slug do Apêndice A ou `null`, e `comm -23 <(sort vocab/wos.txt) <(jq -r '.map|keys[]' crosswalk_wos.json | sort)` (e análogos) não retorna nada — ou seja, 100% dos rótulos têm chave. Contagens: wos 363, doaj 538, latindex 190, scielo_desc 465.
- **Não tocar:** código PHP, banco.

### T05 — Fetchers: snapshot OpenAlex e SciELO ArticleMeta
- **Modelo:** `haiku`. **Depende de:** nada.
- **Arquivos:** criar `src/Command/JournalsFetchOpenAlexCommand.php` (`app:journals:evidence:fetch-openalex`), `src/Command/JournalsFetchScieloCommand.php` (`app:journals:evidence:fetch-scielo`).
- **Especificação:** usar `Symfony\Contracts\HttpClient\HttpClientInterface` (injetado). OpenAlex: opção `--dir` (padrão `docs/banco/lists-to-import/openalex/snapshot`), `--manifest` (padrão A6.2), `--limit` (para teste); lê o manifesto, aceita lista em `entries` ou `files`, converte `s3://openalex/` → `https://openalex.s3.amazonaws.com/`, salva em `<dir>/<updated_date=...>/<part>.gz` em streaming (`$client->stream()`), pula arquivo existente com tamanho igual a `meta.content_length`, mostra barra de progresso, grava cópia do manifesto em `<dir>/manifest.json`. SciELO: opção `--out` (padrão `docs/banco/derived/scielo_articlemeta.json`), `--limit`; formato de A6.2 (`issns` com `type` de `v435[].t`), até 3 tentativas por código, `usleep(100000)` entre chamadas; relata total/erros; cria a pasta se não existir.
- **Aceite:** `php bin/console app:journals:evidence:fetch-openalex --limit=1` baixa 1 arquivo e `gzip -t` nele passa; `php bin/console app:journals:evidence:fetch-scielo --limit=5 --out=/tmp/scielo_test.json` gera JSON com 5 chaves, cada uma com `title`, `issns[0].issn`, `area`; `php -l` em ambos os arquivos.
- **Não tocar:** banco, outras pastas de `docs/banco`.

### T06 — Construtor do artefato de evidências
- **Modelo:** `sonnet` (vários formatos de arquivo). **Depende de:** T02 (`Issn`); usa fixtures próprias; o snapshot real é rodado em T20.
- **Arquivos:** criar `src/Service/Journal/JournalEvidenceArtifactBuilder.php`, `src/Service/Journal/JournalEvidenceArtifactReader.php`, `src/Command/JournalsEvidenceBuildCommand.php` (`app:journals:evidence:build`), `tests/Service/Journal/JournalEvidenceArtifactBuilderTest.php`, `tests/fixtures/journal_sources/` (mini-arquivos: `wos/SSCI.csv` com 2 linhas, `scopus/mini.xlsx` com 2 linhas e as colunas reais, `doaj/doaj.csv` 2 linhas, `latindex/Indice_indice.csv` 2 linhas, `openalex/snapshot/updated_date=2026-10-14/part_000.gz` com 2 registros, `scielo_articlemeta.json` com 2 periódicos, `pubmed/J_Medline.txt` com 2 blocos). Use `App\Service\Journal\Issn` (T02) para extrair e validar ISSNs.
- **Especificação:** `JournalEvidenceArtifactBuilder::build(string $sourcesDir, string $derivedDir, string $outFile, ?string $vocabDir, callable $progress = null): array` segue A6.1 (formato do registro, regras por fonte, deduplicação WoS por conjunto de ISSNs, OpenAlex só `type=journal` com ISSN, `share` por subcampo, ordenação determinística, linha `_meta`). Escreve com `gzopen($outFile, 'wb9')`. Se `$vocabDir` informado, grava `vocab/{wos,doaj,latindex,scielo_desc,asjc,openalex_subfields}.txt` (**não** sobrescreva os de T04 se forem iguais; se diferirem, grave e avise). `JournalEvidenceArtifactReader::read(string $file): \Generator` (yield array por linha, pulando `_meta`; `meta(string $file): array`). Comando: argumentos opcionais `--sources` (padrão `docs/banco/lists-to-import`), `--derived` (padrão `docs/banco/derived`), `--out` (padrão `docs/banco/derived/journal_evidence.jsonl.gz`), `--vocab-dir` (padrão `config/journal_areas/vocab`), `--skip=<fonte,...>`. Leitura do xlsx como em `JournalDatabaseImporterService::extractFromExcel` (`:446-512`: `setReadDataOnly`, `setLoadSheetsOnly`). CSVs com `League\Csv\Reader` (BOM, delimitador).
- **Aceite:** `APP_ENV=test ./vendor/bin/phpunit --filter JournalEvidenceArtifactBuilderTest` verde, cobrindo: um registro por fonte com os campos exatos de A6.1; ISSN inválido descartado; WoS deduplicado; OpenAlex `share` somando 1 (±0,01) e `type != journal` ignorado; leitura de volta com o reader; `_meta.sources` com contagens. Rodar o comando real **só** com `--skip=openalex` para medir tempo das demais fontes (deve terminar em < 10 min) e relatar o número de registros por fonte.
- **Não tocar:** banco, crosswalks, entidades.

## Onda 1 (migrations de T01 aplicadas em `cech` e `cech_test`; T02 concluída)

### T07 — Importador: coluna ASJC, fim do "Social Sciences", `normalized_title` nos INSERTs crus
- **Modelo:** `haiku`. **Depende de:** T01.
- **Arquivos:** alterar `src/Service/Thesaurus/JournalDatabaseImporterService.php`, `src/Controller/admin/AdminJournalController.php` (apenas `batchInsertJournals`, `:575-592`), `tests/Service/JournalDatabaseTest.php` (novo teste).
- **Especificação:** em `resolveColumnPositions` (`:709-721`) adicionar `allsciencejournalclassificationcodesasjc` e `asjc` à lista de `area`; remover `toplevelsocialsciences`. Nos SQLs de `UPDATE`/`INSERT` (`:245-304`) acrescentar `normalized_title = ?` (UPDATE: só `COALESCE(NULLIF(normalized_title,""), ?)`; INSERT: sempre) com `StringNormalizer::normalizeString($title, true)`. Em `batchInsertJournals` acrescentar a coluna `normalized_title`. Em todas as criações de variantes do importador (`:328`), antes do `INSERT IGNORE`, pular se já existir `normalized_name` igual em **qualquer** periódico (o índice único agora existe; `INSERT IGNORE` já engole, mas registre a contagem de pulados no retorno como `variationsSkipped`).
- **Aceite:** novo teste em `JournalDatabaseTest` importa um CSV com cabeçalho `"Source Title","ISSN","EISSN","All Science Journal Classification Codes (ASJC)","Top level:\n\nSocial Sciences"` e verifica que `area` do periódico criado é `"3304; 3312"` (não `"Social Sciences"`) e que `normalized_title` está preenchido; `APP_ENV=test ./vendor/bin/phpunit --filter JournalDatabaseTest` verde.
- **Não tocar:** `area` de linhas existentes (nenhum UPDATE em massa), resolver, templates.

### T08 — Resolver por etapas, ambiguidade por família, fila de nomes, `normalize-titles`
- **Modelo:** `sonnet`. **Depende de:** T01, T02.
- **Arquivos:** alterar `src/Service/Thesaurus/JournalResolverService.php`, `src/Service/Indexing/CurriculumNormalizationService.php`, `tests/Service/Thesaurus/JournalResolverServiceTest.php`; criar `src/Service/Journal/JournalNameQueueService.php`, `src/Command/JournalsNormalizeTitlesCommand.php` (`app:journals:normalize-titles`), `tests/Service/Journal/JournalNameQueueServiceTest.php`.
- **Especificação (resolver):**
  - `loadFullMaps()` passa a selecionar também `normalized_title`, `canonical_journal_id`; para cada chave (título exato, título sem parênteses, variante, e a nova chave compacta) guardar `chave → ['families' => [fid => rowId], 'q' => qualis]`; chave compacta = forma do resolver sem os tokens `a o as os e de da do das dos em the of and y la el los las del`, só se restarem ≥ 2 tokens.
  - `lookupInFullMaps`: etapas 1–4 de A5.2; em 2–4, se `count(families) > 1` → retorna `null` e registra o nome em `$this->ambiguousHits[] = nome`; etapa 5 inexistente (remover uso de `keywordMap` como resolução; manter `keywordMap` apenas para `suggestByKeywords(?string $name): ?int`).
  - `lookupInDatabase` (modo on-demand): etapa 1 igual; etapa 2: `SELECT id, qualis, COALESCE(canonical_journal_id,id) f FROM qualis_journals WHERE normalized_title = :t` ∪ `SELECT q.id, q.qualis, COALESCE(q.canonical_journal_id,q.id) f FROM journal_name_variants v JOIN qualis_journals q ON q.id=v.journal_id WHERE v.normalized_name = :t AND v.status = 1` com `:t` na forma do tesauro; etapa 3 idem com o nome sem parênteses; etapa 4: `WHERE normalized_title LIKE :first% AND normalized_title LIKE %:last` (primeiro/último token da chave compacta, forma do tesauro) e comparação da chave compacta em PHP; mesma regra de unicidade de família; **remover** a busca `title LIKE '%kw%'`. Resultado `['j' => id, 'q' => qualis]` como hoje.
  - Métodos novos públicos: `suggestByKeywords(?string $name): ?int`, `consumeAmbiguous(): array`, `isAmbiguousName(string $name): bool`.
- **Especificação (fila):** `JournalNameQueueService::rebuild(): array{pending:int, ambiguous:int, removed:int}` conforme A5.4 — uma consulta por `journal_name_normalized` de artigos `ARTIGO` com `qualis_journal_id IS NULL AND journal_name_normalized <> ''` (contagens; grafia = a mais frequente, obtida numa segunda consulta `GROUP BY journal_name_normalized, journal_name` ordenada por `COUNT(*) DESC`), ISSN mais frequente, 3 títulos (consulta única ordenada por `year DESC`, corte em PHP), sugestão: `suggestByKeywords` → `keywords`; se `isAmbiguousName` → `status='ambiguous'`, senão `pending` para novos; `INSERT ... ON DUPLICATE KEY UPDATE articles_count=VALUES(...), researchers_count=..., raw_name=..., issn_raw=..., sample_titles=..., suggested_journal_id=..., suggestion_method=..., last_seen_at=NOW()` (não altera `status` de linhas já revisadas); depois `UPDATE journal_name_candidates SET articles_count=0 WHERE normalized_name NOT IN (vistos)` e `DELETE ... WHERE status IN ('pending','ambiguous') AND articles_count=0`. Também `reindexArticlesByNormalizedJournalName(string $norm): int` em `CurriculumNormalizationService` (loop sobre os artigos com esse nome aplicando o resolver e gravando `qualis`, `qualis_journal_id`, `indexed_databases`).
  - `indexAllProductionsJournalAndDatabases` (`:324`) e `normalizeResearcher` (`:67`): gravar `journal_name_normalized` do artigo (forma do tesauro de `journal_name`) quando nulo/diferente; ao final chamar `$this->queue->rebuild()`.
  - `app:journals:normalize-titles`: preenche `qualis_journals.normalized_title` (todas as linhas, em lotes de 1.000, via PHP) e `production_items.journal_name_normalized` (todas as linhas com `journal_name` não nulo). Idempotente. Opção `--only-missing`.
- **Aceite:** `php bin/console app:journals:normalize-titles` → `SELECT COUNT(*) FROM qualis_journals WHERE normalized_title IS NULL AND title <> ''` = 0; testes (KernelTestCase, criando dados próprios com títulos únicos e `clearCache()`): (1) nome com acento resolve por título exato; (2) dois periódicos com mesmo título e ISSNs distintos → `resolveJournalIdAndQualis(nome)` retorna `null` e `consumeAmbiguous()` contém o nome; (3) impresso/online na mesma família (canonical) com mesmo título → resolve; (4) "Educação e Sociedade" vs título "EDUCAÇÃO & SOCIEDADE" resolve pela chave compacta; (5) nome sem correspondência não resolve por palavras-chave mas `suggestByKeywords` sugere; (6) `rebuild()` cria candidato com `articles_count` correto e `status='ambiguous'` para o caso 2; modo on-demand testado com `clearCache()` sem `loadFullMaps()`. `APP_ENV=test ./vendor/bin/phpunit --filter 'JournalResolverServiceTest|JournalNameQueueServiceTest'` verde; suíte completa sem novas falhas (o teste existente `testThesaurusVariationAndKeywordResolution` espera `Boletim do IBCCRIM` → `C` por palavras-chave: **ajuste-o** para esperar `null` em `resolveQualis('Boletim do IBCCRIM')` e `assertSame(id, suggestByKeywords('Boletim do IBCCRIM'))`).
- **Não tocar:** `QualisExtension`, importadores Lattes, templates.

### T09 — Auditoria de vínculos (antes/depois)
- **Modelo:** `haiku`. **Depende de:** T01 (coluna `journal_name_normalized` opcional; funciona sem ela).
- **Arquivos:** criar `src/Service/Journal/JournalLinkAuditService.php`, `src/Command/JournalsLinksAuditCommand.php` (`app:journals:links:audit`), `tests/Service/Journal/JournalLinkAuditServiceTest.php`.
- **Especificação:** `snapshot(string $file): array` grava JSON `{"taken_at":..., "articles": {"<id>": {"j": jid|null, "q": qualis|null, "d": ["scopus",...]}}, "totals": {"linked": n, "by_qualis": {...}, "by_db": {...}}}` lendo `production_items` (`item_type='ARTIGO'`) + `qualis_journal_academic_database` + `academic_database.acronym`; `compare(string $file): array` recalcula o estado atual e devolve: iguais, mudaram de periódico, ganharam vínculo, perderam, variação por estrato e por base, 30 maiores pares `(título antes → título depois, n)`, e os três casos de A5.6 (consulta por `journal_name_normalized` ou `LOWER(journal_name)` contendo `ciencia e educacao`/`historia da educacao` vinculados a linha cujo título normalizado contém `turismo visao e acao`; `avaliacao psicologica` → `construcao psicopedagogica`; `educacao matematica em revista` → `caminhos da educacao matematica`) com contagem (meta 0). Comando: `--snapshot=<arquivo>` ou `--compare=<arquivo>`; saída em tabelas `$io->table`; `--json` para imprimir o resultado bruto.
- **Aceite:** `php bin/console app:journals:links:audit --snapshot=var/audit/t09.json` cria o arquivo com 13.469 artigos; `--compare=var/audit/t09.json` logo em seguida mostra 13.469 iguais e 0 mudanças; teste unitário com 3 artigos fictícios cobre `compare`.
- **Não tocar:** nada além dos arquivos criados.

### T10 — Carga de evidências (artefato → banco), legado, regras de título, linhas faltantes
- **Modelo:** `sonnet`. **Depende de:** T01, T02, T03, T06 (reader).
- **Arquivos:** criar `src/Service/Journal/JournalEvidenceLoader.php`, `src/Command/JournalsEvidenceLoadCommand.php` (`app:journals:evidence:load`), `tests/Service/Journal/JournalEvidenceLoaderTest.php`.
- **Especificação:** A6.3 integral. Assinaturas: `load(string $artifact, bool $createMissing, bool $legacy, bool $titleRules, ?callable $progress): array{records:int, evidence_rows:int, journals_hit:int, created:int, legacy_rows:int, title_rule_rows:int}`; `loadLegacy(): int`; `loadTitleRules(): int` (usa `AreaCrosswalk::matchTitle` sobre `display_title` e títulos das linhas da família; precisa de `canonical_journal_id`, por isso pode ser chamado isoladamente com `--title-rules` depois de `families`); `createMissingForArticles(array $recordsByIssn): int` (A5.5: título da fonte pela ordem de A4.6, `issn_imp`/`issn_e` conforme `issn_types` do SciELO ou 1.ª/2.ª posição, `normalized_title`, `display_title(_source)`, vínculo em `qualis_journal_academic_database` quando `src ∈ {scopus,wos,pubmed,scielo,latindex}` — base localizada por **acrônimo**). Inserções em lotes de 500 (`INSERT INTO journal_area_evidence (...) VALUES (...),(...)`). Mapa ISSN→ids via `Issn::extractAll` nas 4 colunas de todas as linhas (uma consulta, ~83 mil linhas, memória ok). `label` do OpenAlex = `code`; `weight` = `share`. Fontes SciELO desdobradas em `scielo_area`, `scielo_wos`, `scielo_desc`. `record_key` = `rec` (ou `md5(issns.title)` se vazio).
- **Aceite:** teste com artefato de fixture (reutilize `tests/fixtures/journal_sources` de T06 ou um `.jsonl.gz` pequeno criado no teste) e periódicos de teste com ISSNs únicos: evidências criadas com pesos somando 1 por registro; recarga não duplica (`DELETE` por fonte); `--legacy` cria evidência para linha com `area='SOCIOLOGIA'` e nenhuma para `'Social Sciences'`; `--create-missing-for-articles` cria linha para artigo com ISSN desconhecido presente no artefato e a vincula à base correta; `APP_ENV=test ./vendor/bin/phpunit --filter JournalEvidenceLoaderTest` verde.
- **Não tocar:** `qualis_journals.area`, resolver, templates.

### T11 — Famílias e nome exibido
- **Modelo:** `sonnet`. **Depende de:** T01, T02, T06 (reader).
- **Arquivos:** criar `src/Service/Journal/JournalFamilyBuilder.php`, `src/Service/Journal/DisplayTitle.php`, `src/Command/JournalsFamiliesCommand.php` (`app:journals:families`), `tests/Service/Journal/JournalFamilyBuilderTest.php`, `tests/Service/Journal/DisplayTitleTest.php`.
- **Especificação:** A4 passos 1–7. `JournalFamilyBuilder::build(string $artifact, bool $dryRun, ?callable $progress): array{families:int, multi:int, joined_by_title:int, ambiguous_titles:array, largest:int, by_db:array}`; union-find em PHP (arrays indexados por ISSN de 8 caracteres); `UPDATE` em lote por família; `by_db` = artigos por base por linha × por família (consulta de A1.3 sem `LIMIT`, somada). `DisplayTitle::titleCase(string $s): string` (regra de A4.6) e `DisplayTitle::choose(array $candidatesBySource, array $lattesNames, string $rowTitle): array{title:string, source:string}` com a ordem de prioridade de A4.6. Comando com `--dry-run`, `--artifact=<arquivo>` (padrão `docs/banco/derived/journal_evidence.jsonl.gz`); sem artefato (arquivo ausente) roda só com os ISSNs do cadastro e avisa.
- **Aceite:** teste (KernelTestCase) cria 3 linhas com título único: A (ISSN X, 2 artigos), B (ISSN Y, 0 artigos), C (sem ISSN, mesmo `normalized_title` de A) e um artefato de fixture com registro `{issns:[X,Y], title:"Nome Bonito"}`: após `build`, B e C têm `canonical_journal_id = A.id`, A tem NULL, `display_title` das três = "Nome Bonito" com `display_title_source` da fonte; rodar duas vezes não muda nada; linha com `canonical_method='manual'` não é alterada. `DisplayTitleTest`: `titleCase('REVISTA BRASILEIRA DE EDUCAÇÃO ESPECIAL')` → `Revista Brasileira de Educação Especial`. `APP_ENV=test ./vendor/bin/phpunit --filter 'JournalFamilyBuilderTest|DisplayTitleTest'` verde.
- **Não tocar:** `production_items`, `journal_areas`, templates.

### T12 — Guarda de variantes e aprendizado de grafias do Lattes
- **Modelo:** `sonnet`. **Depende de:** T01, T02, T08 (usa `journal_name_normalized` e `JournalNameQueueService`).
- **Arquivos:** criar `src/Service/Journal/JournalVariantGuard.php`, `src/Service/Journal/JournalNameLearner.php`, `src/Command/JournalsNamesLearnCommand.php` (`app:journals:names:learn`), `src/Command/JournalsNamesQueueCommand.php` (`app:journals:names:queue`), `tests/Service/Journal/JournalVariantGuardTest.php`, `tests/Service/Journal/JournalNameLearnerTest.php`.
- **Especificação:** `JournalVariantGuard::findConflict(string $normalizedName, ?int $journalId): ?array{kind:string, journalId:int, title:string}` — procura `normalized_name` em `journal_name_variants` e `normalized_title` em `qualis_journals` em **outra família** que não a de `$journalId` (mesma família não é conflito); `addVariant(QualisJournal $journal, string $rawName, string $type): ?JournalVariation` — grava na **linha canônica** da família, retorna `null` se já existe (mesma família) e lança `JournalVariantConflictException` (classe nova no mesmo namespace, com `getConflict()`) se em outra família. `JournalNameLearner::learn(int $minArticles = 2, bool $dryRun = false): array{learned:int, queued:int, conflicts:int, examples:array}` conforme A5.3 (vínculo confirmado por ISSN = algum ISSN do artigo ∈ ISSNs válidos de qualquer linha da família vinculada; `variation_type='lattes'`; 1 artigo → `journal_name_candidates` com `suggested_journal_id` = canônica, `suggestion_method='issn'`, `status='pending'`; conflito → `status='ambiguous'`). `app:journals:names:queue` com `--rebuild` (chama `rebuild()`) e `--stats` (contagens por status). `app:journals:names:learn` com `--dry-run`, `--min-articles`.
- **Aceite:** testes: conflito detectado entre famílias e não detectado dentro da família; `learn` cria variante `lattes` na canônica quando 2 artigos com ISSN da família têm nome desconhecido, e só enfileira quando há 1; rodar `learn` duas vezes não duplica. No banco local, `php bin/console app:journals:names:learn --dry-run` relata ≈ 146 aprendizados / ≈ 223 enfileirados / 0 conflitos (tolerância ±20%; números maiores se T21 já tiver rodado). `APP_ENV=test ./vendor/bin/phpunit --filter 'JournalVariantGuardTest|JournalNameLearnerTest'` verde.
- **Não tocar:** controllers, templates, `EntityMergeService` (fica para T17).

### T13 — Enriquecimento SciELO (ISSNs em linhas sem ISSN)
- **Modelo:** `haiku`. **Depende de:** T01, T02, T05 (formato do JSON).
- **Arquivos:** criar `src/Service/Journal/ScieloIssnEnricher.php`, `src/Command/JournalsScieloEnrichCommand.php` (`app:journals:scielo:enrich`), `tests/Service/Journal/ScieloIssnEnricherTest.php`.
- **Especificação:** A6.2 "Enriquecimento SciELO". `enrich(string $jsonFile, bool $dryRun): array{matched:int, updated:int, unmatched:array, ambiguous:array}`; linhas-alvo = periódicos vinculados à base de acrônimo `scielo` sem ISSN válido (`Issn::extractAll` vazio nas 4 colunas); casamento por forma do resolver do título = forma do resolver de `title` do JSON (exatamente um); grava via entidade (`setIssnImp`, `setIssnE`, `setIssn`) e `display_title`/`display_title_source='scielo'` se `display_title_source` ∈ {NULL,'title'}; `flush` em lotes de 100. Argumento `file` (padrão `docs/banco/derived/scielo_articlemeta.json`), `--dry-run`.
- **Aceite:** teste com JSON de fixture e periódico de teste sem ISSN vinculado a `scielo` → ISSNs preenchidos e `normalized_issn_*` coerentes; linha já com ISSN não é tocada; `APP_ENV=test ./vendor/bin/phpunit --filter ScieloIssnEnricherTest` verde. Com o JSON real (se já existir), `--dry-run` relata ≈ 210 casados e ≤ 10 não casados.
- **Não tocar:** linhas com ISSN, outras bases.

## Onda 2 (onda 1 concluída)

### T14 — Classificador de áreas
- **Modelo:** `sonnet`. **Depende de:** T01, T03, T04, T10, T11.
- **Arquivos:** criar `src/Service/Journal/JournalAreaScorer.php`, `src/Service/Journal/JournalAreaClassifier.php`, `src/Command/JournalsClassifyCommand.php` (`app:journals:classify`), `tests/Service/Journal/JournalAreaScorerTest.php`, `tests/Service/Journal/JournalAreaClassifierTest.php`.
- **Especificação:** `JournalAreaScorer` é **puro** (sem banco): `score(array $evidence): array{primary: ?array{slug,share}, secondary: array<array{slug,share}>, confidence: string, shares: array<string,float>, total_weight: float, sources: array}` onde `$evidence` é lista de `['source'=>..., 'record_key'=>..., 'label'=>..., 'weight'=>float, 'payload'=>array]`; implementa **exatamente** A6.4 (dedup por source+record_key+label; pesos da tabela; OpenAlex `works` em `payload.works`; corte `share<0,10` com renormalização; `ai` com `payload.confidence` e `payload.role` ∈ {primary, secondary}; regra do par `1710`+`3309` de A6.6; decisão em 7 passos; confiança). Dependências: `AreaCrosswalk`, `CapesTaxonomy`. `JournalAreaClassifier::classifyAll(string $runId, ?callable $progress): array{families:int, classified:int, high:int, medium:int, low:int, level1:int, no_evidence:int, locked:int, visible_total:int, visible_classified:int}` e `classifyFamily(int $canonicalId, string $runId): ?array` (A6.4 "Gravação", incluindo a escrita de `qualis_journals.area`/`area_raw` de A3 e o fallback PubMed). Leitura das evidências por família em lotes (`WHERE journal_id IN (...)`), famílias obtidas por `COALESCE(canonical_journal_id,id)`. Comando: `--run-id` (padrão `date('Ymd-Hi')`), `--journal=<id>` (uma família), `--dry-run` (só relata).
- **Aceite:** `JournalAreaScorerTest` (unitário, arrays literais; valores esperados calculados com A6.4): (a) só WoS `Education & Educational Research` → principal `educacao` share 1,0, sem secundárias, `high`; (b) WoS `Education & Educational Research` + um registro Scopus `3304;3312` → `educacao` 0,75; `sociologia` 0,125 e `ciencia-politica-relacoes-internacionais` 0,125 **não** entram como secundárias (< 0,25); `high`; (c) um registro Scopus `3304;2746;1203` → nenhum bucket ≥ 0,34 e três grandes áreas → principal `interdisciplinar`, `medium`, secundárias `educacao` e `linguistica-literatura` (empate em 0,333 resolvido por ordem alfabética do slug); (d1) só OpenAlex `works=5`, `3304` share 0,9 → `educacao`, `total_weight` ≈ 0,23, `low`; (d2) (d1) + Latindex `Educación` → `educacao`, `total_weight` ≈ 1,03, `high`; (e) só `title_rule` `educacao` → `educacao`, `low`; (f) só `ai` principal `direito` com `confidence` 0,9 → `direito`, `medium`; (g) `legacy` `SOCIOLOGIA` + WoS `Anthropology` → principal `antropologia-arqueologia` 0,556, secundária `sociologia` 0,444, `high`; (h) só `scielo_area` `Human Sciences` → principal `ciencias-humanas` (nível 1), `medium`; (i) só OpenAlex `works=1000` com `1710` 0,6 e `3309` 0,4 → `comunicacao-informacao-museologia` 1,0 (regra do par de A6.6), `medium` (fonte única fora da lista de fontes fortes). `JournalAreaClassifierTest` (KernelTestCase): família de 2 linhas com evidências → ambas recebem as mesmas linhas em `journal_areas` e `area` com o rótulo canônico, com o texto anterior em `area_raw`; família sem evidência e com base `pubmed` → `ciencias-da-saude`, `low`; canônica com `areas_locked=1` → nada gravado; rodar duas vezes não duplica. `APP_ENV=test ./vendor/bin/phpunit --filter 'JournalAreaScorerTest|JournalAreaClassifierTest'` verde.
- **Não tocar:** templates, controllers.

### T15 — Lotes de IA: exportação e importação
- **Modelo:** `haiku`. **Depende de:** T01, T03 (slugs), T11 (display_title; funciona sem).
- **Arquivos:** criar `src/Service/Journal/JournalAiBatchService.php`, `src/Command/JournalsAiExportCommand.php` (`app:journals:ai:export`), `src/Command/JournalsAiImportCommand.php` (`app:journals:ai:import`), `tests/Service/Journal/JournalAiBatchServiceTest.php`; copiar o texto do Apêndice D para `PROMPT.md` dentro da pasta de saída (gerado pelo comando, não versionado).
- **Especificação:** A7. `export(string $outDir, int $batchSize = 40, bool $includeMedium = false): array{journals:int, batches:int}` — seleção: famílias com ≥ 1 artigo (consulta de A1.3 sem base e sem `LIMIT`) cuja canônica não tem `journal_areas` ou tem `confidence='low'` (ou `medium` com a opção); ordena por artigos desc; linha JSON com os campos de A7 (`key` = `issn:<primeiro ISSN válido da canônica>` ou `title:<normalized_title>`; `evidence` agrupada por fonte com rótulos crus; `sample_articles` = 5 títulos mais recentes da família). `import(string $file): array{lines:int, imported:int, skipped:array}` — valida (`key` presente, `primary` slug válido, `secondary` ≤ 2 slugs válidos, `confidence` ∈ [0,1]); localiza a família (ISSN → qualquer linha → canônica; `title:` → `normalized_title`); `DELETE FROM journal_area_evidence WHERE source='ai' AND batch=:basename` antes; insere evidência `ai` (`label`=slug, `weight`=1 principal/0,5 secundária, `record_key`=`key`, `payload`={linha, `role`, `confidence`, `model`:`claude-sonnet`}). Linhas inválidas são listadas e puladas, nunca abortam. Acrescente `exportUnclassified(string $outDir, int $batchSize = 250): array{journals:int, batches:int}` (opção `--scope=unclassified` do comando; famílias sem nenhuma linha em `journal_areas`, visíveis ou não; linhas compactas de A7; `PROMPT.md` = Apêndice D + "Há apenas título e país; escolha a área pelo título; `confidence` no máximo 0,6; sem segurança, use `primary: null`."). No import, `primary: null` conta como `unclassifiable` e é pulada; aceite `model` opcional no comando (`--model`, padrão `claude-sonnet`).
- **Aceite:** teste: exporta para pasta temporária a partir de periódicos de teste (um sem área, um `high`) → só o primeiro entra; importa um `.out.jsonl` com 1 linha válida e 1 inválida → 1 evidência `ai`, 1 em `skipped`; reimportar não duplica. `APP_ENV=test ./vendor/bin/phpunit --filter JournalAiBatchServiceTest` verde.
- **Não tocar:** classificador, templates.

### T16 — Página pública `/periodicos`
- **Modelo:** `sonnet`. **Depende de:** T01 (colunas/tabelas; funciona com `canonical_journal_id` todo NULL e `journal_areas` vazia).
- **Arquivos:** criar `src/Service/JournalsByDatabaseService.php`, `src/Controller/pub/JournalController.php`, `templates/pub/journal/index.html.twig`, `templates/pub/journal/database.html.twig`, `templates/pub/journal/_list.html.twig`, `templates/pub/journal/_filter.html.twig`, `templates/pub/journal/_methodology.html.twig`, `tests/Controller/pub/JournalsPageTest.php`; alterar `templates/pub/base.html.twig` (nav desktop `:224-240`, menu móvel `:323-380`, rodapé `:496-501`), `src/Controller/pub/SeoController.php` (`:27-51`).
- **Especificação:** A1 inteira. Serviço (DBAL, imite `StatisticsService::getFigAcademicDatabases` `:1016`): `const BASES = ['scopus','pubmed','scielo','wos','latindex']` (ordem fixa), `const PREVIEW_LIMIT = 25`, `const SOURCE_NOTES = [...]` (A1.1), `getOverview(?int $areaId): array{bases: array<acronym => array{db: array, total_journals:int, total_articles:int, rows: array}>, totals: array{journals:int, articles:int, researchers:int}, updatedAt: \DateTimeImmutable}`, `getDatabaseList(string $acronym, ?int $areaId): array`, `getAreaOptions(): array` (de `capes_areas`, agrupado), `resolveAreaSlug(string $slug): ?array` (id, name, level). SQL de A1.3 com os chips em segunda consulta; `updatedAt` = `MAX(updated_at)` de `journal_areas` ou `now`. Controller: duas rotas de A1.1; `area` validada como em A1.2 (regex + existência → 404). Templates: cabeçalho como `department/index.html.twig:9-20`; select como `search.html.twig:25`; `<ol class="list-none ...">`; `data-base="{{ acronym }}"`; sem selo Qualis; barra `style="width: {{ pct }}%"`; chips `<span class="journal-areas ...">`; textos exatamente de A1.4; **nenhuma ocorrência** das palavras `rank`, `ranking`, `melhores` nem de `loop.index` nos templates; classes utilitárias como `top-0` são permitidas. Nav: link "Periódicos" entre "Indicadores" e "Departamentos" nos três lugares (no menu móvel, bloco igual aos vizinhos com `sl-icon name="journal-text"`, subtítulo "Onde os docentes publicam", destaque quando `'journals' in app.request.attributes.get('_route')`). Sitemap: `/periodicos` e as 5 bases.
- **Aceite:** `tests/Controller/pub/JournalsPageTest.php` (WebTestCase, imite `PublicRoutesTest`): `/periodicos` 200 com `h1` = `Periódicos em que o CECH publica`; HTML contém `data-base="scopus"`, `"pubmed"`, `"scielo"`, `"wos"`, `"latindex"` **nessa ordem** (`strpos` crescente); `assertStringNotContainsStringIgnoringCase('ranking')` e `assertDoesNotMatchRegularExpression('/\brank\b/i')`; `/periodicos?area=educacao` 200; `/periodicos?area=nao-existe` 404; `/periodicos/scopus` 200; `/periodicos/foo` 404; `/sitemap.xml` contém `/periodicos`; `/` contém `Periódicos` no menu. `php bin/console lint:twig templates` ok; `php bin/console debug:router | grep periodicos` mostra as 2 rotas; `time curl -s -o /dev/null http://localhost:8000/periodicos` < 1 s com servidor local (`symfony serve --no-tls` ou `php -S localhost:8000 -t public`), `PAGE_CACHE_ENABLED` indiferente.
- **Não tocar:** `StatisticsService`, `indicadores`, admin.

### T17 — Admin: áreas CAPES no formulário/índice/exports, conflitos de variantes, merge seguro
- **Modelo:** `sonnet`. **Depende de:** T01, T12 (`JournalVariantGuard`), T14 (`classifyFamily`).
- **Arquivos:** alterar `src/Controller/admin/AdminJournalController.php` (`new` `:108-166`, `edit` `:169-269`, `exportCsv` `:312-338`, `importCsv` `:362-431`, `importThesaurus` `:433-507`), `templates/admin/journal/form.html.twig`, `templates/admin/journal/index.html.twig` (`:140`), `src/Service/Thesaurus/EntityMergeService.php` (`mergeJournals` `:291-358`), `src/Service/Thesaurus/JournalDatabaseExporterService.php` (`:72,110,168`), `tests/Controller/admin/AdminJournalTest.php` (novos testes), `tests/Service/Thesaurus/EntityMergeServiceTest.php` (criar).
- **Especificação:** A6.8 e A5.3. Formulário: bloco "Área de Avaliação / Conhecimento (CAPES)" logo abaixo do input `area` (checkboxes `areas[]` por grande área via `CapesTaxonomy::grouped()`, select `area_primary`, checkbox `areas_locked`, botão "Voltar ao automático" com form POST próprio para rota nova `app_admin_journal_areas_unlock` `/{id}/areas/unlock` com CSRF `unlock_areas_<id>`), lista de evidências (`JournalAreaEvidenceRepository::findBy(['journal'=>...], ['source'=>'ASC'])`), resultado automático atual; campo "Nome de exibição" (`display_title`); input `area` conforme A6.8 (mesmo rótulo de hoje, `readonly` e ignorado no servidor quando a família tem áreas; `area_raw` exibido abaixo); em linha membro, bloco somente leitura com link para a canônica. Gravação das áreas: na **canônica** da família e réplica nas demais linhas (`JournalAreaRepository::replaceManual(QualisJournal $canonical, array $slugs, string $primarySlug)`, que também grava `area`/`area_raw` como em A3), `areas_locked=1` na canônica. Variações do formulário: para cada linha do textarea e `new_variation`, `JournalVariantGuard::findConflict` → se conflito, `addFlash('danger', 'A variação «X» já pertence ao periódico #id «Título». Remova-a daqui ou mescle os periódicos.')` e re-renderiza sem gravar; `variation_type='manual'` para variantes novas do formulário. Índice: área principal + ponto de confiança (cores de A6.8) com fallback `journal.area`. Exports: colunas `area_capes_primary`, `areas_capes`. `importCsv`: coluna opcional `areas_capes` → manual travado. `importThesaurus`: usar o guard (pular conflitos, contar). `mergeJournals`: as cinco correções de A5.3 (artigos, canônicos, evidências, `journal_areas`, reaproveitar variantes) **antes** do `remove`; `flush` único.
- **Aceite:** `AdminJournalTest`: editar periódico marcando `educacao` + `sociologia`, principal `educacao` → 2 linhas em `journal_areas` (`method='manual'`, `is_primary` certo), `areas_locked=1`, `area` = `EDUCAÇÃO / CIÊNCIAS HUMANAS`; POST em `/areas/unlock` → 0 manuais e `areas_locked=0`; cadastrar variante que já existe em outro periódico → resposta 200 com a mensagem de conflito e nenhuma variante nova; índice mostra o nome da área. `EntityMergeServiceTest`: merge de A (2 artigos) em B → artigos apontam para B, variantes de A migradas sem duplicata, A removido. `APP_ENV=test ./vendor/bin/phpunit --filter 'AdminJournalTest|EntityMergeServiceTest'` verde; `php bin/console lint:twig templates` ok.
- **Não tocar:** rotas existentes (nomes/paths), página pública.

### T18 — Admin: fila de revisão de nomes
- **Modelo:** `sonnet`. **Depende de:** T01, T08 (`reindexArticlesByNormalizedJournalName`), T12 (`JournalVariantGuard`).
- **Arquivos:** criar `src/Controller/admin/AdminJournalNameController.php` (prefixo `/admin/journal-names`, `#[IsGranted('ROLE_ADMIN')]`), `templates/admin/journal_names/index.html.twig`, `tests/Controller/admin/AdminJournalNameTest.php`; alterar `templates/admin/base.html.twig` (link após `:127-128`).
- **Especificação:** A5.4 "Tela admin". Rotas: `GET /` (`app_admin_journal_name_index`, filtros `status` (padrão `pending`), `q` (busca em `raw_name`), paginação 50, ordem `articles_count DESC`); `POST /{id}/accept` (`app_admin_journal_name_accept`, campo `journal_id`; CSRF `name_accept_<id>`): localiza periódico → canônica → `JournalVariantGuard::addVariant($journal, $candidate->getRawName(), 'curated')` (conflito → flash danger) → `reindexArticlesByNormalizedJournalName` → `status='accepted'`, `resolved_journal_id`, `reviewed_at`, `reviewed_by` = username → flash success com nº de artigos revinculados; `POST /{id}/reject` (`app_admin_journal_name_reject`, CSRF) → `status='rejected'`; `POST /{id}/reopen` → `pending`. Template: imite o cabeçalho e a tabela de `templates/admin/journal/index.html.twig`; colunas: nome bruto, ISSN visto, artigos/docentes, amostra (3 títulos truncados), sugestão (título + link `app_admin_journal_edit`; método), formulário inline com `journal_id` pré-preenchido com a sugestão + link "procurar" para `path('app_admin_journal_index', {search: raw_name})` em nova aba, botões Aceitar/Rejeitar; abas por status com contagens. Menu: item "Fila de nomes" com `sl-icon name="inbox"`, sem contador; o número de pendentes (`JournalNameCandidateRepository::countByStatus('pending')`) aparece no cabeçalho da própria tela.
- **Aceite:** `AdminJournalNameTest` (login como em `AdminJournalTest::getAuthenticatedClient`): cria candidato pendente e periódico de teste; GET 200 mostra o nome; POST accept → variante `curated` na canônica, candidato `accepted`, artigo de teste com aquele `journal_name_normalized` agora vinculado; POST reject → `rejected`. `APP_ENV=test ./vendor/bin/phpunit --filter AdminJournalNameTest` verde; `lint:twig` ok.
- **Não tocar:** `AdminJournalController`, página pública.

### T19 — Overrides, relatório e orquestrador
- **Modelo:** `haiku`. **Depende de:** T08, T10, T11, T12, T14, T15, T17 (`JournalAreaRepository::replaceManual`); chama comandos existentes.
- **Arquivos:** criar `src/Service/Journal/JournalOverridesImporter.php`, `src/Command/JournalsOverridesImportCommand.php` (`app:journals:overrides:import`), `src/Command/JournalsReportCommand.php` (`app:journals:report`), `src/Command/JournalsPipelineCommand.php` (`app:journals:pipeline`), `tests/Service/Journal/JournalOverridesImporterTest.php`.
- **Especificação:** overrides conforme A6.8 (quatro tipos; localizar periódico por `issn` → qualquer linha → família, ou `title` exato na forma do tesauro; mais de uma família → pular e relatar; `area` → `JournalAreaRepository::replaceManual` + lock; `name` → `JournalVariantGuard::addVariant(..., 'curated')` + `reindexArticlesByNormalizedJournalName` + marcar candidato `accepted` se existir; `family` → `canonical_journal_id`/`canonical_method='manual'` em todas as linhas dos ISSNs, canônica = `canonical_issn`; `display_title` → gravar com `source='manual'` em toda a família). Relatório (`app:journals:report`): tabela com os indicadores de A9 (famílias visíveis total/classificadas/% ; por confiança; linhas com evidência; linhas com área; artigos por base por família; variantes por tipo; fila por status; 3 casos de A5.6) — só leitura. Pipeline: opções `--derived` (padrão `docs/banco/derived`), `--skip=<passo,...>`, `--only=<passo,...>`, `--from=<passo>`; passos nomeados, nesta ordem (= passos 2–17 de A8): `normalize-titles`, `audit-before`, `scielo-enrich`, `evidence-load`, `families-first`, `index`, `families`, `learn`, `overrides-names`, `index-again`, `audit-after`, `title-rules`, `classify`, `ai-import`, `overrides-areas`, `classify-again`, `report`, `cache-clear`; cada passo executa o comando correspondente via `$this->getApplication()->find('app:...')->run(new ArrayInput([...]), $output)`, para no primeiro código ≠ 0, imprime o tempo de cada passo; o passo `ai-import` lê `ai/*.out.jsonl` e `ai_titles/*.out.jsonl`; passos cujo arquivo de entrada não existe (`overrides/*.jsonl`, `ai*/*.out.jsonl`) são pulados com aviso; migrations **não** são executadas pelo pipeline (o usuário roda à mão).
- **Aceite:** teste do importador de overrides com os 4 tipos sobre periódicos de teste; `php bin/console app:journals:report` roda no banco local sem erro; `php bin/console app:journals:pipeline --only=normalize-titles,audit-before --derived=/tmp/vazio` executa só esses dois passos e termina com sucesso (prova do encadeamento). `APP_ENV=test ./vendor/bin/phpunit --filter JournalOverridesImporterTest` verde.
- **Não tocar:** lógica dos comandos chamados.

## Onda 3 — execução dos dados nesta máquina (onda 2 concluída; migrations aplicadas)

Todas as tarefas desta onda escrevem só em `docs/banco/derived/`, `var/audit/`, `config/journal_areas/vocab/` e no banco local `cech`. Nenhuma altera código, exceto onde dito.

### T20 — Dados 1: snapshot OpenAlex, SciELO e artefato
- **Modelo:** `haiku`. **Depende de:** T05, T06.
- **Passos:** (1) `php bin/console app:journals:evidence:fetch-openalex` (≈ 364 MB; se a rede falhar, repita: o comando pula arquivos completos); (2) `php bin/console app:journals:evidence:fetch-scielo` (≈ 8 min); (3) `php bin/console app:journals:evidence:build` (sem `--skip`); (4) `gzip -t docs/banco/derived/journal_evidence.jsonl.gz`; (5) `gunzip -c docs/banco/derived/journal_evidence.jsonl.gz | head -1` e anotar `_meta.sources`.
- **Aceite:** registros esperados (±5%): scopus ≈ 48.800, wos ≈ 31.000 (após dedupe), doaj ≈ 22.000, latindex ≈ 4.300, openalex ≈ 200.000–270.000 (só `journal` com ISSN), scielo ≈ 423, pubmed ≈ 37.000; arquivo < 40 MB; `git status` mostra alterados apenas `config/journal_areas/vocab/*.txt` (se o builder regravou com conteúdo diferente de T04, relate as diferenças — os crosswalks de T04 precisam cobrir os rótulos novos; nesse caso abra a pendência para T04 em vez de editar JSON).
- **Não tocar:** código, banco.

### T21 — Dados 2: vínculos antes/depois
- **Modelo:** `sonnet` (interpreta o diff). **Depende de:** T07–T13, T20.
- **Passos:** `php bin/console app:journals:normalize-titles` → `php bin/console app:journals:links:audit --snapshot=var/audit/before.json` → `php bin/console app:journals:scielo:enrich` → `php bin/console app:journals:evidence:load docs/banco/derived/journal_evidence.jsonl.gz --create-missing-for-articles --legacy` → `php bin/console app:journals:families` → `php bin/console app:index:journals` → `php bin/console app:journals:links:audit --compare=var/audit/before.json`.
- **Aceite / relatar:** artigos iguais/mudaram/ganharam/perderam (esperado na simulação: ≈ 231 mudam, ≈ 124 ganham, ≈ 35 + ≈ 300 perdem temporariamente — os ≈ 300 do antigo palpite por palavras-chave voltam em T23); os 3 casos de A5.6 com contagem 0; variação por estrato Qualis e por base; linhas criadas por `--create-missing-for-articles` (≈ 150) listadas; SciELO: casados ≈ 210. Qualquer par (antes → depois) com ≥ 10 artigos deve ser explicado em uma linha (ex.: "Paidéia online → Paidéia impresso: mesma família"). Se algo parecer errado (ex.: um periódico perdendo > 20 artigos sem explicação), **pare e relate** sem corrigir código.
- **Não tocar:** código.

### T22 — Dados 3: famílias e nomes
- **Modelo:** `haiku`. **Depende de:** T21.
- **Passos:** `php bin/console app:journals:families` → `php bin/console app:journals:names:learn --dry-run` (anotar) → `php bin/console app:journals:names:learn` → `php bin/console app:journals:names:queue --rebuild` → `php bin/console app:journals:names:queue --stats` → consultas: `SELECT COUNT(*) FROM qualis_journals WHERE canonical_journal_id IS NOT NULL`; `SELECT display_title_source, COUNT(*) FROM qualis_journals GROUP BY 1`; `SELECT id, title, display_title FROM qualis_journals WHERE id IN (11322,65044,23639,23600,65138)` (ids locais, só para conferência).
- **Aceite / relatar:** famílias com > 1 linha ≈ 6.000–6.500; maior família ≤ 6; artigos por família por base (SciELO ≥ 2.200); `display_title` das linhas 23639 e 23600 = "Revista Brasileira de Ciências Sociais" e "Revista de Antropologia" (ou variação com acentos de fonte em português); variantes `lattes` criadas ≈ 146; fila: pendentes/ambíguos/sugeridos por ISSN.
- **Não tocar:** código.

### T23 — Revisão única da fila de nomes (converter acertos em variantes curadas)
- **Modelo:** `sonnet` (julgamento; 1 ou 2 subagentes). **Depende de:** T22.
- **Passos:** (1) exportar para revisão: `php bin/console dbal:run-sql "SELECT c.normalized_name, c.raw_name, c.issn_raw, c.articles_count, c.suggestion_method, q.id AS sug_id, q.title AS sug_title, JSON_EXTRACT(c.sample_titles,'$') AS samples FROM journal_name_candidates c LEFT JOIN qualis_journals q ON q.id=c.suggested_journal_id WHERE c.status='pending' AND c.articles_count >= 2 ORDER BY c.articles_count DESC"` (≈ 300 linhas; salve como `docs/banco/derived/review/names_pending.tsv`); (2) para cada linha decidir: **aceitar a sugestão** (nome e sugestão são o mesmo periódico — aceite só quando o título sugerido é claramente a mesma revista; "Revista Logos (FEUC)" → "Logos (Lithuania)" é **rejeitar**), **apontar outro periódico** (procure por `SELECT id, title, issn FROM qualis_journals WHERE normalized_title LIKE '%PALAVRA%' LIMIT 20`), ou **deixar pendente** (sem informação); (3) escrever `docs/banco/derived/overrides/names.jsonl` com uma linha `{"type":"name","name":"<raw_name>","journal":{"issn":"<ISSN do periódico escolhido>"}}` (ou `{"title":"<título exato>"}` se não houver ISSN) para cada aceito; (4) `php bin/console app:journals:overrides:import docs/banco/derived/overrides/names.jsonl` → `php bin/console app:index:journals` → `php bin/console app:journals:links:audit --compare=var/audit/before.json`.
- **Aceite / relatar:** nº de nomes aceitos/rejeitados/pendentes; artigos vinculados ao final ≥ 11.516; os pares aceitos listados no relatório (nome → periódico) para o usuário conferir. Nomes com `suggestion_method='issn'` (1 artigo, ISSN confirma) podem ser aceitos em bloco com a sugestão.
- **Não tocar:** código, banco diretamente (só via comandos).

### T24 — Classificação determinística e exportação dos lotes de IA
- **Modelo:** `haiku`. **Depende de:** T14, T15, T22.
- **Passos:** `php bin/console app:journals:evidence:load docs/banco/derived/journal_evidence.jsonl.gz --title-rules` (só regras de título; precisa de `display_title`) → `php bin/console app:journals:classify` → `php bin/console app:journals:report` → `php bin/console app:journals:ai:export --out=docs/banco/derived/ai`.
- **Aceite / relatar:** cobertura das famílias visíveis (esperado ≥ 85% antes da IA), distribuição por confiança, nº de periódicos exportados e de lotes (esperado ≈ 500–800 periódicos, 13–20 lotes), `PROMPT.md` presente.
- **Não tocar:** código.

### T25 — Classificação por IA (N subagentes em paralelo)
- **Modelo:** `sonnet`, **um subagente a cada 3 lotes** (≈ 6 subagentes). **Depende de:** T24.
- **Instrução para cada subagente:** "Leia `docs/banco/derived/ai/PROMPT.md` e siga-o para os arquivos `batch_00X.jsonl`, `batch_00Y.jsonl`, `batch_00Z.jsonl` da mesma pasta, gravando `batch_00X.out.jsonl` etc. Não leia outros arquivos do projeto. Valide ao final: mesmo número de linhas de entrada e saída, todo JSON válido (`python3 -I -c 'import json,sys; [json.loads(l) for l in open(sys.argv[1])]' <arquivo>`)."
- **Aceite:** para cada lote, `wc -l batch_00X.jsonl batch_00X.out.jsonl` iguais; JSON válido; slugs válidos (o importador recusa inválidos e lista).
- **Não tocar:** nada além dos `.out.jsonl`.

### T26 — Importação da IA e reclassificação
- **Modelo:** `haiku`. **Depende de:** T25.
- **Passos:** `php bin/console app:journals:ai:import docs/banco/derived/ai/*.out.jsonl` → `php bin/console app:journals:classify` → `php bin/console app:journals:report`.
- **Aceite / relatar:** linhas importadas/puladas por lote; cobertura das famílias visíveis ≥ 97%; distribuição por confiança; famílias visíveis ainda sem área listadas (`SELECT ... LIMIT 50`).
- **Não tocar:** código.

### T26b — Segunda passada por título: restante do cadastro
- **Modelo:** coordenação pelo orquestrador; subagentes `haiku`, 5 lotes por subagente. **Depende de:** T26.
- **Passos:** `php bin/console app:journals:report` (anotar a cobertura total) → `php bin/console app:journals:ai:export --scope=unclassified --out=docs/banco/derived/ai_titles --batch-size=250` → cada subagente segue `docs/banco/derived/ai_titles/PROMPT.md` nos seus lotes e grava `batch_00X.out.jsonl` → `php bin/console app:journals:ai:import docs/banco/derived/ai_titles/*.out.jsonl --model=claude-haiku` → `php bin/console app:journals:classify` → `php bin/console app:journals:report`.
- **Aceite / relatar:** linhas totais com área ≥ 98%; nº de `unclassifiable`; amostra de 20 títulos classificados nesta passada, para conferência.
- **Não tocar:** código.

### T27 — Revisão de qualidade (amostra) e overrides de área
- **Modelo:** `sonnet`. **Depende de:** T26.
- **Passos:** (1) montar `docs/banco/derived/review/sample.tsv` com: os 30 primeiros periódicos de cada uma das 5 bases (consulta de A1.3 por família, `LIMIT 30`) + 100 famílias visíveis aleatórias (`ORDER BY RAND()` com `SET @seed`/`RAND(42)`), colunas: canônica id, nome exibido, ISSNs, bases, artigos, área principal, secundárias, confiança, 3 títulos de artigos; (2) julgar cada linha: grande área certa? área certa (principal ou secundária)? (3) calcular as duas taxas; (4) para cada discordância, uma linha em `docs/banco/derived/overrides/areas.jsonl` (`{"type":"area","journal":{"issn":"..."},"primary":"...","secondary":[...],"lock":true}`); (5) `php bin/console app:journals:overrides:import docs/banco/derived/overrides/areas.jsonl` → `php bin/console app:journals:classify` → `php bin/console app:journals:report`.
- **Aceite / relatar:** taxas antes das correções (metas: ≥ 90% grande área, ≥ 80% área); lista das correções; se a taxa de grande área ficar < 85%, listar os padrões de erro (ex.: "ASJC 3300 sozinho") como pendência de ajuste do crosswalk/pesos para o arquiteto — **não** altere código nem JSON.
- **Não tocar:** código, crosswalks.

## Onda 4 — fechamento

### T28 — Suíte completa, lints, documentação operacional
- **Modelo:** `haiku`. **Depende de:** T16–T19, T27.
- **Arquivos:** alterar `docs/DEPLOY_E_PRODUCAO.md` (nova seção "4.6 Periódicos por base, áreas CAPES e tesauro" com os comandos de A8 e a lista de artefatos), `docs/ADMIN_GUIDE.md` (seções "Áreas CAPES no cadastro de periódicos" e "Fila de nomes de periódicos", 10–20 linhas cada), `docs/THESAURUS.md` (parágrafo sobre tipos `lattes`/`curated`/`manual` e a trava única).
- **Passos:** `APP_ENV=test ./vendor/bin/phpunit --testdox` (toda verde; relatar totais), `php bin/console lint:twig templates`, `php bin/console lint:yaml config`, `php bin/console doctrine:schema:validate`, `php bin/console app:cache:clear`, abrir `http://localhost:8000/periodicos`, `/periodicos?area=educacao`, `/periodicos/scielo` com `curl -s | grep -c 'data-base'` e conferir visualmente (ou por `grep`) os textos de A1.4.
- **Aceite:** 0 falhas; documentação atualizada; relatório final com os números de A9 preenchidos.

### T29 — Roteiro de produção (executado pelo usuário, não por subagente)
1. Local: `git status` limpo após commit do usuário; `scp` dos artefatos (A8) para `runcloud@104.236.71.49:/home/runcloud/webapps/ufscar-cech/docs/banco/derived/` (criar a pasta com `ssh ... mkdir -p .../docs/banco/derived/{ai,overrides}`).
2. Servidor: `cd /home/runcloud/webapps/ufscar-cech && git pull origin main && /RunCloud/Packages/php84rc/bin/php /usr/local/bin/composer install --no-dev --optimize-autoloader`.
3. `/RunCloud/Packages/php84rc/bin/php bin/console doctrine:migrations:migrate --no-interaction --env=prod` — se abortar por `normalized_name` duplicado, rodar a consulta indicada na mensagem, mesclar os periódicos no admin e repetir.
4. `./build.sh && /RunCloud/Packages/php84rc/bin/php bin/console cache:clear --env=prod`.
5. `/RunCloud/Packages/php84rc/bin/php bin/console app:journals:pipeline --derived=docs/banco/derived --env=prod` (≈ 20–40 min; executa os passos 2–17 de A8 e imprime a auditoria antes/depois e o relatório).
6. Conferir `https://<domínio>/periodicos` e `/admin/journal-names`; guardar a saída do pipeline (`| tee var/audit/pipeline-$(date +%F).log`).
7. Reexecuções futuras (novas listas, novos currículos): repetir só os passos afetados (`evidence:build` local → `scp` → `evidence:load`, `families`, `classify`, `cache:clear`); a fila de nomes é alimentada automaticamente a cada importação de currículo.

---

## B2. Estimativa de esforço (desenvolvimento + dados)

| Grupo | Subagentes | Tokens por subagente (entrada+saída, estimados) | Custo (US$) |
|---|---|---|---|
| Sonnet de código (T01, T04, T06, T08, T10, T11, T12, T14, T16, T17, T18) | 11 | 200–350k | 11 × ≈ 1,3 ≈ 14 |
| Haiku de código (T02, T03, T05, T07, T09, T13, T15, T19) | 8 | 100–200k | 8 × ≈ 0,05 ≈ 0,4 |
| Execução de dados Haiku (T20, T22, T24, T26, T28) | 5 | 50–120k | ≈ 0,2 |
| Execução/revisão Sonnet (T21, T23 ×2, T27) | 4 | 150–300k | ≈ 4 |
| IA de classificação Sonnet (T25) | ≈ 6 | ≈ 36k | ≈ 0,7 |
| Segunda passada por título Haiku (T26b) | ≈ 4–8 | ≈ 70k | ≈ 0,2 |
| **Total** | **≈ 34 subagentes** | ≈ 6–8 M tokens | **≈ US$ 20 (faixa 15–30)** |

Preços usados (dossiê): Haiku 5.5 US$ 0,10/0,50 por MTok; Sonnet 5.5 US$ 2/10. Tempo de parede: ondas 0–2 em ≈ 3 rodadas paralelas; onda 3 é sequencial (T20 ≈ 30 min de download; T21–T27 ≈ 2–3 h incluindo revisões).

## B3. Lista de verificação do arquiteto (feita antes da entrega)

Para cada tarefa foi conferido que um agente sem outro contexto tem: arquivos exatos a criar/alterar com linhas de referência; assinaturas e formatos de entrada/saída; padrões a imitar (arquivo:linha); comandos de aceite com resultado esperado; e a lista do que não tocar. Pontos que exigiram decisão explícita para evitar adivinhação: ids de `academic_database` nunca usados (acrônimos); ids fixos de `capes_areas` definidos aqui; formato único de crosswalk; normalização única (forma do tesauro vs. forma do resolver) nomeada e usada consistentemente; ordem de execução dos dados fixada em A8 e repetida nas tarefas; critério de "família visível" = ≥ 1 artigo.

