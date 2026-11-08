# Comparativo com a Plataforma Jabuticaba (UFABC)

Levantamento feito em 07/11/2026 em <https://plataforma-jabuticaba.org/ufabc/>. Percorremos todas as telas
do portal, lemos o código JavaScript publicado (rotas e chamadas) e consultamos a API pública que ele usa
(`/ufabc/api/v1/`: `catalog`, `search`, `researchers/{id}`, `publications`, `metrics`, `graph` e `projects`).
Depois cruzamos cada funcionalidade com o código do Portal CECH (`src/Controller/pub`, `templates/pub`,
entidades e serviços).

Na UFABC a unidade de agrupamento é o **centro** (CCNH, CECS, CMCC). No CECH, o equivalente é o
**departamento**. Em todo este documento, "centro" na Jabuticaba corresponde a "departamento" aqui.

Volume da Jabuticaba na data: 1.288 perfis cadastrados, 1.249 com Lattes, 93.107 publicações (deduplicadas),
179 assuntos, 88 áreas de atuação, 9 grandes áreas, 14.320 projetos e 1.918 pares de coautoria.

---

## 1. O que a Jabuticaba oferece

### 1.1 Estrutura geral

- Menu lateral: **Especialistas**, **Panorama**, **Coautorias**, **Explorar pesquisa**, **Projetos**. No rodapé
  do menu: **Sobre nós**, **Sobre os dados**, **Dúvidas frequentes**.
- Botão **Acessibilidade** no topo de todas as páginas e link "Pular para o conteúdo".
- Todo estado de busca e filtro fica na URL, então qualquer resultado pode ser compartilhado por link.
- API JSON pública que alimenta o portal.

### 1.2 Especialistas (página inicial e busca)

- **Três modos de busca**: *Especialistas* (por assunto), *Publicações* (por assunto) e *Pesquisador* (por nome).
- **Filtros**: centro; área de atuação (9 grandes áreas e 79 áreas, agrupadas); vínculo institucional
  (vigente ou encerrado); tipo de publicação (artigo, livro, capítulo, trabalho em evento); período de
  publicação (ano inicial e final, com seletor de ano); "Buscar a expressão exata"; "Limpar filtros".
- **Página inicial sem busca**: três números (pesquisadores com produção, publicações reunidas, assuntos para
  explorar); bloco "Por onde você quer começar?" com os 14 assuntos mais frequentes e suas contagens; atalhos
  para Coautorias, Panorama e Projetos.
- **Resultado em modo Especialistas**: total de pesquisadores e de publicações relacionadas; um cartão por
  pessoa com iniciais, centro, área e situação do vínculo, mais as duas publicações que melhor casam com a
  busca (ano, ícone do tipo, título, veículo, ISSN, DOI); link "Ver N publicações", que abre o perfil já na
  aba "Publicações da busca".
- **Ordenação**: relevância textual (o título pesa mais que as palavras-chave, a expressão completa no título
  soma pontos, o desempate é por quantidade de trabalhos e depois por nome), mais recentes, mais publicações
  relacionadas e nome. Paginação de 12 em 12.
- **Resultado em modo Publicações**: lista sem repetição (a mesma publicação em vários currículos conta uma
  vez), com ano, tipo, título, veículo, ISSN, DOI e autoria, em que os pesquisadores da casa aparecem como
  link para o perfil.

### 1.3 Perfil do pesquisador

- **Cabeçalho**: iniciais (não usa foto), instituição, centro, situação do vínculo (vigente, encerrado ou não
  confirmado), área e os quatro assuntos principais em etiquetas. "Voltar à busca" recupera a busca anterior.
- **Filtros do perfil**, válidos em todas as abas: assunto (texto), centro, tema e período.
- **Abas**:
  - *Atuação*: histórico de vínculos (centro e período, por exemplo "CCNH 2009–hoje"); quatro números
    (publicações, coautores na plataforma, publicações compartilhadas, publicação mais recente); gráfico de
    publicações por ano; assuntos em destaque com barras.
  - *Publicações*: produção completa, com autoria e colegas da casa como link, DOI e paginação.
  - *Publicações da busca*: aparece só quando o perfil foi aberto a partir de uma busca e mostra os trabalhos
    que correspondem a ela.
  - *Coautorias*: grafo da vizinhança da pessoa, com os mesmos controles da página Coautorias.
  - *Projetos*: busca por nome e filtro de situação, em cartões paginados.
  - *Sobre o pesquisador*: resumo do Lattes, links para o Lattes e para a página institucional, data de
    atualização do currículo e "Formação de pesquisadores" (orientações e coorientações concluídas de
    mestrado e doutorado, com programa e instituição, paginadas).

### 1.4 Panorama

- Filtros: assunto, centro, tema e período.
- Quatro números: publicações, pesquisadores, publicações compartilhadas e pares de coautoria.
- Gráficos: produção por ano, assuntos em destaque (12 primeiros) e **"Pontes entre grandes áreas"**, um mapa
  de calor 9 × 9 com os pares de pesquisadores que publicam juntos, cruzando as grandes áreas de cada um.

### 1.5 Coautorias

- Filtros iguais aos do Panorama.
- **Grafo interativo da instituição** (biblioteca ECharts): cada pessoa é um nó, com cor e forma por centro
  (a legenda não depende só de cor); a espessura da ligação indica o número de publicações em comum.
- Controles: "Destacar pesquisador" (lista de nomes), clique numa pessoa para ver sua vizinhança, clique numa
  ligação para ver as publicações do par, ampliar, reduzir, centralizar, tela cheia e mostrar ou ocultar
  nomes. O rodapé informa quantas pessoas e ligações estão desenhadas ("40 pessoas · 53 de 1918 pares").
- Lista dos pares que mais publicam juntos, com "Ver mais pares".
- Janela **"Pesquisa compartilhada"**: publicações do par, com DOI, veículo, ISSN e autoria.

### 1.6 Explorar pesquisa

- Três catálogos com filtro por nome: **Assuntos de pesquisa** (179), **Áreas de atuação** (88) e
  **Grandes áreas** (9), cada item com o número de publicações.
- **Página de cada assunto ou área**: filtros (assunto, centro, período); quatro números (publicações,
  pesquisadores, publicações compartilhadas, pares de coautoria); produção por ano; assuntos relacionados;
  coautorias neste campo (grafo e pares); publicações relacionadas, paginadas.
- Os assuntos vêm de palavras-chave e de expressões recorrentes nos títulos.

### 1.7 Projetos

- **Catálogo institucional** com 14.320 projetos: busca por nome, filtro por situação (concluído, em
  andamento, desativado), cartões com situação, título, resumo e ano, e paginação.
- **Página do projeto**: título, situação, anos, descrição, natureza (pesquisa, extensão, desenvolvimento),
  financiadores e pesquisadores associados com o papel declarado (responsável ou participante) e link para
  o perfil.
- O mesmo projeto declarado em vários currículos aparece uma única vez, com todos os integrantes da casa.

### 1.8 Páginas institucionais

- **Sobre nós**: o que é o portal, o que se encontra nele e quem o desenvolve, com links acadêmicos.
- **Sobre os dados**: números de cobertura (perfis cadastrados, perfis com Lattes, publicações), fontes
  (Lattes e cadastro institucional) e "Como interpretar os indicadores" (publicações e coautorias,
  orientações, títulos científicos, assuntos e áreas, cobertura e atualização).
- **Dúvidas frequentes**: três categorias (busca e resultados; perfis e coautorias; dados e cobertura), cerca
  de 12 perguntas, cada uma com âncora própria.

### 1.9 Acessibilidade

- Painel "Preferências de leitura": tamanho do texto até 200%, tema automático, claro ou escuro, alto
  contraste, espaçamento ampliado, redução de animações e "Restaurar".
- Navegação completa por teclado (Tab, Enter, Esc fecha janelas), link para pular ao conteúdo e rótulos
  ARIA.

### 1.10 Tratamento de dados

- Fórmulas matemáticas e químicas nos títulos são exibidas formatadas (KaTeX).
- Publicações e projetos repetidos entre currículos são unificados.
- O vínculo vem de um cadastro institucional com períodos, inclusive de ex-docentes.
- Perfis cadastrados sem Lattes aparecem como "pendentes" na cobertura.

---

## 2. Comparação com o Portal CECH

Legenda: **Temos**, **Parcial** (existe, mas falta parte do que a Jabuticaba faz), **Não temos**.

| # | Funcionalidade da Jabuticaba | Portal CECH hoje | Situação |
| --- | --- | --- | --- |
| 1 | Busca por nome de pesquisador | `/busca` com tipo "Apenas Pesquisadores" | Temos |
| 2 | Busca de especialistas por assunto, agrupada por pessoa, com as publicações que casam | `/temas` agrupa docentes por termo do índice temático, mas não aceita texto livre; `/busca` lista docentes e produções separados | Parcial |
| 3 | Busca de publicações por assunto, sem repetição entre currículos | `/busca` lista cada registro do currículo (o mesmo artigo de dois docentes aparece duas vezes), ordenado por ano | Parcial |
| 4 | Filtros de área de atuação, vínculo, tipo de publicação e período na busca | Só departamento e tipo de resultado | Não temos |
| 5 | "Buscar a expressão exata" | — | Não temos |
| 6 | Ordenação por relevância, mais recentes, quantidade e nome | Ordem fixa por ano | Não temos |
| 7 | Autoria com os colegas da casa como link | Coautores aparecem no perfil (Principais Colaboradores), não na lista de publicações da busca | Parcial |
| 8 | Página inicial com caixa de busca, números e os assuntos mais frequentes | Home com números, departamentos, publicações recentes e docentes; os temas em destaque ficam em `/temas` | Parcial |
| 9 | Perfil: aba "Publicações da busca" e "Voltar à busca" | O perfil aceita `?q=`/`?tema=` e já abre filtrado, mas sem aba própria nem retorno | Parcial |
| 10 | Perfil: filtros por tema e período em todas as abas | Busca textual única no perfil, sem tema nem período | Parcial |
| 11 | Perfil: histórico de vínculos (vigente/encerrado, com períodos) | Só `admissionYear`, `leaveYear` e `status`, não exibidos como histórico | Parcial |
| 12 | Perfil: números de coautores na plataforma e publicações compartilhadas | Cartões de colaboradores, sem esses totais | Parcial |
| 13 | Perfil: grafo de coautoria da pessoa | Lista de colaboradores, sem grafo | Não temos |
| 14 | Perfil: projetos com busca e filtro de situação | Projetos listados na aba "Projetos, Bancas & Atuação", sem filtro próprio | Parcial |
| 15 | Panorama com filtros por assunto, departamento, tema e período | `/indicadores` tem 18 figuras, mas sem filtro global | Parcial |
| 16 | Números de publicações compartilhadas e pares de coautoria | Calculados na rede (Fig. 16), sem cartão de destaque | Parcial |
| 17 | Mapa "pontes entre grandes áreas" (e, no nosso caso, entre departamentos) | Matriz docente × docente; nada por área ou departamento | Não temos |
| 18 | Página de coautorias com grafo interativo (destacar, ampliar, tela cheia, nomes, legenda) | Ranking de pares, matriz e exportação para VOSviewer, sem grafo | Não temos |
| 19 | Clique num par para ver as publicações em comum | — | Não temos |
| 20 | Catálogo de assuntos com página própria para cada um | `/temas?t=` tem evolução anual, Qualis, periódicos e docentes do termo | Parcial |
| 21 | Catálogo de áreas de atuação e grandes áreas, com página de cada uma | Dado existe (`knowledge_areas`, `capes_areas`) e alimenta a Fig. 4, sem páginas | Não temos |
| 22 | Página de assunto/área com coautorias no campo e publicações relacionadas | — | Não temos |
| 23 | Catálogo institucional de projetos | Projetos só dentro do perfil | Não temos |
| 24 | Página do projeto com todos os integrantes e papéis | O importador só grava se o docente é coordenador; os demais integrantes são descartados | Não temos |
| 25 | Projetos unificados entre currículos | Cada currículo gera seu próprio registro | Não temos |
| 26 | Página "Sobre nós" | — | Não temos |
| 27 | Página "Sobre os dados" (fontes, cobertura, como interpretar) | Existe só para periódicos (`_methodology`) | Parcial |
| 28 | Dúvidas frequentes | — | Não temos |
| 29 | Painel de acessibilidade (texto até 200%, contraste, espaçamento, animações) | Só botão claro/escuro | Parcial |
| 30 | Tema automático (segue o sistema) | Segue o sistema até o primeiro clique; não há opção "automático" para voltar | Parcial |
| 31 | Link "pular para o conteúdo" e navegação por teclado revisada | Não encontrado no `base.html.twig` | Não temos |
| 32 | Fórmulas em títulos (KaTeX) | — | Não temos |
| 33 | Ex-docentes na busca, com filtro de vínculo | Listas públicas usam só `status = 1` | Não temos |
| 34 | API JSON pública | Só rotas internas (`/api/temas/*`, fotos, importação) | Não temos |
| 35 | Data de atualização do currículo no perfil | `lastLattesUpdate` existe | Temos |
| 36 | Orientações com programa e instituição | Aba de orientações completa | Temos |
| 37 | Contagem de publicações sem repetição | `uniqueProductions` no `StatisticsService` | Temos |

### 2.1 O que temos e a Jabuticaba não tem

Vale manter e destacar na comunicação: Qualis e estratos; bases internacionais (Scopus, Web of Science,
SciELO, Latindex, PubMed etc.) e a página `/periodicos`; fotos dos docentes; exportação do perfil em BibTeX,
JSON e CSV; exportação da rede para VOSviewer; indicadores de formação do corpo docente (graduação,
doutorado, origem geográfica, trajetórias e saídas); parcerias nacionais e internacionais; orientações em
andamento, bancas, eventos, prêmios e produção técnica e artística; tesauros de curadoria no painel; páginas
de departamento; SEO com `sitemap.xml` (a Jabuticaba usa URLs com `#`, que os buscadores não indexam).

---

## 3. Novas funcionalidades necessárias

Porte: **P** (até 2 dias), **M** (3 a 5 dias), **G** (mais de 5 dias). Prioridade pensada para quem usa o
portal: primeiro encontrar especialistas, depois entender as redes e os projetos.

| Código | Funcionalidade | Prioridade | Porte | Itens da seção 2 |
| --- | --- | --- | --- | --- |
| N1 | Busca unificada com três modos, filtros completos, expressão exata, ordenação e relevância | Alta | G | 2–8 |
| N2 | Perfil com "Publicações da busca", filtros por tema e período, histórico de vínculos, novos números e grafo da pessoa | Alta | M | 9–14 |
| N3 | Página de coautorias com grafo interativo e publicações de cada par | Alta | G | 16, 18, 19 |
| N4 | Projetos: integrantes, unificação, catálogo e página do projeto | Alta | G | 14, 23–25 |
| N5 | Panorama com filtros e mapa de pontes entre departamentos e entre grandes áreas | Média | M | 15–17 |
| N6 | Explorar pesquisa: catálogos de assuntos, áreas e grandes áreas, com página de cada um | Média | G | 20–22 |
| N7 | Páginas Sobre, Sobre os dados e Dúvidas frequentes | Média | P | 26–28 |
| N8 | Painel de acessibilidade e revisão de teclado | Média | M | 29–31 |
| N9 | Vínculo institucional com histórico e ex-docentes na busca | Média | M | 11, 33 |
| N10 | Página inicial com busca, assuntos em destaque e atalhos | Média | P | 8 |
| N11 | Fórmulas em títulos (KaTeX) | Baixa | P | 32 |
| N12 | API JSON pública | Baixa | M | 34 |

---

## 4. Ajustes necessários

Regras que valem para todos os itens:

- **Dado do Lattes intocado.** Nada do que vem do currículo é alterado. Todo cálculo, unificação ou
  normalização vai para colunas ou tabelas novas.
- **Só migrations.** Toda mudança de esquema (inclusive índices) é feita com `make:migration` ou
  `doctrine:migrations:diff` e aplicada com `doctrine:migrations:migrate`.
- **Cache.** As páginas públicas ficam em `var/cache/public_pages`. As novas rotas com filtro geram uma
  entrada por URL; as rotas em `/api` não são cacheadas pelo cache de página, então os cálculos pesados
  (grafo, métricas) devem usar um pool de cache do Symfony com chave pelos filtros e ser limpos por
  `app:cache:clear` depois de importar ou reindexar.
- **Testes.** Cada item novo ganha teste de serviço (PHPUnit, com data providers para as regras) e teste de
  rota com o cliente BrowserKit.

### N1. Busca unificada

**Banco**

- Migration com índice `FULLTEXT` em `production_items(title)`, para a pontuação de relevância.
- As palavras-chave ficam dentro do JSON `extra_data`, que não aceita `FULLTEXT`. Criar a coluna derivada
  `production_items.search_keywords` (texto), preenchida pelo comando de indexação a partir de
  `extraData['keywords']`, com índice `FULLTEXT` próprio. O JSON original não muda.
- Antes, medir o custo de criar os índices no servidor
  (ver [`CAPACIDADE_DO_SERVIDOR.md`](../CAPACIDADE_DO_SERVIDOR.md)).

**Back-end**

- `src/Dto/SearchFilterDto.php`: `q`, `mode` (`especialistas`, `publicacoes`, `pesquisador`), `departamento`,
  `grandeArea`, `area`, `vinculo` (`vigente`, `encerrado`, `todos`), `tipo` (artigo, livro, capítulo,
  evento), `de`, `ate`, `exata`, `ordem`, `pagina`.
- `src/Service/Search/ExpertSearchService.php`:
  - pontuação por trabalho: termo no título vale mais que nas palavras-chave; expressão completa no título
    soma bônus; com `exata`, exige a frase na ordem;
  - modo Especialistas: agrupa por docente, nota do docente = maior nota entre seus trabalhos; desempate por
    quantidade de trabalhos e depois por nome; devolve as duas melhores publicações de cada um;
  - modo Publicações: usa a mesma chave de unificação do `uniqueProductions` do `StatisticsService` para não
    repetir o mesmo trabalho;
  - ordenações: relevância, mais recentes, mais publicações, nome.
- Autoria com link: usar `production_authors.matched_researcher_id` para transformar os coautores do CECH em
  link para o perfil.
- `MainController::search()` passa a montar o DTO e chamar o serviço; os parâmetros antigos (`type`, `dept`)
  continuam aceitos e são convertidos, para não quebrar links já publicados.

**Front-end**

- Reescrever `templates/pub/main/search.html.twig`: abas dos três modos, filtros em uma linha, seletor de ano
  inicial e final, caixa "expressão exata", "Limpar filtros", ordenação e paginação.
- Cartão de especialista com as duas publicações e o link "Ver N publicações" para
  `/professor/{slug}?q=…&aba=busca`.
- Todos os filtros na URL (GET), para o resultado poder ser compartilhado.

### N2. Perfil do docente

**Back-end**

- `ProfessorController::show()`: ler `q` e os filtros vindos da busca e expor a aba "Publicações da busca"
  quando houver `q`; guardar a URL de origem para o botão "Voltar à busca".
- Novos números em `calculateKpiStats()`: coautores do CECH (distintos), publicações compartilhadas com
  colegas do CECH e ano da publicação mais recente.
- "Assuntos em destaque": usar `thematic_term_researchers` (contagem por termo) em vez de só a nuvem de
  palavras.
- Endpoint `GET /professor/{slug}/coautorias.json` com a vizinhança da pessoa (mesmo formato do N3).

**Front-end**

- Nova aba "Publicações da busca" (fragmento `_tab_search_results.html.twig`).
- Barra de filtros comum às abas: texto, tema (lista de termos do docente) e período.
- Bloco "Histórico de vínculos" (depende do N9; enquanto isso, mostrar departamento e
  `admissionYear`–`leaveYear`).
- Grafo da vizinhança na aba de análise, reaproveitando o componente do N3.
- Na aba "Projetos, Bancas & Atuação", busca por nome e filtro de situação para os projetos.

### N3. Coautorias com grafo interativo

**Back-end**

- `src/Service/CoauthorshipGraphService.php`, a partir do cálculo que já alimenta a Fig. 16
  (`getFig13CoauthorshipNetwork` e `getCoauthorshipMatrixPayload`): nós (docente, departamento, total de
  publicações) e ligações (par, peso, anos, ids das publicações em comum), aceitando os filtros do N5.
- Rotas:
  - `GET /coautorias`: página;
  - `GET /api/coautorias/grafo?…filtros`: JSON do grafo;
  - `GET /api/coautorias/par/{a}/{b}?…filtros`: publicações em comum, unificadas, com DOI, veículo, ISSN e
    autoria.

**Front-end**

- Biblioteca de grafo carregada pelo `importmap.php`, com versão fixa (ECharts, a mesma da Jabuticaba, ou
  Cytoscape.js). Hoje o projeto não tem nenhuma.
- Controller Stimulus `coauthorship_graph_controller.js`: "Destacar docente", clique no nó mostra a
  vizinhança, clique na ligação abre a janela de publicações, ampliar, reduzir, centralizar, tela cheia,
  mostrar ou ocultar nomes.
- Legenda por departamento com **cor e forma** (11 departamentos pedem pelo menos 6 formas combinadas com
  cores; validar contraste nos temas claro e escuro).
- Lista dos pares com mais publicações e "Ver mais pares".
- Manter na página os recursos que já temos: matriz, exportação CSV e VOSviewer.
- Incluir "Coautorias" no menu principal.

### N4. Projetos

**Banco** (tabelas novas; `research_projects` não muda)

- `research_project_members`: `research_project_id`, `full_name`, `citation_name`, `member_order`,
  `is_responsible`, `lattes_id`, todos copiados do XML como vieram, mais `matched_researcher_id` (calculado).
- `research_project_funders`: todos os financiadores do projeto (hoje só o primeiro vai para
  `agency_financier`).
- `research_project_groups` e a coluna `research_projects.project_group_id`: unificação do mesmo projeto
  declarado em vários currículos.

**Importação**

- `LattesXmlParserService::parseResearchProjects()` (linha 994) hoje percorre
  `EQUIPE-DO-PROJETO/INTEGRANTES-DO-PROJETO` só para marcar o coordenador. Passa a gravar cada integrante
  em `research_project_members`. Nos 414 XMLs de `docs/banco/CECH` há 32.815 integrantes em 391 currículos.
- Exige **reimportar os currículos** depois do deploy (seguir o roteiro de reimportação já documentado).

**Unificação**

- Comando `app:index:projects`: agrupa registros com o mesmo nome normalizado, o mesmo ano de início e pelo
  menos um integrante em comum (por `lattes_id`), e preenche `project_group_id` e `matched_researcher_id`.
  Entra na rotina de indexação do painel.

**Rotas e telas**

- `GET /projetos`: busca por nome, filtros de situação, natureza (pesquisa, extensão, desenvolvimento),
  departamento e ano; cartões paginados.
- `GET /projeto/{slug}`: título, situação, anos, natureza, descrição, financiadores e integrantes com papel
  (responsável ou participante); os do CECH com link para o perfil.
- Incluir "Projetos" no menu, no `sitemap.xml` e no cache de página.

### N5. Panorama com filtros

**Back-end**

- `src/Dto/StatsFilterDto.php` (`q`, `departamento`, `termo`, `de`, `ate`) e métodos no `StatisticsService`
  que o aceitem: total de publicações únicas, docentes, publicações compartilhadas, pares de coautoria,
  série anual e assuntos em destaque.
- Mapa de pontes: matriz departamento × departamento e grande área × grande área, contando pares de
  docentes com publicação em comum. A grande área do docente vem de `knowledge_areas.major_area`; para
  agrupar as grafias diferentes, criar a coluna `knowledge_areas.capes_area_id`, preenchida por comando a
  partir de `capes_areas`.

**Front-end**

- No topo de `/indicadores`, uma barra de filtros e quatro cartões, seguidos dos gráficos novos (série,
  assuntos, mapa de pontes). As 18 figuras atuais continuam nas abas. Se os filtros valerem também para
  elas, cada `getFigX` precisa receber o DTO; fazer isso aos poucos, começando pelo bloco de produção.

### N6. Explorar pesquisa

**Dados**

- Assuntos: `thematic_terms` e `thematic_term_researchers`, gerados por `app:index:topics`. Conferir se o
  índice está populado em produção (no banco de desenvolvimento de 11/09/2026 estava com 0 termos).
- Áreas e grandes áreas: `knowledge_areas` ligadas a `capes_areas` pela coluna nova do N5.

**Rotas e telas**

- `GET /explorar` (aba Assuntos), `/explorar/areas` e `/explorar/grandes-areas`: cartões com nome e número de
  publicações, filtro por nome.
- `GET /explorar/assunto/{slug}` e `/explorar/area/{slug}`: Panorama filtrado (N5) e grafo do campo (N3), mais
  a lista de publicações relacionadas, paginada.
- `/temas?t=` passa a redirecionar (301) para `/explorar/assunto/{slug}`, mantendo os blocos que só nós temos
  (Qualis e periódicos do tema).

### N7. Páginas institucionais

- `GET /sobre`: o que é o portal, o que se encontra nele e quem o mantém (CECH, biblioteca, equipe técnica).
- `GET /sobre-os-dados`: números de cobertura (docentes cadastrados, com Lattes, publicações únicas, data da
  última importação), fontes (Lattes, planilha do CECH, Repositório TeD-UFSCar, Qualis, bases
  internacionais) e "como interpretar" (unificação de publicações, coautoria, orientações, Qualis,
  assuntos). Reaproveitar o texto de `templates/pub/journal/_methodology.html.twig`.
- `GET /duvidas`: perguntas em categorias, cada uma com âncora.
- Textos em Twig no primeiro momento; se a coordenação quiser editar sozinha, mover para `SiteSetting` com
  edição no painel.
- Links no rodapé e no `sitemap.xml`.

### N8. Acessibilidade

- Painel "Preferências de leitura" no `templates/pub/base.html.twig`: tamanho do texto de 100% a 200%
  (variável CSS na raiz), tema automático, claro ou escuro, alto contraste, espaçamento ampliado, reduzir
  animações e "Restaurar". Preferências no `localStorage`, aplicadas antes da pintura para não piscar.
- Respeitar `prefers-reduced-motion` e `prefers-contrast` por padrão.
- Link "Pular para o conteúdo" como primeiro elemento e `id="conteudo"` no `<main>`.
- Revisar foco visível, ordem de Tab e Esc nas janelas e menus (Shoelace já ajuda nos componentes dele).
- Os gráficos Chart.js precisam de `aria-label` e de tabela alternativa ("ver dados").

### N9. Vínculo institucional

**Banco**

- Tabela `researcher_affiliations`: `researcher_id`, `department_code`, `start_year`, `end_year`, `source`
  (planilha do CECH, informado no painel). Os campos `admission_year`, `leave_year` e `status` ficam como
  estão; a tabela nova guarda o histórico.

**Importação e painel**

- `ExcelCechImporterService` grava os períodos na tabela nova.
- No formulário do currículo no painel, um bloco para incluir e editar períodos.

**Portal**

- Situação "vínculo vigente" ou "vínculo encerrado" no cabeçalho do perfil e no cartão de busca.
- Filtro de vínculo na busca (N1). Ex-docentes passam a aparecer na busca quando o filtro pedir; as listas
  de departamento continuam só com vigentes, com a opção de ver os anteriores.

### N10. Página inicial

- Caixa de busca com os três modos do N1 no topo da home.
- Bloco "Por onde começar" com os 14 assuntos mais frequentes (links para o N6).
- Três números: docentes com produção, publicações únicas e assuntos.
- Atalhos para Coautorias, Indicadores e Projetos. Os blocos atuais (departamentos, publicações recentes,
  docentes) continuam abaixo.

### N11. Fórmulas em títulos

- KaTeX (versão fixa, via `importmap.php` ou cdnjs) aplicado só aos títulos que contêm `$…$` ou `\(…\)`. A
  renderização é só visual: o título gravado não muda.
- Pouco frequente nas áreas do CECH; deixar para o fim.

### N12. API JSON pública

- Prefixo `/api/v1` com `catalog`, `search`, `researchers/{slug}`, `publications`, `metrics`, `graph` e
  `projects`, todos somente leitura, sem dados pessoais além do que o perfil público já mostra.
- Limite de requisições (`symfony/rate-limiter`), cabeçalhos de cache e documentação curta em
  `docs/API_PUBLICA.md`.

---

## 5. Ordem sugerida

1. **Fase 1 (encontrar especialistas):** N1, N10 e a parte do N2 que depende só da busca.
2. **Fase 2 (redes):** N3, o grafo do perfil (N2) e N5.
3. **Fase 3 (projetos):** N4, que exige mudança no importador e reimportação dos currículos.
4. **Fase 4 (exploração e institucional):** N6, N7 e N9.
5. **Fase 5 (acabamento):** N8, N11 e N12.

N8 (acessibilidade) pode ser adiantado se houver exigência formal da universidade: não depende dos outros.

---

## 6. Resumo para a coordenação

Comparamos o Portal CECH com a Plataforma Jabuticaba da UFABC. O nosso portal já tem recursos que ela não
tem (Qualis, bases internacionais, periódicos, fotos, exportação, indicadores de formação do corpo
docente). O que precisamos acrescentar:

1. **Busca de especialistas melhor:** buscar por assunto e ver quem pesquisa o tema, com as publicações que
   comprovam; filtros por área, tipo de publicação, período e situação do vínculo; ordenar por relevância.
2. **Mapa de coautorias interativo:** um grafo com os docentes que publicam juntos, em que se clica num par
   para ver as publicações em comum.
3. **Catálogo de projetos:** todos os projetos do CECH numa página só, com busca e situação, e uma página
   para cada projeto com a equipe completa.
4. **Panorama com filtros:** os indicadores filtrados por assunto, departamento e período, mais um mapa das
   pontes entre departamentos e entre áreas.
5. **Explorar por assunto e por área:** uma página para cada assunto e área do conhecimento, com a produção,
   os docentes e as colaborações daquele campo.
6. **Perfil do docente mais completo:** histórico de vínculos, número de coautores no CECH, grafo de
   colaboradores e as publicações da busca que levou até ele.
7. **Páginas de apoio:** Sobre o portal, Sobre os dados e Dúvidas frequentes.
8. **Acessibilidade:** aumentar o texto até 200%, alto contraste, espaçamento e redução de animações.
9. **Ex-docentes:** indicar quem tem vínculo vigente ou encerrado e permitir buscar também quem já saiu.

---

## 7. Implementação (07/11/2026)

Todos os itens N1 a N12 foram implementados. A suíte passou de 815 para 854 testes, todos passando.

### Como foi feito

O caminho mais curto foi um núcleo único, reaproveitado por todas as telas, em `src/Service/Research/`:

- `ResearchFilter` e `ResearchFilterFactory`: o mesmo filtro (`q`, `exata`, `departamento`, `tema`, `grande_area`,
  `area`, `vinculo`, `tipo`, `de`, `ate`) vale para busca, panorama, rede, Explorar, perfil e API.
- `ResearchSql`: a condição SQL do filtro, a pontuação de relevância e a chave de publicação única (DOI, ou tipo +
  ano + título, a mesma regra do resumo institucional).
- `ExpertSearchService`, `CoauthorshipGraphService`, `ResearchMetricsService` e `ResearchCatalogService`.
- Blocos de tela reaproveitáveis em `templates/pub/research/` (filtros, panorama, grafo, macros) e o componente
  `assets/js/graph.js`, que baixa o ECharts 5.5.1 só quando há grafo na página.
- Resultados de panorama e rede ficam no pool `cache.research` (um dia), limpo junto com o cache de páginas.

### O que mudou em relação ao plano da seção 4

| Plano | O que foi feito | Por quê |
|---|---|---|
| Índice `FULLTEXT` e coluna `search_keywords` (N1) | Busca com `LIKE` sobre título e palavras-chave, com pontuação em SQL | Com 76 mil registros a busca leva menos de 1 s; não precisou de migration nem de coluna nova |
| Tabela `researcher_affiliations` (N9) | Vínculo e período vêm de `status`, `admission_year` e `leave_year` | A planilha do CECH só tem um período por docente (anos de início e fim no CECH) |
| Coluna `knowledge_areas.capes_area_id` (N5, N6) | Tradução das grandes áreas em código (`MajorAreaCatalog`) | O Lattes grava o código da grande área; só um registro vinha por extenso |
| `/temas` redirecionando para o Explorar | `/temas` continua e as duas páginas se ligam | A página antiga tem a análise de Qualis e periódicos do tema, que o Explorar não repete |
| Limite de requisições na API (N12) | Só cache HTTP (`max-age=3600`) e cache interno | Não há `symfony/rate-limiter` no projeto; fica como próximo passo |
| Filtros valendo para as 18 figuras (N5) | Os filtros valem para o panorama do topo | As figuras continuam mostrando o CECH inteiro, como antes |
| Rotas `/api/coautorias/grafo` e `/api/coautorias/par/{a}/{b}` (N3) | `/api/v1/grafo` e `/api/v1/grafo/par?a&b` (`ApiV1Controller`) | O grafo ficou na API pública versionada, junto das outras rotas |
| Matriz e exportação CSV/VOSviewer na página de coautorias (N3) | Ficaram nos Indicadores (Figura 16), com link a partir de `/coautorias` | Reaproveita a aba de rede que já existia |
| Listas de departamento só com vigentes, com opção de ver os anteriores (N9) | `/departamento/{código}` lista vigentes e encerrados, sem opção de alternar; a lista `/departamentos` conta só os vigentes | Não houve filtro de vínculo nessas telas |
| Capa com três números (N10) | A capa mostra seis números (`MainController::home`); os três números do plano ficam na capa de `/busca` | A capa já tinha os indicadores de produção; a busca ficou com o resumo enxuto |

Acréscimos que não estavam no plano: panorama e rede na página de cada departamento; o modo "Teses e orientações"
continua na busca; e, na busca de especialistas, os docentes cujo nome contém o termo aparecem acima dos resultados.

### Projetos (N4)

- Tabelas `research_project_members` e `research_project_funders` (migration `Version20261107182141`) e coluna
  calculada `research_projects.project_group_key`.
- No banco local, o comando `app:projects:backfill-members` preencheu 32.815 integrantes e 3.026 financiadores sem
  reimportar. Os 6.773 registros viraram 6.292 projetos, dos quais 299 são declarados por mais de um docente.
- Limitações:
  - o agrupamento usa nome normalizado + ano de início, então o mesmo projeto declarado com anos diferentes não se une;
  - a reimportação de um currículo regrava os projetos e muda os ids de `/projeto/{id}`.

### Outros ajustes

- **`PageCacheService`:** página com `q` preenchido não vai mais para o cache de páginas. Sem isso, cada termo
  pesquisado viraria um arquivo.
- **`.env.test`:** `PAGE_CACHE_ENABLED=false`. Os testes de rotas públicas sem cookie de sessão gravavam páginas com
  dados do banco de teste em `var/cache/public_pages`, que o ambiente dev servia.

### Publicação em produção

1. Backup: `app:database:dump`.
2. Código e banco: `doctrine:migrations:migrate -n --env=prod`.
3. Assets: `build.sh`, que roda `tailwind:build --minify` e `asset-map:compile`. Sem isso, `graph.js`, `a11y.js` e
   `math.js` dão 404.
4. Projetos antigos:
   - `app:projects:backfill-members --dir=docs/banco/CECH --dry-run --env=prod` para conferir;
   - o mesmo comando sem `--dry-run` para gravar.
5. Conferir que o índice temático está populado (`thematic_terms`); se não estiver, rodar `app:index:topics`.
6. `app:cache:clear --env=prod`.
