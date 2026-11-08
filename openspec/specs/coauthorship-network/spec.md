# coauthorship-network Specification

## Purpose
Rede de coautoria entre docentes do CECH: a regra que liga dois docentes, o grafo interativo reaproveitado em várias páginas (coautorias, Explorar, departamento e perfil do docente), a página `/coautorias` e o conteúdo do grafo e das publicações de um par que a API pública entrega (as rotas estão em `public-api`).

## Requirements

### Requirement: Regra de coautoria
O sistema SHALL ligar dois docentes do CECH quando ambos aparecem, como autores reconhecidos como docentes do CECH, na mesma publicação. O peso da ligação MUST ser o número de publicações únicas do par (mesmo DOI ou, sem DOI, mesmo tipo, ano e título), e autores de fora do CECH MUST ficar fora da rede. Os pares são ordenados do maior peso para o menor.

#### Scenario: Obra declarada nos dois currículos
- **WHEN** Ana e Bruno assinam juntos um artigo com DOI, declarado no currículo de cada um, e um segundo trabalho sem DOI
- **THEN** a ligação entre Ana e Bruno tem peso 2

#### Scenario: Coautor externo
- **WHEN** uma publicação de Ana tem um coautor que não é docente do CECH
- **THEN** esse coautor não vira nó nem ligação na rede

### Requirement: Recorte da rede
A rede SHALL respeitar o filtro compartilhado da pesquisa (descrito em `expert-search`): texto, tema, tipo e período valem para a publicação; departamento, grande área, área de atuação e vínculo valem para o docente dono do currículo em que a publicação foi declarada.

#### Scenario: Rede de um departamento com parceiro de fora dele
- **WHEN** a rede é filtrada por `departamento=ED` e uma docente de ED declarou uma publicação feita com um docente de PS
- **THEN** o par entre os dois aparece na rede, e o docente de PS é desenhado com a cor e a forma de PS

### Requirement: Conteúdo do grafo de um recorte
O grafo de um recorte (entregue por `GET /api/v1/grafo`, descrita em `public-api`) SHALL conter as ligações mais fortes até o limite pedido em `ligacoes` (padrão 120; 0 = todas), os nós dos docentes presentes nelas (nome, foto ou iniciais, departamento, vínculo, número de ligações desenhadas e soma dos pesos), o nome de cada departamento presente e os totais de pessoas e de pares do recorte inteiro e do desenho.

#### Scenario: Limite padrão
- **WHEN** alguém pede `/api/v1/grafo` sem `ligacoes` e o recorte tem 500 pares
- **THEN** a resposta traz as 120 ligações mais fortes, `totalPairs` igual a 500 e `shownPairs` igual a 120

#### Scenario: Todas as ligações
- **WHEN** alguém pede `/api/v1/grafo?ligacoes=0`
- **THEN** a resposta traz todas as ligações do recorte

### Requirement: Vizinhança de um docente
Quando o grafo é pedido com `docente` (slug, ID Lattes ou ID numérico), o sistema SHALL entregar a vizinhança do docente: as ligações dele e as ligações entre os seus vizinhos, sem o limite de `ligacoes`, com o docente marcado como foco. O grafo SHALL destacar o docente em foco ao carregar, e a lista de pares da vizinhança MUST mostrar o docente em foco sempre à esquerda.

#### Scenario: Rede de coautoria no perfil
- **WHEN** o perfil de Ana carrega o grafo com `docente=ana` e `ligacoes=0`
- **THEN** o grafo mostra Ana, seus coautores do CECH e as ligações entre esses coautores, com Ana destacada

#### Scenario: Pares de uma vizinhança
- **WHEN** a lista de pares mostra os coautores de Bruno
- **THEN** Bruno aparece à esquerda em todos os cartões

### Requirement: Publicações de um par
As publicações de um par (entregues por `GET /api/v1/grafo/par`, descrita em `public-api`) SHALL ser as publicações únicas do recorte em que os dois docentes aparecem como autores: o total real e até 200 delas, da mais recente para a mais antiga, cada uma com título, ano, tipo, veículo, ISSN, DOI e até 10 autores (os do CECH com link para o perfil; acima de 10, "et al."), junto com os dados dos dois docentes.

#### Scenario: Par com duas publicações
- **WHEN** alguém pede as publicações de Ana e Bruno, que têm um trabalho de 2016 e outro de 2019 em comum
- **THEN** a resposta traz `count` igual a 2 e o trabalho de 2019 em primeiro lugar

#### Scenario: Par num recorte
- **WHEN** as publicações de Ana e Bruno são pedidas com `departamento=ED`
- **THEN** entram só as publicações em comum declaradas em currículos de docentes de ED

#### Scenario: Par com mais publicações do que a lista
- **WHEN** um par tem 260 publicações em comum
- **THEN** a lista traz 200 e `count` é 260

### Requirement: Leitura visual do grafo
O grafo interativo SHALL dar a cada departamento uma cor e uma forma próprias, com legenda que mostra o código, o nome completo como dica e o nome da forma para leitores de tela. O tamanho do nó MUST crescer com a soma dos pesos do docente e a espessura da ligação com o número de publicações do par. O rodapé SHALL informar "N pessoas · X de Y pares · maior espessura = mais publicações". A biblioteca de gráficos só MUST ser baixada quando a página tem um grafo.

#### Scenario: Nomes visíveis
- **WHEN** o grafo desenha mais de 40 pessoas
- **THEN** aparecem os nomes do docente em foco e dos docentes com pelo menos 8% da maior soma de pesos, e os demais nomes surgem ao passar o mouse sobre o nó

#### Scenario: Recorte sem coautoria
- **WHEN** o recorte não tem nenhum par
- **THEN** a área do grafo mostra "Nenhuma coautoria entre docentes do CECH para estes filtros."

#### Scenario: Falha ao consultar a rede
- **WHEN** a consulta do grafo falha
- **THEN** o rodapé mostra "Não foi possível consultar a rede."

### Requirement: Destacar um docente
O grafo SHALL permitir escolher um docente pelo clique no nó ou pela lista "Destacar docente" (docentes desenhados em ordem alfabética, mais "Toda a rede visível"). A escolha SHALL destacar o docente e seus vizinhos, mostrar uma faixa com o nome, o número de coautores na rede desenhada, a soma das publicações das ligações desenhadas, "Ver perfil" e "Limpar seleção", e trocar a lista de pares por "Pares de {nome}". O clique duplo num nó SHALL abrir o perfil do docente.

#### Scenario: Docente com coautores fora das ligações desenhadas
- **WHEN** o grafo desenha só as 120 ligações mais fortes e a pessoa destaca uma docente que tem 9 coautores no recorte, dos quais 3 aparecem no desenho
- **THEN** a faixa informa 3 coautores nesta rede
- **AND** a lista "Pares de {nome}" traz os 9 coautores, buscados na vizinhança completa e ordenados pelo número de publicações

#### Scenario: Voltar à rede inteira
- **WHEN** a pessoa aciona "Mostrar todos os pares", "Limpar seleção" ou o botão de centralizar
- **THEN** a seleção é desfeita e a lista dos pares mais fortes volta

### Requirement: Publicações em comum de um par na tela
Ao clicar numa ligação do grafo ou num cartão de par, o sistema SHALL abrir a janela "Pesquisa compartilhada", com o título "{docente A} × {docente B}", o total "N publicações em comum" e a lista das publicações do par no recorte da página, com link para o DOI em nova aba. A janela MUST fechar pelo botão Fechar ou por um clique fora dela, e uma falha MUST mostrar "Não foi possível carregar as publicações. Tente novamente.".

#### Scenario: Clique num cartão de par
- **WHEN** a pessoa clica no cartão do par Ana e Bruno na página de um assunto
- **THEN** a janela lista as publicações de Ana e Bruno que pertencem a esse assunto

### Requirement: Controles de navegação do grafo
O grafo SHALL oferecer ampliar e reduzir (entre 0,3 e 6 vezes), centralizar e limpar a seleção, tela cheia e mostrar ou ocultar os nomes, além de arrastar os nós e mover a área. As animações MUST ser desligadas quando a pessoa pede menos movimento (no aparelho ou no painel de acessibilidade), e o grafo SHALL ser redesenhado quando o tema claro ou escuro muda.

#### Scenario: Ocultar nomes
- **WHEN** a pessoa aciona o botão "Nomes"
- **THEN** os rótulos dos nós somem e o botão indica que está desligado

### Requirement: Pares que mais publicam juntos
As páginas com grafo SHALL mostrar, abaixo dele, os pares mais fortes do recorte como alternativa em texto ao desenho, cada um com a posição, as fotos ou iniciais e os nomes dos dois docentes e o número de publicações em comum, e o título informa o total de pares do recorte quando ele é conhecido.

#### Scenario: Página de um campo do Explorar
- **WHEN** a página de uma área de atuação é aberta
- **THEN** a seção de coautorias mostra os 6 pares mais fortes da área

### Requirement: Página de coautorias
O sistema SHALL oferecer `/coautorias` com a capa (docentes com pelo menos um par, pares e publicações compartilhadas do recorte), o formulário "Filtrar coautorias" (Assunto, Departamento, Tema e Período), o grafo, a lista de pares e um atalho para Indicadores › Redes de colaboração, onde ficam a matriz completa e a exportação para o VOSviewer. A página MUST aceitar na URL todos os parâmetros do filtro compartilhado, inclusive os que o formulário não mostra.

#### Scenario: Filtro por departamento
- **WHEN** a pessoa abre `/coautorias?departamento=ED`
- **THEN** a capa, o grafo e os pares consideram só as publicações dos currículos de ED

#### Scenario: Parâmetro fora do formulário
- **WHEN** a pessoa abre `/coautorias?tipo=artigo`
- **THEN** a rede considera só os artigos

### Requirement: Ligações exibidas e quantidade de pares
Na página `/coautorias`, a opção "Ligações exibidas" SHALL oferecer 60, 120, 250 ou Todas (`ligacoes`, padrão 120; valor inválido vale 120; `0` vale Todas), com links que mantêm os filtros e a quantidade de pares. A lista de pares SHALL mostrar `pares` pares (padrão 6, mínimo 6, máximo 300), com "Ver mais pares", que acrescenta 12 e volta à seção da rede, enquanto houver mais pares no recorte.

#### Scenario: Mais pares
- **WHEN** a página mostra 6 pares de um total de 40 e a pessoa clica em "Ver mais pares"
- **THEN** a página volta à seção da rede mostrando 18 pares, com os mesmos filtros e a mesma quantidade de ligações

#### Scenario: Limite de ligações inválido
- **WHEN** a pessoa abre `/coautorias?ligacoes=999`
- **THEN** o grafo desenha 120 ligações e a opção 120 aparece selecionada
