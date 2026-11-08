# expert-search Specification

## Purpose
Busca pública do portal em `/busca`: encontra quem pesquisa um assunto (com as publicações que comprovam), publicações únicas, docentes pelo nome e teses e orientações. Define também o filtro de pesquisa compartilhado (parâmetros de URL em português), a relevância textual, a ordenação e os pontos de entrada da busca (página inicial e cabeçalho). A versão JSON da busca (`/api/v1/busca` e `/api/v1/publicacoes`) está em `public-api`.

## Requirements

### Requirement: Modos da busca
O sistema SHALL oferecer em `GET /busca` os modos `especialistas`, `publicacoes`, `pesquisador` e `orientacoes` ("Teses e orientações"), escolhidos pelo parâmetro `modo`. Sem `modo` válido, o sistema SHALL aceitar o parâmetro antigo `type`; sem nenhum dos dois, um texto que comece por um DOI SHALL abrir o modo Publicações, e qualquer outro caso o modo Especialistas.

#### Scenario: DOI digitado sem escolher o modo
- **WHEN** o visitante acessa `/busca?q=10.1590/S0101-73302020` (com ou sem o prefixo `https://doi.org/` ou `https://dx.doi.org/`)
- **THEN** a busca roda no modo Publicações

#### Scenario: Link antigo com `type`
- **WHEN** a URL traz `type=researchers`, `type=productions` ou `type=orientations`, sem `modo`
- **THEN** a busca roda, respectivamente, nos modos Pesquisador, Publicações ou Teses e orientações
- **AND** `type=all` ou um valor desconhecido cai no modo Especialistas

#### Scenario: Modo explícito prevalece
- **WHEN** a URL traz `modo=especialistas` e `type=productions`
- **THEN** vale o modo Especialistas

### Requirement: Filtro de pesquisa pela URL
O sistema SHALL montar o recorte a partir dos parâmetros `q`, `exata`, `departamento` (ou o antigo `dept`), `tema`, `grande_area`, `area`, `vinculo`, `tipo`, `de` e `ate`, os mesmos das outras páginas de pesquisa e da API pública, e MUST descartar valores inválidos sem falhar: `vinculo` aceita `vigente` ou `encerrado`; `tipo` aceita `artigo`, `livro`, `capitulo` ou `evento`; `grande_area` aceita o código ou o nome por extenso; `tema` aceita o slug ou o id de um termo do índice temático.

#### Scenario: Valores inválidos são ignorados
- **WHEN** a URL traz `vinculo=aposentado&tipo=patente&de=1800&departamento=all`
- **THEN** a busca roda sem filtro de vínculo, de tipo, de ano inicial e de departamento
- **AND** só valem anos entre 1950 e o ano corrente + 1, e um `q` com mais de 200 caracteres é cortado em 200

#### Scenario: Período invertido
- **WHEN** a URL traz `de=2020&ate=2010`
- **THEN** o recorte usa os anos de 2010 a 2020

#### Scenario: Tema desconhecido
- **WHEN** a URL traz `tema=slug-inexistente` e nenhum outro filtro
- **THEN** o tema é descartado e a página mostra a capa da busca, sem erro

### Requirement: Correspondência por texto e tema
O sistema SHALL encontrar trabalhos comparando o texto com o título e com as palavras-chave declaradas no Lattes, sem distinguir caixa e acentos e sem alterar o dado bruto. Sem `exata`, cada palavra significativa do texto (até 8, sem palavras de uma letra e palavras vazias em português, inglês e espanhol) MUST aparecer no título ou nas palavras-chave; com `exata=1`, a frase inteira MUST aparecer, na ordem. Um `tema` válido SHALL exigir também o nome do termo no título ou nas palavras-chave.

#### Scenario: Todas as palavras precisam aparecer
- **WHEN** o visitante busca `educacao inclusiva`
- **THEN** só entram trabalhos que tenham "educação" e "inclusiva" no título ou nas palavras-chave, em qualquer ordem e com ou sem acento

#### Scenario: Expressão exata
- **WHEN** o visitante busca `formação de professores` com "Buscar a expressão exata" marcado
- **THEN** só entram trabalhos com a frase "formação de professores" nessa ordem
- **AND** um título "professores em formação" fica de fora

#### Scenario: Texto só com palavras vazias
- **WHEN** o texto não tem nenhuma palavra significativa, como `de`
- **THEN** vale o texto inteiro como termo de busca

### Requirement: Filtros de recorte
O sistema SHALL combinar os filtros de recorte, que precisam ser todos atendidos: `departamento` pelo departamento do docente dono do currículo, aceitando as grafias alternativas do mesmo departamento; `grande_area` e `area` pelas áreas de atuação declaradas no currículo do docente; `vinculo` pela situação do vínculo do docente; `tipo` pelo tipo do trabalho; `de` e `ate` pelo ano do trabalho, inclusive.

#### Scenario: Departamento por grafia alternativa
- **WHEN** a URL traz `departamento=DCSo`
- **THEN** entram docentes gravados como `CS`, `DCSo`, `DCS` ou "Departamento de Ciências Sociais"

#### Scenario: Ex-docentes
- **WHEN** a URL traz `vinculo=encerrado`
- **THEN** só aparecem docentes com vínculo encerrado e seus trabalhos

#### Scenario: Trabalho sem ano com filtro de período
- **WHEN** há filtro `de` ou `ate`
- **THEN** trabalhos sem ano informado ficam de fora

### Requirement: Relevância
O sistema SHALL pontuar cada trabalho pelo texto e pelo tema: cada palavra no título vale 3 e nas palavras-chave vale 1; com mais de uma palavra, o texto completo no título soma 5; com `exata`, a frase no título vale 8 e nas palavras-chave 3; o tema soma como uma palavra; no modo Publicações, o texto no nome do periódico, no nome do evento ou no DOI soma 1. Sem texto nem tema, todos os trabalhos valem o mesmo.

#### Scenario: Título pesa mais que palavra-chave
- **WHEN** um trabalho tem o termo no título e outro só nas palavras-chave
- **THEN** o primeiro fica à frente na ordenação por relevância

#### Scenario: Expressão completa no título
- **WHEN** o visitante busca `formação de professores` sem `exata`
- **THEN** o trabalho com a expressão completa no título fica à frente de um que tem as palavras separadas no título

### Requirement: Modo Especialistas
No modo Especialistas, o sistema SHALL listar, 12 por página, os docentes donos de trabalhos que atendem ao filtro. A nota do docente SHALL ser a maior nota entre seus trabalhos, e a contagem de publicações SHALL considerar obras únicas. Cada docente SHALL vir com seus dois trabalhos de maior nota e com o link "Ver N publicações" para o perfil, levando o filtro e `aba=busca`. O resultado SHALL informar o total de docentes e o de publicações únicas relacionadas.

#### Scenario: Docente com coautoria interna
- **WHEN** a busca `leitura` casa com três obras de uma docente, duas delas também no currículo de outro docente
- **THEN** a docente aparece com "Ver 3 publicações"
- **AND** o total de publicações relacionadas conta cada obra uma vez

#### Scenario: Nome digitado no modo Especialistas
- **WHEN** o visitante busca `roniberto` no modo Especialistas e está na primeira página
- **THEN** além dos especialistas, aparece o quadro "Docentes com “roniberto” no nome", com até 6 docentes
- **AND** havendo mais, o link "Ver todos (N)" leva à mesma busca no modo Pesquisador

#### Scenario: Empate de nota entre trabalhos
- **WHEN** vários trabalhos de um docente têm a mesma nota
- **THEN** os dois exibidos são os mais recentes entre eles

### Requirement: Ordenação
O sistema SHALL ordenar pelo parâmetro `ordem`: no modo Especialistas, `relevancia`, `recentes`, `publicacoes` e `nome`; no modo Publicações, `relevancia`, `recentes`, `antigas` e `titulo`. Sem `ordem` válida, o padrão SHALL ser `relevancia` quando houver texto ou tema, `nome` no modo Pesquisador e `recentes` nos demais casos. O seletor de ordem SHALL aparecer só nesses dois modos e quando houver mais de um resultado.

#### Scenario: Recorte sem texto
- **WHEN** a URL traz só `departamento=ED` no modo Especialistas
- **THEN** a ordem padrão é "Mais recentes"
- **AND** com `q=infância&ordem=aleatoria` a ordem aplicada é "Relevância"

#### Scenario: Desempate entre especialistas
- **WHEN** dois especialistas têm a mesma nota na ordem por relevância
- **THEN** fica à frente o que tem mais publicações e, persistindo o empate, vale a ordem alfabética do nome
- **AND** na ordem `recentes` vale o ano do trabalho mais recente de cada docente

#### Scenario: Publicações mais antigas
- **WHEN** a URL traz `modo=publicacoes&ordem=antigas`
- **THEN** as publicações vêm da mais antiga para a mais recente, com as sem ano por último

### Requirement: Modo Publicações
No modo Publicações, o sistema SHALL listar obras únicas, 15 por página: a mesma obra declarada em vários currículos, ou duas vezes no mesmo, SHALL aparecer uma vez, identificada pelo DOI ou, sem DOI, por tipo, ano e título. Além do título e das palavras-chave, o texto SHALL encontrar trabalhos pelo periódico, pelo evento, pelo DOI e por qualquer nome da família da revista. Cada obra SHALL listar os docentes do CECH em cujo currículo aparece.

#### Scenario: Obra em coautoria conta uma vez
- **WHEN** a mesma obra com o mesmo DOI está nos currículos de dois docentes
- **THEN** ela aparece uma vez, com os dois docentes como donos

#### Scenario: Busca pelo periódico
- **WHEN** o visitante busca, com ao menos 3 letras, o nome de uma revista no modo Publicações
- **THEN** aparecem os artigos publicados nela, inclusive os gravados com outra grafia da mesma família
- **AND** o mesmo texto no modo Especialistas não acha trabalhos só pelo periódico

### Requirement: Modo Pesquisador
No modo Pesquisador, o sistema SHALL listar docentes cujo nome completo, nomes de citação, ID Lattes ou ORCID contenham o texto, 24 por página, primeiro os de vínculo vigente e depois por nome. Apenas os filtros `departamento`, `vinculo`, `grande_area` e `area` SHALL se aplicar; os filtros de trabalho (`tipo`, período, `tema`, `exata`) MUST ser ignorados. Cada docente SHALL mostrar o total de produções no currículo e o ano da mais recente.

#### Scenario: Busca por nome com vínculo encerrado
- **WHEN** o visitante busca `Rede` no modo Pesquisador com `vinculo=encerrado`
- **THEN** aparecem só os docentes com "Rede" no nome e vínculo encerrado, com o período no CECH

#### Scenario: Busca pelo ID Lattes
- **WHEN** o visitante digita parte de um ID Lattes no modo Pesquisador
- **THEN** o docente com esse ID aparece no resultado

### Requirement: Modo Teses e orientações
No modo `orientacoes`, o sistema SHALL listar orientações cujo título, título alternativo, nome do orientando ou palavras-chave contenham o texto, 15 por página, das mais recentes para as mais antigas, aplicando também tema, `departamento`, `grande_area`, `area` e `vinculo` do orientador e o período pelo ano. O tipo de publicação MUST NOT se aplicar e fica desativado no formulário. Cada orientação SHALL mostrar tipo, ano, situação, título, orientando, curso, orientador e link do Repositório.

#### Scenario: Tese encontrada pela palavra-chave
- **WHEN** o visitante busca uma palavra-chave de uma dissertação no modo Teses e orientações
- **THEN** a dissertação aparece com o orientando, o orientador e, se houver, o link do repositório

#### Scenario: Período e vínculo
- **WHEN** o visitante busca no modo Teses e orientações com `de=2020` e `vinculo=vigente`
- **THEN** só aparecem orientações de 2020 em diante, de orientadores com vínculo vigente

### Requirement: Apresentação de docentes e trabalhos nos resultados
O sistema SHALL apresentar cada docente com foto ou iniciais, nome, departamento, situação do vínculo e período no CECH, e cada trabalho com ano, tipo, título, veículo como veio do Lattes (periódico, evento ou editora), ISSN, estrato Qualis, link do DOI e até 10 autores, em que os docentes do CECH identificados aparecem com link para o perfil.

#### Scenario: Coautor do CECH vira link
- **WHEN** um trabalho tem um autor identificado como docente do CECH
- **THEN** esse autor aparece com o nome cadastrado e link para o perfil, e os demais com o nome como veio do Lattes
- **AND** com mais de 10 autores, a lista termina em "et al."

#### Scenario: Período no CECH
- **WHEN** um docente com vínculo encerrado entrou em 1980 e saiu em 2015
- **THEN** o cartão mostra "Vínculo encerrado" e "1980–2015"
- **AND** um docente com vínculo vigente desde 2005 aparece com "Vínculo vigente" e "2005–hoje"

### Requirement: Capa da busca e estado vazio
Sem nenhum filtro, `/busca` SHALL mostrar a capa com os números de docentes com produção, publicações únicas e assuntos do catálogo, até 14 assuntos mais frequentes levando a `/busca?tema={slug}` e atalhos para coautorias, explorar e projetos. A capa SHALL ser indexável por buscadores, e páginas com filtro SHALL ser marcadas `noindex, follow`. Um recorte sem resultados SHALL mostrar "Nenhum resultado para esta busca." com sugestões.

#### Scenario: Busca vazia
- **WHEN** o visitante acessa `/busca` sem parâmetros
- **THEN** a página responde com sucesso e mostra "Por onde você quer começar?" com os assuntos mais frequentes

#### Scenario: Recorte sem resultado
- **WHEN** a busca não encontra nada
- **THEN** a página sugere variações em português ou inglês, desmarcar a expressão exata ou remover filtros

### Requirement: Paginação e links compartilháveis
O sistema SHALL manter todo o estado da busca na URL (GET), para que qualquer resultado possa ser compartilhado. A página SHALL vir de `pagina` (ou do nome antigo `page`), mínimo 1; nos modos Especialistas, Publicações e Pesquisador, uma página além da última SHALL mostrar a última. Os links de paginação e ordenação SHALL repetir o filtro sem parâmetros vazios. "Limpar filtros" SHALL voltar a `/busca` mantendo só o modo, e o selo do tema ativo SHALL ter um link que remove apenas o tema.

#### Scenario: Página além da última
- **WHEN** a busca de especialistas tem 2 páginas e a URL traz `pagina=9`
- **THEN** é exibida a página 2

#### Scenario: Remover o tema
- **WHEN** a busca tem `tema=educacao-especial` e `q=leitura`
- **THEN** o selo "Tema: Educação Especial" tem um link para a mesma busca sem `tema`

### Requirement: Busca no cabeçalho e no menu do celular
O sistema SHALL oferecer a busca no cabeçalho das páginas públicas, a partir de telas médias, com um seletor de modo (Especialistas, Publicações, Pesquisador e Teses) e o campo de texto, oculta na própria página de busca; no celular, o menu lateral SHALL ter um campo de texto simples. Ambos SHALL enviar para `/busca`. A tecla `/`, fora de campos de texto, SHALL levar o foco ao campo de busca do cabeçalho. A caixa de busca da página inicial é descrita em `portal-home`.

#### Scenario: Atalho de teclado
- **WHEN** o visitante tecla `/` numa página pública sem estar digitando em um campo
- **THEN** o cursor vai para o campo de busca do cabeçalho

#### Scenario: Busca pelo cabeçalho
- **WHEN** o visitante escolhe "Teses" no cabeçalho e busca `evasão escolar`
- **THEN** é levado a `/busca?modo=orientacoes&q=evasão escolar`

#### Scenario: Busca pelo menu do celular
- **WHEN** o visitante digita um DOI no campo do menu lateral e envia
- **THEN** é levado a `/busca?q=…`, que abre no modo Publicações por causa do DOI
