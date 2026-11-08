# journal-catalog Specification

## Purpose
Página pública `/periodicos`, que lista os periódicos em que os docentes do CECH publicaram artigos registrados no Lattes, agrupados pelas cinco bases de indexação (Scopus, PubMed, SciELO, Web of Science e Latindex) e por uma lista das bases somadas, com filtro por área CAPES, selo Qualis e nota metodológica. A página mede presença nas bases e quantidade de artigos, sem juízo de qualidade sobre os periódicos.

## Requirements
### Requirement: Rotas públicas de periódicos
O sistema SHALL servir, só pelo método GET, a visão geral em `/periodicos` (H1 "Periódicos em que o CECH publica"), a lista completa das bases somadas em `/periodicos/geral` e a lista completa de uma base em `/periodicos/{base}`, em que `base` MUST ser `scopus`, `pubmed`, `scielo`, `wos` ou `latindex`. As listas completas não têm paginação e trazem um link de volta para `/periodicos` que mantém a área escolhida.

#### Scenario: Página de uma base conhecida
- **WHEN** o visitante abre `/periodicos/scielo`
- **THEN** a resposta é 200, com o título "Periódicos indexados em SciELO com artigos do CECH" e uma única seção com todos os periódicos da base
- **AND** há um link "Todas as bases" para `/periodicos`

#### Scenario: Base desconhecida
- **WHEN** o visitante abre `/periodicos/foo`
- **THEN** a resposta é 404

### Requirement: Periódico contado por família e por artigo distinto
O sistema SHALL tratar como um único periódico cada família de linhas do cadastro (`COALESCE(canonical_journal_id, id)`), exibida pela linha canônica, e SHALL contar só os itens `ARTIGO` ligados a qualquer linha da família, deduplicados pelo DOI em minúsculas ou, sem DOI, por ano + título em minúsculas. Os docentes SHALL ser contados de forma distinta.

#### Scenario: Coautoria entre docentes do Centro
- **WHEN** dois docentes registram o mesmo artigo (mesmo DOI) no mesmo periódico
- **THEN** o periódico mostra 1 artigo e 2 docentes

#### Scenario: Linhas impressa e online da mesma revista
- **WHEN** duas linhas da mesma família têm artigos ligados
- **THEN** a revista aparece uma só vez, pela linha canônica, com os artigos distintos das duas linhas somados

#### Scenario: Item que não é artigo
- **WHEN** um capítulo de livro tem um `journal_name` igual ao nome de um periódico
- **THEN** ele não entra em nenhuma contagem da página

### Requirement: Seções da visão geral
A visão geral SHALL mostrar, nesta ordem, a lista "Todas as bases somadas" e as seções Scopus, PubMed, SciELO, Web of Science e Latindex, cada uma com os 25 primeiros periódicos e com o total de periódicos e de artigos distintos da seção. Quando a seção tiver mais de 25 periódicos no filtro atual, SHALL mostrar o link "Ver todos os N periódicos" para a lista completa, mantendo a área escolhida. As cinco seções aparecem mesmo para base ausente de `academic_database`, com o nome padrão.

#### Scenario: Base com mais de 25 periódicos
- **WHEN** a seção de uma base tem 30 periódicos com o filtro `?area=educacao`
- **THEN** a visão geral mostra os 25 primeiros e o link "Ver todos os 30 periódicos" para `/periodicos/{base}?area=educacao`

#### Scenario: Ordem das seções
- **WHEN** a visão geral é exibida
- **THEN** a lista das bases somadas vem primeiro, seguida de Scopus, PubMed, SciELO, Web of Science e Latindex

### Requirement: Presença nas bases e lista das bases somadas
Uma família SHALL entrar na seção de uma base quando qualquer linha dela estiver ligada à base em `qualis_journal_academic_database`. A lista das bases somadas SHALL trazer uma única vez cada família presente em pelo menos uma das cinco bases, com os selos das bases em que está, na ordem fixa, e com os artigos distintos da família, sem somar base a base. Periódicos fora das cinco bases MUST NOT aparecer nas listas.

#### Scenario: Periódico presente em três bases
- **WHEN** uma família ligada a Scopus, Web of Science e SciELO tem 10 artigos distintos
- **THEN** ela aparece uma vez na lista das bases somadas, com 10 artigos e os selos das três bases
- **AND** aparece também em cada uma das três seções

#### Scenario: Vínculo numa linha membro
- **WHEN** só a linha online de uma família está ligada ao Latindex
- **THEN** a família inteira aparece na seção Latindex, pela canônica

### Requirement: Ordem por quantidade, sem numeração nem vocabulário de ranking
O sistema SHALL ordenar cada lista pela quantidade de artigos distintos, decrescente, com empate pela ordem alfabética do nome exibido e depois pelo id. O sistema MUST NOT numerar posições nem usar as palavras `ranking` ou `rank` no HTML público dessas páginas. Cada linha SHALL ter uma barra com largura proporcional ao maior valor da seção, com no mínimo 1%.

#### Scenario: Empate na quantidade de artigos
- **WHEN** dois periódicos da mesma seção têm 5 artigos cada
- **THEN** aparecem na ordem alfabética do nome exibido

#### Scenario: Lista sem pódio
- **WHEN** qualquer uma das páginas é exibida
- **THEN** a lista é uma lista ordenada sem marcadores numéricos, sem "1º" nem "#1", e o HTML não contém "ranking" nem "rank"

### Requirement: Formulário de filtro por área
O sistema SHALL oferecer um formulário GET com um único campo `area`: "Todas as áreas" e, para cada grande área, um grupo com a opção "<grande área> — todas" seguida das suas áreas de avaliação, na ordem de `capes_areas.sort_order`. O botão "Filtrar" MUST funcionar sem JavaScript; com JavaScript, a escolha na lista já envia, e o envio bloqueia o botão com o texto "Filtrando..." e ignora um segundo envio.

#### Scenario: Envio com JavaScript
- **WHEN** o visitante escolhe uma área na lista
- **THEN** o formulário é enviado, o botão mostra "Filtrando..." e um segundo envio é ignorado

#### Scenario: Volta pelo navegador
- **WHEN** o visitante volta à página pelo histórico do navegador
- **THEN** o botão e a lista voltam ao estado da área da página exibida

### Requirement: Regra do filtro por área
Com uma área de avaliação, o sistema SHALL listar as famílias que têm essa área, principal ou secundária; com uma grande área, as que têm a própria grande área ou qualquer uma das suas áreas. Cada família MUST aparecer uma só vez, os totais do cabeçalho e das seções SHALL valer só para o filtro, e família sem área CAPES nunca entra num filtro.

#### Scenario: Filtro por grande área
- **WHEN** o visitante abre `?area=ciencias-humanas`
- **THEN** entram as famílias com Educação, Psicologia ou outra área de Ciências Humanas e as que têm só a grande área, cada uma uma vez

#### Scenario: Filtro por área de avaliação
- **WHEN** o visitante abre `?area=educacao`
- **THEN** entram só as famílias que têm Educação entre as suas áreas

#### Scenario: Seção vazia no filtro
- **WHEN** o filtro deixa uma base sem periódicos
- **THEN** a seção mostra "Nenhum periódico desta base com artigos na área selecionada." (na lista somada, "Nenhum periódico das bases com artigos na área selecionada.")

### Requirement: Validação de parâmetros e URL canônica
O sistema SHALL aceitar na query string apenas `area`, com um slug que case `^[a-z0-9-]{1,80}$` e exista em `capes_areas`. Um valor fora desse formato, inexistente ou que não seja texto MUST responder 404. Qualquer outra query string (parâmetro extra, `area` vazio) SHALL ser redirecionada com 301 para a URL canônica, que traz só `area` e só quando há filtro.

#### Scenario: Área inexistente
- **WHEN** o visitante abre `/periodicos?area=xyz`
- **THEN** a resposta é 404

#### Scenario: Área enviada como lista
- **WHEN** o visitante abre `/periodicos?area[]=educacao`
- **THEN** a resposta é 404

#### Scenario: Parâmetro de rastreamento
- **WHEN** o visitante abre `/periodicos/scopus?area=educacao&utm_source=x`
- **THEN** a resposta é um 301 para `/periodicos/scopus?area=educacao`

#### Scenario: Área vazia
- **WHEN** o visitante abre `/periodicos?area=`
- **THEN** a resposta é um 301 para `/periodicos`

### Requirement: Nome exibido e ISSNs da linha
Cada linha SHALL mostrar como nome o `display_title` da canônica ou, sem ele, o título da linha com as entidades HTML decodificadas, em caixa de título quando está todo em maiúsculas ou todo em minúsculas. Abaixo, SHALL mostrar os ISSNs da canônica com dígito verificador válido, formatados como `0000-000X`, sem repetição, na ordem impresso, genérico e eletrônico.

#### Scenario: Título em maiúsculas sem nome exibido
- **WHEN** a canônica tem o título "REVISTA BRASILEIRA DE EDUCAÇÃO ESPECIAL" e nenhum `display_title`
- **THEN** a linha mostra "Revista Brasileira de Educação Especial"

#### Scenario: ISSN com dígito verificador errado
- **WHEN** uma coluna de ISSN da canônica tem um número cujo dígito verificador não confere
- **THEN** esse número não aparece na linha

### Requirement: Áreas e contadores da linha
Cada linha SHALL mostrar os chips das áreas CAPES da família, com a principal primeiro e destacada e as demais por nota e pela ordem da taxonomia, e, à direita, "N artigos" e "N docentes" no singular ou plural. Na lista das bases somadas, a linha SHALL mostrar também os selos das bases em que a família está.

#### Scenario: Família sem área
- **WHEN** a família não tem nenhuma linha em `journal_areas`
- **THEN** a linha não mostra chip de área

#### Scenario: Área principal e secundária
- **WHEN** a família tem Educação como principal e Psicologia como secundária
- **THEN** o chip de Educação vem primeiro, destacado, seguido do de Psicologia

### Requirement: Selo Qualis da família
O sistema SHALL mostrar como selo Qualis o estrato gravado nos artigos da família (`production_items.qualis`); com estratos diferentes, SHALL valer o de mais artigos distintos e, no empate, o mais alto (o primeiro em ordem alfabética). Família cujos artigos não têm estrato SHALL ficar sem selo. O selo é informativo e MUST NOT influir na ordem da lista.

#### Scenario: Estratos diferentes na família
- **WHEN** uma família tem 3 artigos com Qualis A2 e 1 com B1
- **THEN** o selo mostra "Qualis A2"

#### Scenario: Empate entre estratos
- **WHEN** uma família tem 2 artigos A1 e 2 artigos A2
- **THEN** o selo mostra "Qualis A1"

### Requirement: Totais do cabeçalho
Na visão geral, o cabeçalho SHALL mostrar os periódicos com artigos (famílias com algum artigo ligado, estejam ou não nas cinco bases), os artigos distintos e os docentes distintos de todos os artigos ligados a periódicos. Nas páginas de uma base e das bases somadas, SHALL mostrar o total de periódicos da lista, a soma dos artigos distintos das famílias listadas e os docentes distintos que publicaram nelas.

#### Scenario: Periódico fora das cinco bases
- **WHEN** uma família com artigos não está ligada a nenhuma das cinco bases
- **THEN** ela conta nos totais da visão geral, mas não aparece em nenhuma lista

#### Scenario: Docente com vários periódicos na mesma base
- **WHEN** um docente publicou em 3 periódicos da Scopus
- **THEN** a página da Scopus o conta uma vez no total de docentes

### Requirement: Transparência metodológica e data dos dados
Toda página de periódicos SHALL mostrar a caixa "Como esta página é feita" acima das listas e a seção "Metodologia e fontes" no fim, com as fontes, as datas das listas, as regras do selo Qualis e da lista somada e a frase "Dados atualizados em DD/MM/AAAA". Cada seção de base SHALL mostrar a nota de data da sua lista. A data dos dados SHALL ser a atualização mais recente de `journal_areas` ou, sem áreas gravadas, a do dia.

#### Scenario: Data dos dados
- **WHEN** a atualização mais recente de `journal_areas` foi em 31/10/2026
- **THEN** a seção "Metodologia e fontes" mostra "Dados atualizados em 31/10/2026"

#### Scenario: Nota da lista da base
- **WHEN** a seção da Scopus é exibida
- **THEN** ela mostra "Lista de títulos de jul./2026" abaixo dos contadores
