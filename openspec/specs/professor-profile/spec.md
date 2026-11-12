# professor-profile Specification

## Purpose
Perfil público de cada docente do CECH em `/professor/{slugOrId}`: identificação, vínculo e período no Centro, assuntos em destaque, coautores no CECH, indicadores rápidos, quatro abas (produção, orientações e formação, projetos e atuação, métricas) e a aba "Publicações da busca" quando o perfil é aberto a partir da busca, além das exportações do currículo.

## Requirements

### Requirement: Endereço do perfil
O sistema SHALL servir o perfil em `GET /professor/{slugOrId}`, localizando o docente pelo slug, pelo ID Lattes ou, quando o valor é numérico, também pelo id interno. Docente não encontrado MUST responder 404. O endereço `/docentes/{slugOrId}` SHALL redirecionar com 301 para `/professor/{slugOrId}`. Os links internos para o perfil SHALL usar o slug e, sem slug, o ID Lattes.

#### Scenario: Perfil pelo slug
- **WHEN** um visitante abre `/professor/maria-silva`
- **THEN** a página mostra o perfil do docente com esse slug

#### Scenario: Perfil pelo ID Lattes
- **WHEN** um visitante abre `/professor/` seguido do ID Lattes de 16 dígitos de um docente
- **THEN** a página mostra o perfil desse docente

#### Scenario: Endereço antigo
- **WHEN** um visitante abre `/docentes/maria-silva`
- **THEN** o sistema responde 301 para `/professor/maria-silva`

### Requirement: Cabeçalho de identificação
O sistema SHALL mostrar no topo do perfil a foto (ou as iniciais do nome, quando não há foto ou ela falha ao carregar), o nome completo, o código do departamento, os nomes em citação (as variantes do tesauro de autores e, sem elas, os nomes em citação do Lattes), o departamento, a data da última atualização do Lattes, os idiomas, o resumo do Currículo Lattes e os links para o Currículo Lattes, o ORCID e o e-mail quando existirem.

#### Scenario: Docente sem foto
- **WHEN** o docente não tem foto cadastrada
- **THEN** o cabeçalho mostra as duas primeiras letras do nome em maiúsculas no lugar da foto

#### Scenario: Docente com ORCID
- **WHEN** o docente tem ORCID cadastrado
- **THEN** o cabeçalho mostra o link "ORCID" para o registro dele, aberto em nova aba

### Requirement: Vínculo e período no CECH
O sistema SHALL mostrar se o vínculo do docente com o CECH está vigente ("Vínculo vigente") ou encerrado ("Vínculo encerrado") e, quando houver anos, o período precedido do código do departamento (ou "CECH"): "{admissão}–hoje" para vínculo vigente; "{admissão}–{saída}", "até {saída}" ou "desde {admissão}" para vínculo encerrado, conforme os anos conhecidos.

#### Scenario: Vínculo vigente
- **WHEN** o docente está ativo e foi admitido em 2008
- **THEN** o cabeçalho mostra "Vínculo vigente · {departamento} 2008–hoje"

#### Scenario: Vínculo encerrado com os dois anos
- **WHEN** o docente não está ativo, foi admitido em 1995 e saiu em 2020
- **THEN** o cabeçalho mostra "Vínculo encerrado · {departamento} 1995–2020"

### Requirement: Assuntos em destaque e coautoria no Centro
O sistema SHALL mostrar até seis assuntos em destaque do docente, tirados do índice temático (termos de palavras-chave presentes na produção de pelo menos dois docentes, ordenados pelas ocorrências do docente), cada um com link para a página do assunto em `/explorar/assunto/{slug}`. O sistema SHALL mostrar também quantos coautores distintos o docente tem entre os docentes do CECH, quantas publicações compartilha com eles e o ano da publicação mais recente.

#### Scenario: Docente com coautores no CECH
- **WHEN** o docente assina publicações com três outros docentes do CECH em cinco trabalhos distintos
- **THEN** o cabeçalho mostra "3 coautores no CECH" e "5 publicações compartilhadas"

#### Scenario: Assunto clicável
- **WHEN** o visitante clica num assunto em destaque
- **THEN** ele vai para a página desse assunto em `/explorar/assunto/{slug}`

### Requirement: Indicadores rápidos
O sistema SHALL mostrar seis indicadores: artigos em periódicos (com o percentual com DOI), livros e capítulos, trabalhos em eventos, produção técnica (trabalhos técnicos, demais produções técnicas, softwares, patentes e marcas), orientações concluídas (com o número em andamento) e total do acervo com a média por ano. Uma orientação SHALL contar como em andamento quando a natureza indicar andamento; as demais contam como concluídas.

#### Scenario: Média por ano
- **WHEN** o docente tem 40 produções e registros de produção ou orientação em 10 anos distintos
- **THEN** o indicador de total mostra 40 e "Média: ~4 / ano", pois a média usa os anos distintos com produção ou orientação

### Requirement: Abas do perfil
O sistema SHALL organizar o currículo em quatro abas, cada uma com o número de itens: "Produção Científica & Técnica" (aberta por padrão), "Orientações & Formação", "Projetos, Bancas & Atuação" e "Métricas, Redes & Inteligência". As três primeiras SHALL vir na resposta inicial; a de métricas SHALL ser carregada sob demanda ao ser aberta. A aba aberta SHALL ficar no fragmento da URL (`#productions`, `#orientations`, `#activities`, `#analytics`).

#### Scenario: Link direto para as métricas
- **WHEN** o visitante abre `/professor/maria-silva#analytics`
- **THEN** a aba de métricas é aberta e o conteúdo dela é carregado

#### Scenario: Falha ao carregar a aba
- **WHEN** o carregamento da aba de métricas falha
- **THEN** a aba mostra que não foi possível carregar os dados e sugere recarregar ou clicar de novo

### Requirement: Fragmentos das abas
O sistema SHALL servir o conteúdo de cada aba em `GET /professor/{slugOrId}/fragment/{tab}` para `productions`, `orientations`, `activities` e `analytics`, com `Cache-Control: public, max-age=3600`. Aba desconhecida ou docente inexistente MUST responder 404.

#### Scenario: Fragmento válido
- **WHEN** o navegador pede `/professor/maria-silva/fragment/analytics`
- **THEN** a resposta é o HTML da aba de métricas com cache público de uma hora

#### Scenario: Aba desconhecida
- **WHEN** o navegador pede `/professor/maria-silva/fragment/outra`
- **THEN** o sistema responde 404

### Requirement: Filtro global do perfil
O sistema SHALL oferecer uma caixa de busca que filtra na hora os itens das abas por termo (título, orientando, projeto, autor, evento, instituição ou ano), sem diferenciar acentos, e o botão "Apenas com DOI". Quando o perfil é aberto com `tema`, `keyword` ou `q` na URL (e não vem da busca), a caixa SHALL vir preenchida, o filtro SHALL ser aplicado e a primeira aba com resultados (produção, orientações ou atividades) SHALL ser aberta, salvo quando a URL indicar outra aba.

#### Scenario: Chegada pela pesquisa temática
- **WHEN** o visitante abre `/professor/maria-silva?tema=letramento`
- **THEN** a caixa de busca vem com "letramento", os itens são filtrados e abre a primeira aba com correspondências

### Requirement: Aba "Publicações da busca"
O sistema SHALL mostrar a aba "Publicações da busca", já aberta, quando o perfil for aberto com `aba=busca` e algum filtro da busca de especialistas na URL. A aba SHALL listar as publicações do docente que correspondem aos filtros, por relevância, 20 por página (parâmetro `pagina`), e oferecer "Voltar à busca" para `/busca` com os mesmos filtros e o modo (`especialistas`, o padrão, ou `publicacoes`). Sem filtro, a aba MUST NOT aparecer.

#### Scenario: Perfil aberto a partir da busca
- **WHEN** o visitante abre `/professor/maria-silva?aba=busca&q=alfabetização&modo=publicacoes`
- **THEN** o perfil abre na aba "Publicações da busca" com as publicações dela que correspondem a "alfabetização" e um link de volta para `/busca?q=alfabetização&modo=publicacoes`

#### Scenario: Busca sem filtros
- **WHEN** o visitante abre `/professor/maria-silva?aba=busca` sem nenhum filtro
- **THEN** a aba "Publicações da busca" não aparece

### Requirement: Projetos no perfil
A aba "Projetos, Bancas & Atuação" SHALL listar os projetos declarados pelo docente, do mais recente para o mais antigo, com natureza, situação e link para a página do projeto no catálogo (`/projeto/{id}`). A lista SHALL ter um filtro rápido por nome e por situação, com a contagem de projetos por situação nas opções.

#### Scenario: Filtro rápido por situação
- **WHEN** o docente tem um projeto em andamento e um concluído
- **THEN** o filtro de situação oferece "Todas as situações", "Em andamento (1)" e "Concluído (1)"

### Requirement: Aba de métricas
A aba "Métricas, Redes & Inteligência" SHALL mostrar a evolução anual da produção por categoria e das orientações concluídas por nível, a distribuição por categoria, as bases de indexação dos artigos, os doze principais coautores, o mapa de colaboradores do docente na rede de coautoria do CECH, os principais pares de coautoria dele e as palavras-chave (até 24 declaradas nas produções e até 24 termos frequentes dos títulos de produções e projetos).

#### Scenario: Coautor que é docente do CECH
- **WHEN** um coautor frequente está ligado a um docente do CECH
- **THEN** ele aparece entre os principais coautores com link para o perfil desse docente, o período da parceria e exemplos de trabalhos

#### Scenario: Mesmo coautor com grafias diferentes
- **WHEN** um coautor aparece com duas grafias ligadas à mesma identidade do tesauro de autores
- **THEN** ele conta uma vez só na lista de principais coautores

#### Scenario: Bases de indexação
- **WHEN** parte dos artigos do docente está em revistas indexadas na Scopus e na SciELO
- **THEN** a aba mostra o ranking das bases com o total e o percentual de artigos de cada uma e a evolução por ano

### Requirement: Exportação do currículo
O sistema SHALL oferecer em `GET /professor/{slugOrId}/export/{format}` a exportação do currículo do docente em `bibtex`, `json` ou `csv`, localizando o docente pelo ID Lattes ou id interno (valor numérico) ou pelo slug. Formato fora desses três MUST NOT corresponder à rota, e docente inexistente MUST responder 404.

#### Scenario: Exportação em BibTeX
- **WHEN** o visitante clica em "BibTeX" no perfil
- **THEN** o navegador baixa as produções do docente em BibTeX

#### Scenario: Docente inexistente
- **WHEN** alguém pede `/professor/nao-existe/export/json`
- **THEN** o sistema responde 404

### Requirement: Metadados do perfil para buscadores
O perfil SHALL usar como título o nome do docente, como descrição os primeiros 160 caracteres do resumo do Lattes (ou um texto padrão com o nome), Open Graph do tipo `profile` com a foto do docente quando houver, dados estruturados Schema.org `ProfilePage`/`Person` e as metatags `citation_*` dos oito artigos mais recentes.

#### Scenario: Compartilhamento do perfil
- **WHEN** o link de um perfil com foto é compartilhado numa rede social
- **THEN** a prévia usa o nome do docente, o início do resumo e a foto dele

### Requirement: HTML do perfil independente do filtro
Fora de `aba=busca`, o HTML do perfil enviado pelo servidor MUST ser o mesmo para qualquer valor de `tema`, `keyword` ou `q`. O preenchimento da caixa de busca e a escolha da aba descritos no filtro global do perfil SHALL ser feitos pelo navegador, a partir da URL.

#### Scenario: Dois temas para o mesmo docente
- **WHEN** o servidor gera `/professor/maria-silva?tema=letramento` e `/professor/maria-silva?tema=ensino`
- **THEN** os dois HTML são iguais ao de `/professor/maria-silva`

#### Scenario: Caixa preenchida no navegador
- **WHEN** o visitante abre `/professor/maria-silva?tema=letramento` com JavaScript ligado
- **THEN** a caixa de busca mostra "letramento" e os itens aparecem filtrados, como antes

#### Scenario: Perfil aberto pela busca
- **WHEN** o visitante abre `/professor/maria-silva?aba=busca&q=alfabetização`
- **THEN** o servidor monta a aba "Publicações da busca" com o filtro, como antes
