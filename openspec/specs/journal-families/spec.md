# journal-families Specification

## Purpose
Agrupar em famílias as linhas do cadastro de revistas (`qualis_journals`) que são a mesma revista (impresso e online, linhas importadas só por título, listas sem ISSN), sem apagar nem mesclar nada, e dar a cada família uma linha canônica e um nome exibido. Cobre também a mesclagem física feita pelo administrador e as famílias curadas por arquivo. Todas as telas contam por família: `COALESCE(canonical_journal_id, id)`.

## Requirements
### Requirement: Agrupar sem mesclar
O comando `app:journals:families [--dry-run] [--artifact=…]` SHALL gravar a família de cada linha em `canonical_journal_id` (nulo na canônica, o id da canônica nos membros) e o método em `canonical_method` (`issn` ou `title`), sem apagar, mover ou mesclar linhas. O comando é determinístico e idempotente, MUST falhar sem gravar quando o artefato de evidências não existe e, com `--dry-run`, só mostra o relatório.

#### Scenario: Impresso e online
- **WHEN** duas linhas têm ISSNs que caem no mesmo grupo
- **THEN** passam a ser a mesma família e nenhuma das duas é apagada

#### Scenario: Artefato ausente
- **WHEN** o arquivo indicado em `--artifact` não existe
- **THEN** o comando termina com erro e nada é gravado

#### Scenario: Relatório
- **WHEN** o comando termina
- **THEN** mostra famílias, famílias com mais de uma linha, linhas unidas por título, linhas ambíguas, linhas em conflito e os artigos por base contados por linha e por família
- **AND** os vínculos com bases de qualquer linha passam a valer para todas as linhas da família

### Requirement: Agrupamento pelos ISSNs das listas externas
O sistema SHALL extrair os ISSNs válidos (dígito verificador correto) das quatro colunas de cada linha, mesmo vários num campo, e formar grupos externos unindo os ISSNs de cada registro do artefato, exceto registros com mais de 3 ISSNs ou com 3 ISSNs contrariados por duas ou mais fontes. Uma linha cujos ISSNs caem num só grupo SHALL entrar nele mesmo com título diferente; um ISSN de linha MUST NOT unir grupos por si só.

#### Scenario: ISSN certo e título errado
- **WHEN** uma linha tem o ISSN de "Revista Brasileira de Ciências Sociais" e outro título
- **THEN** ela entra no grupo desse ISSN

#### Scenario: Registro com ISSNs demais
- **WHEN** um registro externo lista 5 ISSNs
- **THEN** ele não une nenhum grupo

### Requirement: Grupos compatíveis e linhas em conflito
Quando os ISSNs de uma linha caem em dois ou mais grupos, o sistema SHALL uni-los se forem compatíveis (títulos parecidos, nota de pelo menos 0,7, e países que não se excluem). Se forem incompatíveis, a linha MUST ficar em conflito: nenhuma união passa por ela, e ela entra só no grupo de título mais parecido se a nota passar do limiar sem empate; senão fica isolada. As linhas em conflito vão para o relatório.

#### Scenario: Par impresso e online não declarado
- **WHEN** uma linha tem dois ISSNs em grupos de títulos parecidos e do mesmo país
- **THEN** os dois grupos se unem numa família

#### Scenario: Grupos de revistas diferentes
- **WHEN** os ISSNs de uma linha caem em grupos de títulos diferentes
- **THEN** os grupos não se unem e a linha aparece entre as linhas em conflito

### Requirement: Linhas sem ISSN unidas pelo título exato
Uma linha sem ISSN válido SHALL entrar num grupo, com `canonical_method = 'title'`, quando o seu `normalized_title`, sem remover parênteses, for igual ao de linhas com ISSN ou ao título de um registro do artefato e isso apontar para exatamente um grupo com linha no cadastro. Com mais de um grupo possível, a linha MUST ficar isolada e contada como ambígua.

#### Scenario: Linha do SciELO sem ISSN
- **WHEN** uma linha sem ISSN tem o mesmo título normalizado de uma linha com ISSN de um único grupo
- **THEN** ela entra na família desse grupo

#### Scenario: Título de dois grupos
- **WHEN** o título exato aponta para dois grupos diferentes
- **THEN** a linha fica sozinha e é contada como ambígua

### Requirement: Linha canônica e famílias manuais
A canônica de cada família SHALL ser a linha com mais artigos, depois a com mais ISSNs válidos, depois a de menor id. Linhas com `canonical_method = 'manual'` MUST NOT ser alteradas pela reconstrução e valem como pontes, sem conferir títulos nem países; a família que contém uma delas SHALL usar a canônica manual.

#### Scenario: Escolha da canônica
- **WHEN** uma família tem uma linha com 46 artigos e outra com nenhum
- **THEN** a linha com 46 artigos é a canônica

#### Scenario: Decisão manual preservada
- **WHEN** a família foi definida pela curadoria (`canonical_method = 'manual'`)
- **THEN** uma nova execução de `app:journals:families` mantém a canônica e os membros

### Requirement: Nome exibido da família
O sistema SHALL gravar em todas as linhas da família, menos nas de `display_title_source = 'manual'`, o primeiro título disponível nesta ordem: fontes casadas pelo ISSN (scielo, doaj, latindex, scopus, wos, openalex, pubmed); a grafia de `journal_name` mais frequente nos artigos da família, com pelo menos 2 ocorrências (`lattes`); o título da canônica em caixa de título (`title`).

#### Scenario: Fonte em português primeiro
- **WHEN** o SciELO dá "Paidéia (Ribeirão Preto)" e a Scopus dá "Paideia"
- **THEN** o nome exibido é "Paidéia (Ribeirão Preto)"

#### Scenario: Caixa de título
- **WHEN** só resta o título "REVISTA BRASILEIRA DE EDUCAÇÃO ESPECIAL"
- **THEN** o nome exibido é "Revista Brasileira de Educação Especial", com conectivos em minúsculas e algarismos romanos em maiúsculas

### Requirement: Nome exibido manual
O campo "Nome de exibição" do formulário de periódico no admin SHALL gravar o texto (até 500 caracteres) em todas as linhas da família com `display_title_source = 'manual'`, que a reconstrução MUST NOT sobrescrever; deixado em branco, SHALL apagar o nome para que seja recalculado. A linha `display_title` do arquivo de overrides SHALL fazer o mesmo para a família localizada por ISSN ou título.

#### Scenario: Nome definido no admin
- **WHEN** o administrador grava "Educação & Sociedade" como nome de exibição
- **THEN** todas as linhas da família recebem esse nome como manual e `app:journals:families` não o troca

#### Scenario: Nome apagado
- **WHEN** o administrador apaga o nome de exibição
- **THEN** a família fica sem nome exibido até a próxima reconstrução, que o calcula de novo

### Requirement: Família curada por arquivo
A linha `family` de `app:journals:overrides:import` SHALL juntar numa família todas as linhas que têm algum dos ISSNs listados (todos válidos), com a canônica sendo a linha do `canonical_issn` (no empate, a de mais artigos e depois a de menor id), todas com `canonical_method = 'manual'`. Os membros de linhas que deixaram de ser canônicas SHALL passar à nova canônica, e o nome exibido não manual vem da canônica.

#### Scenario: Família curada
- **WHEN** o arquivo traz `{"type":"family","issns":["0103-863X","1982-4327"],"canonical_issn":"0103-863X"}`
- **THEN** as linhas com esses ISSNs formam uma família manual com canônica na linha de 0103-863X

#### Scenario: ISSN da canônica fora da lista
- **WHEN** `canonical_issn` não está em `issns`
- **THEN** a linha é pulada e reportada, sem alterar o banco

### Requirement: Mesclagem física no admin
A mesclagem em `/admin/journals/merge` (POST com CSRF, periódico principal e fontes) SHALL, numa transação, passar ao principal os artigos, os membros das famílias das fontes (como `manual`), as evidências sem duplicar, as variantes, as bases e os candidatos da fila, apagar as áreas das fontes, gravar os títulos das fontes como variantes pela trava, remover as fontes e recalcular Qualis e bases dos artigos da família. Falha MUST desfazer tudo.

#### Scenario: B1 mesclado num A2
- **WHEN** o administrador mescla uma fonte B1 num principal A2
- **THEN** os artigos da fonte passam ao principal e mostram A2

#### Scenario: Depois da mesclagem
- **WHEN** a mesclagem é gravada
- **THEN** as áreas CAPES da família são reclassificadas (ou mantidas, se travadas) e o cache das páginas públicas é limpo
- **AND** se um desses passos falhar, aparece um aviso com o comando que o refaz

#### Scenario: Erro na mesclagem
- **WHEN** a mesclagem falha no meio
- **THEN** nada é gravado e aparece "Erro na mesclagem: …"
