# academic-databases Specification

## Purpose
Cadastro das bases de indexação (`academic_database`), do cadastro de revistas com o estrato Qualis (`qualis_journals`) e dos vínculos revista-base (`qualis_journal_academic_database`): telas do admin, importação das listas de cada base, carga inicial do Bibliomap, importações e exportações do catálogo e do tesauro, e os selos de Qualis e de bases exibidos nos itens.

## Requirements

### Requirement: Cadastro de bases de indexação
A tela `/admin/academic-databases`, só para `ROLE_ADMIN`, SHALL listar as bases com a quantidade de revistas ligadas e busca, e permitir criar e editar nome, sigla, URL, URL da lista, descrição, formatos, colunas de assinatura e logotipo, com token CSRF. O logotipo MUST ser PNG, JPG, WebP, GIF ou SVG pelo conteúdo e vai para `/images/databases/`. A sigla SHALL ser gravada em minúsculas e MUST ser única. A exclusão, por POST com CSRF, remove a base e os seus vínculos.

#### Scenario: Sigla repetida
- **WHEN** o administrador cadastra uma base com a sigla de outra
- **THEN** aparece "Já existe uma base de indexação com a sigla …" e nada é gravado

#### Scenario: Revistas de uma base
- **WHEN** o administrador clica em ver as revistas de uma base
- **THEN** abre a lista de periódicos filtrada pela sigla da base (`/admin/journals/?database=<sigla>`)

#### Scenario: Logotipo que não é imagem
- **WHEN** o administrador envia como logotipo um arquivo PHP com nome `.png`
- **THEN** nada é gravado e a tela informa os tipos aceitos

### Requirement: Presença numa base vale para a família
O sistema SHALL tratar a presença numa base como da revista inteira: o vínculo de qualquer linha com uma base SHALL ser replicado em todas as linhas da família ao fim da importação de uma lista, da reconstrução das famílias, da mesclagem e da edição no admin. No formulário do periódico, desmarcar uma base SHALL tirá-la de todas as linhas da família.

#### Scenario: Base marcada numa linha membro
- **WHEN** o administrador marca a Scopus no formulário de uma linha membro
- **THEN** a canônica e as demais linhas da família também ficam ligadas à Scopus

#### Scenario: Base desmarcada
- **WHEN** o administrador desmarca o Latindex numa linha da família
- **THEN** nenhuma linha da família continua ligada ao Latindex

### Requirement: Cadastro de periódicos e Qualis no admin
A tela `/admin/journals`, só para `ROLE_ADMIN`, SHALL listar as linhas do cadastro por título, 50 por página, com busca por título, ISSN, Qualis ou variante, filtros por estrato (inclusive "sem Qualis") e por base, a distribuição de estratos e a área CAPES principal com a confiança. Criar e editar SHALL exigir título e aceitar ISSNs impresso, eletrônico e L, Qualis, bases e variações.

#### Scenario: Busca por variante
- **WHEN** o administrador busca uma grafia que só existe como variante
- **THEN** a revista dona da variante aparece na lista

#### Scenario: Título vazio
- **WHEN** o formulário é enviado sem título
- **THEN** aparece "Informe o título do periódico." e nada é gravado

### Requirement: Qualis e bases refletidos nos artigos
Quando a edição de um periódico muda o Qualis ou as bases, o sistema SHALL recalcular `qualis` e `indexed_databases` de todos os artigos ligados à família, pela mesma regra da indexação, e limpar o cache das páginas públicas. A exclusão de um periódico SHALL exigir POST com token CSRF válido.

#### Scenario: Estrato alterado
- **WHEN** o administrador muda o Qualis de uma revista de B1 para A2
- **THEN** os artigos ligados à família passam a mostrar A2 sem esperar a próxima indexação

#### Scenario: Edição sem mudança de Qualis nem bases
- **WHEN** o administrador só corrige o título
- **THEN** os artigos e o cache não são recalculados

#### Scenario: Exclusão com token inválido
- **WHEN** o pedido de exclusão chega com token CSRF inválido
- **THEN** aparece "Token de segurança inválido." e nada é removido

### Requirement: Importação da lista de uma base
O comando `app:import-journal-database-list <arquivo> [--base=sigla]` e o envio em `/admin/journals/import-database` SHALL ler a lista (CSV, XLSX, TXT ou JSON), achar cada revista pelo ISSN nas quatro colunas (menor id) ou pelo título, com e sem parênteses, contra títulos e variantes, completar só os campos vazios, inserir as que faltam (sem Qualis), ligá-las à base e gravar as variantes pela trava. Nome de mais de uma família MUST ignorar a linha.

#### Scenario: Revista já cadastrada
- **WHEN** a lista traz um ISSN de uma revista do cadastro com outro título
- **THEN** a revista existente é ligada à base, e só os campos vazios dela são completados

#### Scenario: Nome ambíguo sem ISSN conhecido
- **WHEN** uma linha sem ISSN conhecido traz um nome de duas famílias
- **THEN** a linha é ignorada e entra na contagem de ambíguas

#### Scenario: Revista que saiu da lista
- **WHEN** uma lista nova da base não traz mais uma revista ligada antes
- **THEN** o vínculo antigo continua, porque a importação só acrescenta vínculos

### Requirement: Área vinda das listas importadas
Nas importações de lista de base e de CSV, o texto da coluna de área SHALL ir para `area_raw` e `area` SHALL receber o rótulo canônico da primeira área CAPES que o tesauro de categorias reconhece no texto, ou o próprio texto. As duas colunas só são preenchidas quando estão vazias, e um texto que já é rótulo canônico vai só para `area`.

#### Scenario: Categoria reconhecida
- **WHEN** a lista traz "Education & Educational Research" para uma revista sem área
- **THEN** `area_raw` recebe esse texto e `area` recebe "EDUCAÇÃO / CIÊNCIAS HUMANAS"

#### Scenario: Revista com área
- **WHEN** a revista já tem `area` preenchida
- **THEN** a importação não a altera

### Requirement: Escolha da base de uma lista
Sem base informada, o sistema SHALL detectar a base pelo cabeçalho do arquivo, com assinaturas conhecidas (Scopus, Latindex, Web of Science, DOAJ, PubMed, SciELO, OpenAlex) ou pelas colunas de assinatura cadastradas. No comando, base informada que não existe MUST encerrar com erro, e uma detecção abaixo de 70% de confiança SHALL pedir a escolha numa lista. No admin, sem base escolhida e sem detecção, a importação falha pedindo a escolha manual.

#### Scenario: Lista da Scopus sem `--base`
- **WHEN** o arquivo tem as colunas da lista de títulos da Scopus
- **THEN** o comando detecta a Scopus e importa nela

#### Scenario: Sigla inexistente no comando
- **WHEN** o comando recebe `--base=xyz`
- **THEN** termina com a mensagem de que a base `xyz` não foi encontrada no cadastro, sem importar nada

### Requirement: Linhas novas a partir das fontes externas
A carga de evidências com `--create-missing-for-articles` SHALL criar uma linha no cadastro para cada ISSN válido de artigo ausente do cadastro e presente nas fontes, com título e nome exibido da fonte de maior prioridade (scielo, doaj, latindex, scopus, wos, openalex, pubmed), os ISSNs e o título normalizado, sem Qualis e sem área. Quando a fonte é uma das cinco bases da página pública, SHALL criar também o vínculo com a base.

#### Scenario: ISSN presente no DOAJ e no Latindex
- **WHEN** um artigo traz um ISSN válido ausente do cadastro e presente no DOAJ e no Latindex
- **THEN** é criada uma linha com o título do DOAJ, ligada só à base Latindex

#### Scenario: ISSN desconhecido das fontes
- **WHEN** o ISSN do artigo não está em nenhuma fonte
- **THEN** nenhuma linha é criada

### Requirement: Selos de Qualis e de bases
O sistema SHALL desenhar o selo Qualis ("Qualis X", com o ícone de prêmio e a cor do estrato de A1 a C) e os selos de bases (logotipo SVG ou PNG, ou o ícone da base, com o nome). Para um item de produção, os selos MUST usar só o que a indexação gravou em `qualis` e `indexed_databases`, sem resolver o periódico de novo na exibição; uma lista pronta de bases é desenhada como veio.

#### Scenario: Item sem estrato gravado
- **WHEN** um capítulo tem título igual ao de uma revista A1, mas nada gravado em `qualis`
- **THEN** ele não ganha selo Qualis

#### Scenario: Base com logotipo
- **WHEN** a base tem logotipo em `.svg`
- **THEN** o selo mostra o logotipo e o nome da base

### Requirement: Carga inicial do Bibliomap
Os comandos `app:import-bibliomap-journals` (revistas e variantes) e `app:import-bibliomap-databases` (bases e vínculos) SHALL copiar do banco do Bibliomap, apagando antes as tabelas de destino. Com dados no destino, o comando MUST recusar sem alterar nada; com `--force`, SHALL pedir confirmação, e no modo não interativo nada é apagado. As variantes entram pela trava do tesauro.

#### Scenario: Banco com dados
- **WHEN** `app:import-bibliomap-journals` roda com `qualis_journals` preenchida e sem `--force`
- **THEN** o comando termina com erro e nada é alterado

#### Scenario: Forçado sem interação
- **WHEN** o comando roda com `--force --no-interaction`
- **THEN** nada é apagado

#### Scenario: Variantes recusadas
- **WHEN** o Bibliomap traz como variante um trecho de página de erro ou um nome de outra revista
- **THEN** a variante não é gravada e o resumo conta as recusas

### Requirement: Exportações do catálogo
O admin SHALL exportar o cadastro por linha em CSV (`/admin/journals/export`, com áreas CAPES, nome exibido e canônica) e em CSV ou JSON com bases e variantes (`/admin/journals/export-full`, filtros de Qualis e base), e o tesauro por família (cabeçalho = nome exibido da canônica ou ISSN principal; variantes = os demais nomes), também por base. O comando `app:export-journals-thesaurus` SHALL fazer o mesmo pelo terminal.

#### Scenario: Tesauro por família
- **WHEN** o administrador exporta o tesauro de periódicos
- **THEN** cada família sai uma vez, com o nome exibido da canônica como cabeçalho e os títulos e variantes de todas as linhas como variantes

#### Scenario: Tesauro de uma base
- **WHEN** o administrador exporta o tesauro da Scopus com chave ISSN
- **THEN** entram as famílias com ISSN e com alguma linha ligada à Scopus, com o ISSN principal da família como cabeçalho

#### Scenario: Formato inválido no comando
- **WHEN** `app:export-journals-thesaurus --format=xls` é executado
- **THEN** o comando termina com erro "Formato inválido"

### Requirement: Importação de CSV de periódicos no admin
O envio em `/admin/journals/import` (POST com CSRF) SHALL ler `title`, `issn`, `qualis`, `area` e, opcional, `areas_capes`, achar a revista pelo ISSN ou, sem ISSN, pelo título, completar só os campos vazios ou inserir a revista, numa transação só. Família travada MUST NOT ser tocada, título de mais de uma família é ignorado, linha inválida vira aviso, e `areas_capes` grava curadoria manual travada se a família não tem áreas.

#### Scenario: Mesmo arquivo duas vezes
- **WHEN** o administrador importa o mesmo CSV duas vezes
- **THEN** a segunda importação não cria nem altera nada

#### Scenario: Família com áreas travadas
- **WHEN** uma linha do CSV corresponde a uma família travada
- **THEN** a linha é ignorada e contada como travada no aviso

### Requirement: Importação de tesauro de periódicos no admin
O envio em `/admin/journals/import-thesaurus` (POST com CSRF; `.the`, `.csv`, `.json` ou `.xml`) SHALL casar o cabeçalho de cada entrada com o título ou, depois, com o nome exibido de uma revista, criar a revista quando não existe e o nome não pertence a outra, e gravar as variantes pela trava como `alternative`, tudo numa transação. Os nomes recusados por pertencerem a outro periódico SHALL ser listados no aviso.

#### Scenario: Reimportar o tesauro exportado
- **WHEN** o administrador importa o tesauro que acabou de exportar
- **THEN** nenhum periódico novo é criado, porque os cabeçalhos casam com os nomes exibidos

#### Scenario: Variante de outro periódico
- **WHEN** uma variante do arquivo já pertence a outra família
- **THEN** ela é ignorada e aparece no aviso como "«nome» → #id «título»"

### Requirement: Exclusão de periódico no admin
Ao excluir um periódico em `/admin/journals` (POST com o token do periódico), o sistema SHALL tirar dos artigos ligados a ele o vínculo, o selo Qualis e as bases, que vinham da revista excluída, e limpar o cache de páginas e o do resolvedor de periódicos.

#### Scenario: Artigo de revista excluída
- **WHEN** o administrador exclui uma revista com artigos ligados e Qualis A1
- **THEN** esses artigos ficam sem periódico, sem selo Qualis e sem bases até a próxima indexação, e a mensagem informa quantos foram afetados
