# journal-area-classification Specification

## Purpose
Dar a cada família de revistas uma área de avaliação da CAPES (principal e até duas secundárias) a partir de evidências: categorias das listas externas (Web of Science, Scopus/ASJC, DOAJ, Latindex, OpenAlex, SciELO, PubMed), o texto de área original, regras de título e lotes de IA processados fora do servidor. Cobre a taxonomia, o artefato de evidências, a pontuação, a gravação, a curadoria manual e o tesauro de categorias de revistas.

## Requirements
### Requirement: Taxonomia CAPES
O sistema SHALL usar como vocabulário final a tabela `capes_areas`, com ids fixos: 9 grandes áreas (nível 1, ids 100 a 900) e 50 áreas de avaliação (nível 2), cada uma com slug, nome, colégio e ordem, igual a `config/journal_areas/taxonomy.json`. O rótulo canônico de uma área SHALL ser "NOME DA ÁREA / NOME DA GRANDE ÁREA" em maiúsculas; o de uma grande área, só o nome dela em maiúsculas.

#### Scenario: Rótulo de uma área de avaliação
- **WHEN** a área principal de uma família é `educacao`
- **THEN** o rótulo canônico é "EDUCAÇÃO / CIÊNCIAS HUMANAS"

#### Scenario: Rótulo de uma grande área
- **WHEN** a principal é a grande área `ciencias-da-saude`
- **THEN** o rótulo canônico é "CIÊNCIAS DA SAÚDE"

### Requirement: Artefato de evidências construído fora do servidor
O comando `app:journals:evidence:build` SHALL ler as listas das bases e o cadastro do SciELO e gravar um gzip JSONL com uma linha `_meta` (data e fontes lidas) e uma linha por registro (fonte, código, ISSNs válidos, título, rótulos, extras), em ordem determinística e sem ids do banco; fonte ausente ou pulada (`--skip`) não entra em `_meta.sources`. Os comandos `app:journals:evidence:fetch-openalex` e `app:journals:evidence:fetch-scielo` SHALL baixar as fontes auxiliares.

#### Scenario: Fonte ausente
- **WHEN** a pasta não tem a lista do Latindex
- **THEN** o artefato é gravado sem registros do Latindex e sem `latindex` em `_meta.sources`

#### Scenario: Snapshot do OpenAlex já baixado
- **WHEN** um arquivo do manifesto do OpenAlex já existe completo na pasta de destino
- **THEN** `app:journals:evidence:fetch-openalex` não o baixa de novo

#### Scenario: Cadastro do SciELO
- **WHEN** `app:journals:evidence:fetch-scielo` roda
- **THEN** grava um JSON com título, ISSNs com tipo, áreas, categorias WoS, descritores e situação de cada periódico da coleção `scl`
- **AND** nenhum passo do pipeline de produção chama o OpenAlex ou o SciELO

### Requirement: Carga das evidências
O comando `app:journals:evidence:load [artefato] [--dry-run]` SHALL gravar em `journal_area_evidence` um rótulo por linha nas linhas do cadastro que têm algum ISSN do registro ou, sem nenhuma, nas linhas sem ISSN válido de título normalizado igual. O peso é 1/n por rótulo e, no OpenAlex, a participação (só a partir de 0,10). O SciELO vira as fontes `scielo_area`, `scielo_wos` e `scielo_desc`. A carga MUST NOT alterar `area` nem outro dado do cadastro.

#### Scenario: Recarga do artefato
- **WHEN** a carga roda de novo com um artefato novo
- **THEN** as evidências das fontes presentes em `_meta.sources` são substituídas, sem duplicar
- **AND** as evidências `ai`, `legacy` e `title_rule` ficam como estavam

#### Scenario: Simulação
- **WHEN** a carga roda com `--dry-run`
- **THEN** mostra as contagens da carga real e não grava nada

### Requirement: Evidência legada e regras de título
Com `--legacy`, a carga SHALL substituir as evidências `legacy`: o texto de área original (`area_raw` ou `area`) que é o nome de uma área CAPES, ou que o tesauro confiável (capes, cnpq, correções manuais) dá a uma só área, em maiúsculas ou numa grafia conhecida, vira evidência de peso 1. Com `--title-rules`, SHALL substituir as `title_rule`: as regras de título aplicadas ao nome exibido e aos títulos da família, na canônica, com peso 1/n.

#### Scenario: Área do Qualis em maiúsculas
- **WHEN** o texto original de área é "SAUDE COLETIVA"
- **THEN** a linha ganha evidência `legacy` que aponta para Saúde Coletiva

#### Scenario: Texto que não vale como legado
- **WHEN** o texto original é "Social Sciences" ou "Sociología"
- **THEN** não vira evidência `legacy`

#### Scenario: Regra de título
- **WHEN** o nome exibido de uma família contém "Ensino"
- **THEN** a canônica ganha evidência `title_rule` com o slug `ensino`

### Requirement: Peso das evidências
O sistema SHALL deduplicar as evidências da família por fonte, registro e rótulo, converter rótulos em slugs pelos crosswalks (com as correções manuais do tesauro de categorias; um rótulo pode dar dois slugs, com peso dividido) e pesar cada fonte: wos, scopus e scielo_wos 1,0; scielo_desc 0,9; legacy, doaj e latindex 0,8; scielo_area e title_rule 0,5; OpenAlex `min(1, log10(works)/3)`; cada linha `ai` 0,9 (principal) ou 0,45 (secundária) vezes a confiança.

#### Scenario: Revista pequena no OpenAlex
- **WHEN** a revista tem 10 trabalhos no OpenAlex
- **THEN** a evidência OpenAlex pesa cerca de 0,33

#### Scenario: Ciência da informação
- **WHEN** a família tem os códigos ASJC 1710 e 3309 nas fontes Scopus ou OpenAlex
- **THEN** o 1710 conta para Comunicação, Informação e Museologia, e não para Computação

#### Scenario: Evidência repetida nas linhas da família
- **WHEN** a mesma evidência está gravada em duas linhas da família
- **THEN** ela conta uma vez

### Requirement: Escolha da área principal e das secundárias
O sistema SHALL escolher como principal a área de avaliação de maior parcela, se tiver ao menos 34%; senão, a grande área de maior parcela com ao menos 34% (ou a filha dela com ao menos 20%); senão, `interdisciplinar` se as áreas com ao menos 15% forem de duas ou mais grandes áreas, ou a maior área. As secundárias SHALL ser até duas outras com ao menos 25%, áreas de avaliação antes de grandes áreas; empate pela parcela e depois pelo slug.

#### Scenario: Área dominante
- **WHEN** Educação tem 70% da evidência e Psicologia 30%
- **THEN** a principal é Educação e Psicologia é secundária

#### Scenario: Evidência espalhada
- **WHEN** nenhuma área chega a 34% e as que passam de 15% são de Ciências Humanas e de Engenharias
- **THEN** a principal é Interdisciplinar

### Requirement: Confiança da classificação
A confiança SHALL ser `high` com parcela da principal de ao menos 50%, soma de pesos de ao menos 1,0 e duas ou mais fontes (ou uma só entre wos, scopus, scielo_wos e legacy); `medium` com parcela de ao menos 34% e soma de ao menos 0,5; senão `low`. Interdisciplinar por espalhamento é `medium`. Só regras de título MUST dar `low`; só IA dá `medium` com confiança da IA de ao menos 0,8, senão `low`.

#### Scenario: Só regra de título
- **WHEN** a única evidência da família é uma regra de título
- **THEN** a confiança é `low`

#### Scenario: Só IA confiante
- **WHEN** a única evidência é uma resposta de IA com confiança 0,9 para uma área
- **THEN** a confiança é `medium`, nunca `high`

### Requirement: Gravação da classificação
O comando `app:journals:classify [--run-id] [--journal=ID] [--dry-run]` SHALL, por família, pular a de canônica com `areas_locked = 1`, trocar as áreas `auto` de todas as linhas pela principal e secundárias (com parcela, confiança, `run_id` e data, sem duplicar área manual) e gravar em `area` o rótulo canônico, copiando antes para `area_raw` o texto que não é rótulo canônico. Família sem classificação volta `area` ao texto de `area_raw`.

#### Scenario: Família travada
- **WHEN** a canônica tem `areas_locked = 1`
- **THEN** nada muda nas linhas da família e ela conta como travada no resumo

#### Scenario: Texto original preservado
- **WHEN** uma linha tem `area = "Education & Educational Research"` e a família é classificada em Educação
- **THEN** `area_raw` recebe "Education & Educational Research" e `area` recebe "EDUCAÇÃO / CIÊNCIAS HUMANAS"

#### Scenario: Família só com vínculo ao PubMed
- **WHEN** a família não tem evidência mapeada mas está ligada à base `pubmed`
- **THEN** recebe Ciências da Saúde como principal, com confiança `low` e nota 0

### Requirement: Curadoria manual das áreas no admin
No formulário da canônica em `/admin/journals/{id}/edit`, o sistema SHALL oferecer as 59 áreas agrupadas, a principal e a caixa "Travar". Salvar com áreas marcadas e seleção alterada (ou trava nova) SHALL substituir as áreas da família por linhas manuais, travar a canônica e gravar o rótulo em `area`. Salvar sem mexer MUST NOT travar. Numa linha membro, o bloco é só leitura, com o aviso "Agrupado sob #id «Título» — edite as áreas lá".

#### Scenario: Curadoria gravada
- **WHEN** o administrador marca Educação como principal e Psicologia e salva
- **THEN** a família recebe as duas áreas como manuais, a canônica fica travada e `area` passa a "EDUCAÇÃO / CIÊNCIAS HUMANAS"

#### Scenario: Área desconhecida
- **WHEN** o formulário envia um slug que não existe na taxonomia
- **THEN** aparece "Área CAPES desconhecida: «…»." e nada é gravado

#### Scenario: Texto livre reconhecido
- **WHEN** a família não tem áreas, nenhuma é marcada e o administrador muda o texto de `area` para um rótulo que o tesauro de categorias reconhece
- **THEN** as áreas reconhecidas são gravadas como curadoria manual travada e um aviso diz qual área foi reconhecida

### Requirement: Volta ao automático e evidências no formulário
O botão "Voltar ao automático" (`POST /admin/journals/{id}/areas/unlock`, com CSRF) SHALL apagar as áreas manuais da família, destravar a canônica e reclassificar só essa família, informando o resultado. O formulário SHALL listar, só para leitura, até 300 evidências da família (fonte, rótulo, peso, lote), o total delas, as áreas atuais com confiança e método e o texto original de área.

#### Scenario: Voltar ao automático
- **WHEN** o administrador clica em "Voltar ao automático" numa família curada
- **THEN** as áreas manuais somem e o aviso mostra a nova área automática e a confiança

#### Scenario: Sem evidência suficiente
- **WHEN** a família destravada não tem evidência mapeada
- **THEN** o aviso diz que a área voltou ao valor original

### Requirement: Lotes de classificação por IA, fora do servidor
O comando `app:journals:ai:export [--out] [--scope=visible|unclassified] [--batch-size] [--include-medium]` SHALL exportar em JSONL, com `PROMPT.md`, as famílias com artigos sem área ou de confiança `low` (`medium` com a opção), ou, no escopo `unclassified`, todas as famílias sem área, só com título e país. O comando `app:journals:ai:import <arquivos…> [--model]` SHALL gravar cada resposta válida só como evidência `ai` da família.

#### Scenario: Reimportar um lote
- **WHEN** o mesmo arquivo `.out.jsonl` é importado de novo
- **THEN** as evidências `ai` daquele lote são substituídas, sem duplicar

#### Scenario: Resposta sem área
- **WHEN** uma linha de resposta traz `"primary": null`
- **THEN** ela é pulada como não classificável e o resumo mostra o motivo

#### Scenario: Dois arquivos com o mesmo nome
- **WHEN** a importação recebe dois arquivos de pastas diferentes com o mesmo nome
- **THEN** o comando termina com erro antes de gravar qualquer lote

### Requirement: Tesauro de categorias de revistas
O sistema SHALL manter em `capes_area_variants` o que cada rótulo de categoria significa em áreas CAPES, por vocabulário (capes, cnpq, asjc, wos, doaj, latindex, scielo_area, scielo_desc, livre), com status `active`, `ignored` ou `pending`. A busca SHALL devolver os slugs de um só vocabulário, na prioridade: correção manual, capes, cnpq, wos, latindex, doaj, scielo_desc, scielo_area, asjc, livre; texto composto é lido inteiro, depois por `|` e `;`, depois por `,`.

#### Scenario: Rótulo com vírgula
- **WHEN** o texto é "Mathematics, Applied | Mathematics"
- **THEN** resolve para Matemática / Probabilidade e Estatística

#### Scenario: Texto composto não reconhecido inteiro
- **WHEN** o texto é "Medicina, Salud pública"
- **THEN** resolve para as três Medicinas (CNPq) e para Saúde Coletiva (Latindex)

### Requirement: Sincronização do tesauro de categorias
O comando `app:journals:areas:thesaurus [--dry-run]` SHALL semear o tesauro com a taxonomia e os arquivos de crosswalk (um valor nulo vira `ignored`), apagar as sementes que saíram dos arquivos, recalcular quantas revistas usam cada rótulo e abrir como `pending` os rótulos usados e não reconhecidos. Rótulo com correção manual num vocabulário MUST NOT ser tocado nele. O comando é idempotente e roda numa transação.

#### Scenario: Correção manual vence a semente
- **WHEN** um rótulo do WoS tem uma linha manual com outra área
- **THEN** o comando não recria nem altera a semente desse rótulo no WoS

#### Scenario: Rótulo usado e desconhecido
- **WHEN** um trecho de `area_raw` com 3 letras ou mais não é reconhecido
- **THEN** ele vira um rótulo `pending` do vocabulário `livre`, com o número de revistas que o usam

### Requirement: Tela do tesauro de categorias
A tela `/admin/journal-categories`, só para `ROLE_ADMIN`, SHALL listar os rótulos nas abas Ativos, Pendentes e Ignorados, com busca, filtros por vocabulário e área e 50 por página, e permitir, por POST com CSRF: definir área, ignorar, cadastrar sinônimo `livre` e excluir correção (só manual). Toda ação SHALL avisar que a classificação só muda na próxima execução de `app:journals:classify` ou do pipeline.

#### Scenario: Definir a área de um rótulo
- **WHEN** o administrador define a área de um rótulo pendente
- **THEN** o rótulo vira uma linha manual ativa com essa área, no lugar das outras linhas dele

#### Scenario: Excluir uma semente
- **WHEN** o administrador tenta excluir uma linha que não é manual
- **THEN** aparece o aviso de que só correções manuais podem ser excluídas e nada muda

#### Scenario: Exportação
- **WHEN** o administrador exporta o tesauro
- **THEN** recebe um CSV com `;`, UTF-8 com BOM, e os rótulos que começam com `=`, `+`, `-` ou `@` saem precedidos de apóstrofo

### Requirement: Áreas curadas por arquivo
A linha `area` de `app:journals:overrides:import` SHALL gravar como curadoria manual da família localizada por ISSN ou título a área `primary` e as `secondary`, aceitas por slug, nome, rótulo canônico ou rótulo do tesauro. A família fica travada, salvo com `"lock": false`, que deixa a classificação automática voltar a valer. Área desconhecida MUST pular a linha sem alterar o banco.

#### Scenario: Trava por padrão
- **WHEN** o arquivo traz `{"type":"area","journal":{"issn":"0101-7330"},"primary":"educacao","secondary":["sociologia"]}`
- **THEN** a família recebe Educação e Sociologia como manuais e fica travada

#### Scenario: Área secundária desconhecida
- **WHEN** uma secundária não é reconhecida
- **THEN** a linha é pulada com "área secundária desconhecida"
