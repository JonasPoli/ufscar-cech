# indicators Specification

## Purpose
Página de Indicadores (`/indicadores`): números globais do CECH, um panorama filtrável da pesquisa e as 18 figuras cienciométricas, mais o ranking interativo de docentes por Qualis A, organizadas em quatro abas carregadas sob demanda, com uma versão completa para impressão. As figuras das abas mostram sempre o CECH inteiro.

## Requirements

### Requirement: Números globais do CECH
A página SHALL mostrar no topo cinco números do portal inteiro, que não mudam com filtros: docentes com vínculo vigente ("Docentes Ativos"), produções únicas, artigos com Qualis únicos, orientações concluídas e livros e capítulos únicos. Cada número único MUST vir com o total de registros nos currículos. A produção única segue a regra do DOI ou, sem DOI, de tipo, ano e título.

#### Scenario: Artigo Qualis em dois currículos
- **WHEN** um artigo com Qualis e DOI está declarado no currículo de dois docentes
- **THEN** o card de artigos Qualis soma 1 artigo único e 2 registros nos Lattes

#### Scenario: Capítulo com ISBN
- **WHEN** dois currículos declaram o mesmo capítulo, com o mesmo ISBN e o mesmo título
- **THEN** ele conta uma vez em livros e capítulos únicos

#### Scenario: Filtro no panorama
- **WHEN** a página é aberta com `departamento=ED`
- **THEN** os cinco números globais continuam iguais aos da página sem filtro

### Requirement: Panorama filtrável
A página SHALL ter a seção "Panorama da pesquisa", com o panorama (`research-panorama`) do filtro da URL, incluindo as pontes. A seção MUST aceitar todos os parâmetros do filtro compartilhado (descrito em `expert-search`), e o formulário SHALL oferecer Assunto, Departamento, Tema (assuntos do catálogo do Explorar) e Período, voltando a `/indicadores#panorama`. Os filtros MUST valer só para o panorama, e a seção MUST ficar oculta na versão completa.

#### Scenario: Panorama de um tema
- **WHEN** a pessoa escolhe um tema e aplica o filtro
- **THEN** a página volta à seção do panorama com os números, a série anual, os assuntos e as pontes desse tema
- **AND** as figuras das abas continuam mostrando o CECH inteiro

### Requirement: Abas e versão completa
A página SHALL organizar as figuras em quatro abas, "1. Corpo Docente & Vínculos", "2. Formação & Orientações", "3. Produção, Qualis & Bases" e "4. Redes & Parcerias", mais "Todos os 19 Indicadores". O parâmetro `tab` (`faculty`, `training`, `production`, `network` ou `all`) MUST escolher a aba aberta ao carregar, com `faculty` para valores ausentes ou inválidos, e o servidor SHALL montar só a aba pedida. `tab=all` ou `print=1` MUST montar as quatro abas de uma vez.

#### Scenario: Aba inválida
- **WHEN** a pessoa abre `/indicadores?tab=xyz`
- **THEN** a página abre na aba de corpo docente

#### Scenario: Versão completa
- **WHEN** a pessoa abre `/indicadores?tab=all`
- **THEN** a página traz as Figuras 1 a 18 e o ranking Qualis, sem a seção do panorama

### Requirement: Carregamento das abas sob demanda
O sistema SHALL responder `/indicadores/fragment/{tab}` (`faculty`, `training`, `production` ou `network`) com o conteúdo da aba, público e válido por uma hora; outro valor MUST receber 404. Na página, cada aba SHALL ser buscada só na primeira abertura, e a troca de aba MUST atualizar a âncora do endereço (`#faculty`, `#training`, `#production`, `#network` ou `#all`) sem recarregar; abrir a página com uma dessas âncoras abre a aba correspondente.

#### Scenario: Aba desconhecida no fragmento
- **WHEN** alguém pede `/indicadores/fragment/invalid-tab`
- **THEN** a resposta é 404

#### Scenario: Todos os indicadores
- **WHEN** a pessoa aciona "Todos os 19 Indicadores"
- **THEN** as abas que faltam são carregadas, com o aviso "Carregando indicadores...", e as quatro aparecem juntas

#### Scenario: Falha ao carregar uma aba
- **WHEN** a consulta do conteúdo de uma aba falha
- **THEN** a aba mostra "Não foi possível carregar esta seção." e o botão "Tentar Novamente", que recarrega a página

### Requirement: Imprimir relatório
O botão "Imprimir Relatório" SHALL carregar todas as abas que faltam, mostrar todas e abrir a impressão do navegador.

#### Scenario: Impressão a partir de uma aba
- **WHEN** a pessoa está na aba de formação e aciona "Imprimir Relatório"
- **THEN** as quatro abas aparecem na página antes de a janela de impressão abrir

### Requirement: Composição do corpo docente
A aba de corpo docente SHALL trazer a Figura 1, com os docentes de vínculo vigente por departamento, do maior para o menor, somando as grafias equivalentes do mesmo departamento e agrupando quem não tem departamento em "Outros"; a Figura 6, com o número de áreas de atuação declaradas por grande área; e a Figura 7, com as 15 unidades da federação de nascimento mais frequentes entre todos os docentes.

#### Scenario: Códigos equivalentes
- **WHEN** há docentes vigentes gravados com os códigos `CS` e `DCSo`
- **THEN** a Figura 1 mostra uma só barra para Ciências Sociais, somando os dois

#### Scenario: Docente com vínculo encerrado
- **WHEN** um docente tem vínculo encerrado
- **THEN** ele não entra na Figura 1, mas a naturalidade dele entra na Figura 7

### Requirement: Formação do corpo docente
A aba de corpo docente SHALL trazer a Figura 2, com os cursos de graduação de todos os currículos consolidados em nomes canônicos, agrupados pela grande área do curso (Ciências Humanas, Ciências Sociais Aplicadas, Linguística, Letras e Artes, Engenharias, Ciências Exatas e da Terra, Ciências da Saúde, Ciências Biológicas, Ciências Agrárias e Outras, nessa ordem) e por quantidade em cada grupo; e a Figura 5, com os 10 cursos de doutorado canônicos mais frequentes.

#### Scenario: Grafias de um mesmo curso
- **WHEN** currículos trazem as graduações "Licenciatura em Pedagogia" e "Pedagogia"
- **THEN** a Figura 2 soma as duas em "Pedagogia", no grupo Ciências Humanas

### Requirement: Trajetória acadêmica
A aba de corpo docente SHALL trazer a Figura 3, que liga, para cada docente de vínculo vigente, a instituição da primeira graduação, do primeiro mestrado e do primeiro doutorado, com os nomes de instituições padronizados, e mostra o total analisado, a parcela com doutorado em instituições paulistas, o número e a parcela com doutorado no exterior e a parcela com doutorado na UFSCar, com um seletor de departamento.

#### Scenario: Doutorado direto
- **WHEN** um docente vigente tem graduação e doutorado no currículo, mas nenhum mestrado
- **THEN** a etapa de mestrado da trajetória dele aparece como "Doutorado Direto"

#### Scenario: Nível não informado
- **WHEN** um docente vigente não tem graduação registrada
- **THEN** a etapa de graduação aparece como "Graduação Não Inf."

### Requirement: Destino dos egressos
A aba de corpo docente SHALL trazer a Figura 4 para os docentes com vínculo encerrado ou ano de saída anterior ao ano corrente. O destino MUST ser a experiência profissional fora da UFSCar e de São Carlos, sem as editoriais ou de periódicos, preferindo a atual, a posterior à saída e a de vínculo funcional, com o endereço profissional do Lattes como alternativa. A figura SHALL mostrar o fluxo departamento → categoria → instituição, o ranking de destinos, a tabela e o resumo.

#### Scenario: Números de resumo
- **WHEN** a figura é exibida
- **THEN** ela mostra quantos egressos têm destino mapeado sobre o total, a parcela em ensino superior público e a parcela fora do estado ou no exterior

#### Scenario: Egresso sem experiência fora da UFSCar
- **WHEN** um docente com vínculo encerrado só tem experiências na UFSCar e não informa endereço profissional fora dela
- **THEN** ele aparece na tabela como "Não mapeado / Aposentado" e não conta no total mapeado

#### Scenario: Virada do ano
- **WHEN** em 2027 um docente ainda marcado como vigente tem ano de saída 2026
- **THEN** ele entra nos egressos, como no perfil, onde já aparece como inativo

### Requirement: Formação e orientações
A aba de formação SHALL trazer a Figura 8, com as orientações concluídas por ano, de 2010 ao ano corrente, em séries de Mestrado, Doutorado, Pós-Doutorado e Iniciação Científica; a Figura 9, com o total de orientações declaradas, concluídas ou em andamento, por nível (Iniciação Científica, TCC / Graduação, Especialização, Mestrado, Doutorado e Pós-Doutorado); e a Figura 10, com os 10 cursos canônicos mais frequentes dos orientandos.

#### Scenario: Orientação em andamento
- **WHEN** um docente declara uma orientação de mestrado em andamento
- **THEN** ela entra na Figura 9, mas não na Figura 8

#### Scenario: Ano sem orientações concluídas
- **WHEN** nenhum doutorado foi concluído em 2012
- **THEN** a série de Doutorado da Figura 8 mostra zero em 2012

### Requirement: Produção e Qualis
A aba de produção SHALL trazer a Figura 11, matriz do ano corrente até 2010 por tipo de produção, com os totais por linha, por coluna e geral; a Figura 12, com os artigos com e sem Qualis por ano, de 2010 ao ano corrente; e a Figura 13, com os artigos por estrato Qualis (A1 a A4, B1 a B4 e C) por ano e o total de cada estrato. As três figuras MUST contar registros dos currículos, sem unificar publicações repetidas.

#### Scenario: Artigo em dois currículos na matriz
- **WHEN** um artigo de 2020 está declarado no currículo de dois docentes
- **THEN** a Figura 11 soma 2 na célula de 2020 e artigos

#### Scenario: Tipos técnicos na matriz
- **WHEN** há patentes e marcas registradas e nenhum registro de "demais produções técnicas"
- **THEN** patentes e marcas somam na coluna "Softwares & Patentes" e a coluna "Demais Produções Técnicas" não aparece

#### Scenario: Estrato desconhecido
- **WHEN** um artigo traz um valor de Qualis fora de A1 a C
- **THEN** ele conta na Figura 12 como artigo com Qualis, mas não aparece na Figura 13

### Requirement: Ranking interativo por Qualis A
A aba de produção SHALL trazer o ranking dos docentes com pelo menos um artigo A1, A2, A3 ou A4. A pontuação MUST ser a soma dos artigos dos estratos ligados (só A1 no início) e, quando há bases de indexação escolhidas, só contam os artigos de periódicos presentes em pelo menos uma delas. O ranking SHALL mostrar só quem tem pontuação maior que zero, ordenar pela pontuação e, no empate, pelo nome, destacar o líder e permitir filtrar pelo nome do docente ou do departamento.

#### Scenario: Combinar estratos
- **WHEN** a pessoa liga A1 e A2
- **THEN** a pontuação de cada docente passa a ser a soma dos artigos A1 e A2 e a ordem é recalculada

#### Scenario: Escolher uma base
- **WHEN** a pessoa escolhe uma base de indexação
- **THEN** só contam os artigos dos estratos ligados publicados em periódicos dessa base, e quem fica com zero sai do ranking
- **AND** "Todas as Bases" desfaz a escolha

### Requirement: Bases de indexação
A aba de produção SHALL mostrar o total de artigos cujo periódico está em alguma base de indexação e a parcela sobre todos os artigos, os três primeiros colocados, a Figura 14, com o número de artigos por base (contados por registro de currículo), a parcela sobre todos os artigos e sobre os artigos indexados, e a Figura 15, com a evolução anual de cada base de 2010 ao ano corrente.

#### Scenario: Artigo em periódico de duas bases
- **WHEN** um artigo foi publicado num periódico presente na Scopus e na SciELO
- **THEN** ele conta uma vez no total de artigos indexados e uma vez em cada uma das duas bases na Figura 14

### Requirement: Rede de coautoria e exportação
A aba de redes SHALL trazer a Figura 16, calculada sobre toda a produção, sem filtros e com docentes de qualquer vínculo, contando como uma a publicação de mesmo DOI ou, sem DOI, de mesmo tipo e título: os 8 pares com mais publicações em comum, o total de conexões e a matriz docente × docente de todos os que têm coautoria interna, em ordem alfabética. A pessoa MUST poder baixar a rede para o VOSviewer e a lista de pares em CSV.

#### Scenario: Exportar para o VOSviewer
- **WHEN** a pessoa aciona "Exportar para VOSviewer (.txt)"
- **THEN** o navegador baixa `rede_coautoria_cech_vosviewer.txt`, com a seção `*Vertices` (um vértice por docente com coautoria) e a seção `*Edges` (uma linha por par, com o número de obras em parceria)

#### Scenario: Exportar a matriz em CSV
- **WHEN** a pessoa aciona "Matriz CSV"
- **THEN** o navegador baixa `matriz_coautoria_cech.csv`, separado por ponto e vírgula, com as colunas Docente 1, Departamento 1, Docente 2, Departamento 2 e Obras em Parceria

#### Scenario: Destacar na matriz
- **WHEN** a pessoa digita parte de um nome no campo "Destacar docente na matriz..."
- **THEN** as linhas e colunas de outros docentes ficam esmaecidas

### Requirement: Parcerias institucionais
A aba de redes SHALL trazer a Figura 17, com as 10 instituições brasileiras que mais aparecem nas experiências profissionais dos docentes, sem as da UFSCar, com os nomes padronizados pelo tesauro de instituições e sem as de outro país; e a Figura 18, com as 10 instituições estrangeiras que mais aparecem na formação acadêmica dos docentes, sem as da UFSCar e de São Carlos, consideradas estrangeiras pelo país do tesauro ou por nomes típicos de universidades estrangeiras.

#### Scenario: Grafias de uma instituição
- **WHEN** experiências profissionais citam "USP" e "Universidade de São Paulo" e o tesauro de instituições reconhece as duas grafias como a mesma instituição
- **THEN** a Figura 17 soma as duas numa só barra, com o nome padronizado

#### Scenario: Instituição estrangeira nas experiências profissionais
- **WHEN** o tesauro indica que uma instituição citada numa experiência profissional fica em outro país
- **THEN** ela não entra na Figura 17
