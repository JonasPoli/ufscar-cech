# thematic-search Specification

## Purpose
Pesquisa Temática em `/temas`: descoberta de temas do índice temático por nuvem e autocomplete e, para o tema escolhido, os docentes que mais produzem nele, os departamentos associados, a evolução anual da produção, os conceitos relacionados e a análise editorial (Qualis e periódicos). Inclui as rotas JSON que alimentam a página.

## Requirements

### Requirement: Página da Pesquisa Temática
O sistema SHALL servir `GET /temas` com o campo de busca de temas, a nuvem de temas em destaque e o total de temas indexados. O parâmetro `t` SHALL selecionar um tema pelo slug ou pelo id numérico; com um tema válido, a página SHALL abrir com o painel do tema preenchido e com título e descrição nomeando o tema; com `t` vazio ou desconhecido, SHALL abrir sem tema selecionado e sem erro.

#### Scenario: Permalink do tema
- **WHEN** o visitante acessa `/temas?t=educacao-especial`
- **THEN** a página mostra o painel do tema "Educação Especial" com seus docentes, departamentos, evolução e análise editorial

#### Scenario: Tema inexistente
- **WHEN** o visitante acessa `/temas?t=nao-existe`
- **THEN** a página responde com sucesso, sem tema selecionado

### Requirement: Nuvem de temas em destaque
O sistema SHALL exibir até 24 temas do índice com pelo menos 2 ocorrências, do mais frequente ao menos (desempate pelo número de docentes), cada um com o número de ocorrências. Cada tema SHALL receber um peso de 0 a 100 relativo ao primeiro da lista, e a cor do chip SHALL seguir faixas de peso (75 ou mais, 45 ou mais, 20 ou mais e abaixo de 20). Clicar num chip SHALL selecionar o tema.

#### Scenario: Peso relativo
- **WHEN** o tema mais frequente tem 1.000 ocorrências e outro tem 500
- **THEN** o segundo recebe peso 50 e a cor da faixa de 45 a 74

### Requirement: Autocomplete de temas
O sistema SHALL expor `GET /api/temas/autocomplete?q=…`, que procura o texto, sem acentos e sem distinguir caixa, na forma normalizada dos termos e devolve até 24 termos, primeiro os que começam pelo texto e depois os que o contêm, por ocorrências e número de docentes. Texto com menos de 3 caracteres SHALL receber a lista vazia. Na página, a lista SHALL se atualizar enquanto o visitante digita, substituindo a nuvem pelos termos encontrados.

#### Scenario: Busca com acento
- **WHEN** o visitante digita `inteligência`
- **THEN** o autocomplete devolve os termos que começam ou contêm "inteligencia", como "Inteligência Artificial"
- **AND** a resposta traz `query`, `count` e `terms`, cada termo com id, nome, slug, ocorrências, docentes e peso

#### Scenario: Texto curto
- **WHEN** a chamada é `/api/temas/autocomplete?q=in`
- **THEN** a resposta tem `count` 0 e `terms` vazio

#### Scenario: Nenhum termo encontrado
- **WHEN** o texto digitado não corresponde a nenhum termo
- **THEN** a página mostra que nenhuma palavra-chave foi encontrada e sugere termos mais genéricos

### Requirement: Seleção do tema sem recarregar a página
Ao selecionar um tema (chip da nuvem, resultado do autocomplete ou conceito relacionado), o sistema SHALL carregar o painel do tema sem recarregar a página, atualizar a URL para `/temas?t={slug}` e levar a rolagem até o painel.

#### Scenario: Clique num tema da nuvem
- **WHEN** o visitante clica no chip de um tema
- **THEN** o painel do tema aparece preenchido e a barra de endereço passa a `/temas?t={slug}`

### Requirement: Resumo do tema e departamentos
O painel do tema SHALL mostrar o nome do tema, o total de ocorrências no índice, o número de docentes vinculados e os até 4 departamentos com mais docentes vinculados ao tema, cada um com a contagem e ignorando docentes sem departamento. Quando o tema tiver slug, o painel SHALL ter o link "Panorama, rede de coautoria e publicações do tema" para a página do assunto em Explorar.

#### Scenario: Departamentos mais associados
- **WHEN** um tema tem docentes de cinco departamentos
- **THEN** o painel lista os quatro departamentos com mais docentes, com a quantidade de cada um

### Requirement: Docentes do tema
O sistema SHALL listar os docentes vinculados ao tema, do que tem mais ocorrências no tema ao que tem menos (desempate pelo nome), 10 de cada vez, com o botão "Carregar mais docentes (+10)" enquanto houver mais. Cada cartão SHALL mostrar foto ou iniciais, nome, departamento (ou "CECH / UFSCar"), "N trabalho(s) neste tema", um exemplo de trabalho e o link para o perfil com `?tema=` seguido do nome do tema.

#### Scenario: Carregar mais docentes
- **WHEN** um tema tem 23 docentes e o visitante clica em "Carregar mais docentes (+10)" uma vez
- **THEN** a lista passa a mostrar 20 docentes e o texto "Exibindo 20 de 23 docentes"

#### Scenario: Link para o perfil
- **WHEN** o visitante clica no cartão de um docente do tema "Educação Especial"
- **THEN** é levado a `/professor/{slug}?tema=Educa%C3%A7%C3%A3o%20Especial`

### Requirement: API de docentes do tema
O sistema SHALL expor `GET /api/temas/docentes`, com o tema por `term_id` ou `slug`, `offset` (mínimo 0) e `limit` (1 a 50, padrão 10), respondendo com o termo, os docentes, `total`, `hasMore` e `nextOffset`. Só com `offset` 0 a resposta SHALL incluir os departamentos, a evolução anual, os conceitos relacionados e a análise editorial. Para um tema inexistente, SHALL responder 404 com `{"error": "Termo não encontrado."}`.

#### Scenario: Tema inexistente na API
- **WHEN** a chamada é `/api/temas/docentes?term_id=999999` para um id que não existe
- **THEN** a resposta é 404 com a mensagem "Termo não encontrado."

#### Scenario: Limite acima do máximo
- **WHEN** a chamada traz `limit=500`
- **THEN** a resposta traz no máximo 50 docentes

#### Scenario: Página seguinte
- **WHEN** a chamada traz `offset=10`
- **THEN** a resposta traz os docentes a partir do 11º, sem departamentos, evolução, conceitos e análise editorial

### Requirement: Evolução anual do tema
O sistema SHALL contar, por ano, os títulos distintos de produções que contêm o tema no título ou nos dados complementares (onde ficam as palavras-chave) e de orientações que o contêm no título, no título alternativo ou nas palavras-chave, de 1950 ao ano corrente. A série SHALL ir do primeiro ano com registro ao ano corrente, com zero nos anos vazios, e trazer o primeiro ano, o ano de pico e o total; a página SHALL alternar entre "Total de Trabalhos" e "Produções vs. Orientações".

#### Scenario: Anos sem produção
- **WHEN** um tema tem trabalhos em 2001 e 2004 e nenhum em 2002 e 2003
- **THEN** a série traz 2002 e 2003 com zero e segue até o ano corrente
- **AND** o ano de pico é o primeiro ano com o maior total

#### Scenario: Tema sem anos
- **WHEN** nenhum trabalho do tema tem ano informado
- **THEN** o gráfico mostra "Sem registros temporais suficientes para este tema."

#### Scenario: Evolução pela API
- **WHEN** a chamada é `/api/temas/evolucao?slug={slug}` ou `?term_id={id}`
- **THEN** a resposta traz o termo e a série anual
- **AND** para um tema inexistente, a resposta é 404

### Requirement: Conceitos relacionados
O sistema SHALL listar até 12 conceitos que mais aparecem junto com o tema, contando as palavras-chave (separadas por ponto e vírgula ou vírgula e comparadas sem acento e sem caixa) das produções e orientações que contêm o tema, ignorando o próprio tema e formas com menos de 3 caracteres. O conceito que corresponde a um termo do índice SHALL usar o nome e o slug do termo e ser clicável, trocando o tema selecionado; os demais SHALL aparecer como rótulo sem link.

#### Scenario: Conceito que existe no índice
- **WHEN** "Inclusão Escolar" aparece junto de "Educação Especial" e existe no índice temático
- **THEN** o conceito é um botão que seleciona o tema "Inclusão Escolar"

#### Scenario: Conceito fora do índice
- **WHEN** uma palavra-chave relacionada não existe no índice
- **THEN** ela aparece como rótulo, com a contagem de ocorrências conjuntas, sem link

### Requirement: Distribuição Qualis do tema
O sistema SHALL mostrar, para os artigos que contêm o tema no título ou nos dados complementares, a distribuição por estrato Qualis (A1 a A4, B1 a B4, C e "Sem Qualis", omitindo estratos sem artigos), o total de artigos com Qualis e a porcentagem desses artigos em A1 e A2, com uma casa decimal. O cartão da análise editorial SHALL ficar oculto quando não houver artigos com Qualis nem periódicos.

#### Scenario: Porcentagem de topo
- **WHEN** um tema tem 40 artigos com Qualis, 10 deles em A1 ou A2
- **THEN** o destaque mostra "25%" dos artigos avaliados nos estratos A1 e A2

#### Scenario: Tema sem artigos avaliados
- **WHEN** o tema não tem artigos com Qualis nem periódicos
- **THEN** o cartão de qualidade editorial não aparece

### Requirement: Periódicos mais frequentes do tema
O sistema SHALL listar os até 6 periódicos com mais artigos distintos do tema (pelo DOI ou, sem DOI, por ano e título), só entre artigos, agrupando as grafias da mesma revista: o artigo vinculado conta para a família do periódico e leva o nome de exibição da canônica; o artigo sem vínculo agrupa pelo nome normalizado e leva a grafia do Lattes mais frequente. O estrato exibido SHALL ser o melhor entre os artigos do grupo.

#### Scenario: Grafias da mesma revista
- **WHEN** artigos do tema foram gravados com duas grafias de uma revista que pertencem à mesma família
- **THEN** a revista aparece numa linha só, com o nome de exibição da canônica e a soma dos artigos distintos

#### Scenario: Capítulo com nome de revista
- **WHEN** um capítulo de livro do tema tem no campo de veículo um nome igual ao de uma revista
- **THEN** ele não entra no ranking de periódicos

### Requirement: Cálculo somente leitura e cache das análises
O sistema SHALL calcular a evolução anual, os conceitos relacionados e a análise editorial apenas por leitura dos dados, sem alterar produções, orientações ou o índice, e SHALL guardar cada um desses resultados por tema durante 24 horas.

#### Scenario: Segunda consulta do mesmo tema
- **WHEN** dois visitantes abrem o mesmo tema no mesmo dia
- **THEN** o segundo recebe a evolução, os conceitos e a análise editorial guardados, sem novo cálculo

### Requirement: Limpar a busca de temas
Ao apagar o texto da busca de `/temas` ou clicar no X, o sistema SHALL voltar à nuvem de temas em destaque e ao título "Temas Frequentes em Destaque", mantendo destacado o tema selecionado.

#### Scenario: Busca apagada
- **WHEN** o visitante pesquisa "leitura" e depois clica no X
- **THEN** a nuvem volta a mostrar os temas em destaque, sem erro na página
