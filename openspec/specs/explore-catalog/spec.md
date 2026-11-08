# explore-catalog Specification

## Purpose
"Explorar pesquisa": catálogos de assuntos do índice temático, de áreas de atuação e de grandes áreas declaradas no Lattes, e a página de cada campo, com o panorama da produção, os docentes que mais publicam, a rede de coautoria e as publicações relacionadas, sem que a pessoa precise digitar uma busca. Os mesmos catálogos são entregues pela API pública (`public-api`).

## Requirements

### Requirement: Catálogo de assuntos
O sistema SHALL listar em `/explorar` até 300 assuntos do índice temático de origem palavra-chave ou híbrida, presentes na produção de pelo menos 5 docentes e com pelo menos 40 ocorrências, do mais frequente ao menos frequente (empate em ordem alfabética), cada um com o número de ocorrências e de docentes e o link para `/explorar/assunto/{slug}`. Termos minerados só dos títulos MUST ficar fora do catálogo.

#### Scenario: Termo com poucos docentes
- **WHEN** um termo de palavra-chave tem 80 ocorrências em 3 docentes
- **THEN** ele não aparece no catálogo de assuntos

#### Scenario: Mais assuntos que o limite
- **WHEN** 420 assuntos atendem aos critérios
- **THEN** o catálogo mostra os 300 mais frequentes e o aviso "os 300 mais frequentes de 420", indicando a busca para os demais

### Requirement: Catálogo de áreas de atuação
O sistema SHALL listar em `/explorar/areas` as áreas de atuação declaradas no Lattes (sem as vazias), cada uma com o número de docentes que a declaram, o número de publicações únicas desses docentes (toda a produção deles) e o link para `/explorar/area/{slug}`, agrupadas pela grande área (sem grande área conhecida, em "Outros"). Os grupos SHALL vir da maior soma de publicações para a menor e, em cada grupo, as áreas da que tem mais publicações para a que tem menos.

#### Scenario: Área declarada por dois docentes
- **WHEN** dois docentes declaram a área "Educação" na grande área Ciências Humanas
- **THEN** "Educação" aparece no grupo Ciências Humanas com 2 docentes e as publicações únicas dos dois somadas sem repetição

### Requirement: Catálogo de grandes áreas
O sistema SHALL listar em `/explorar/grandes-areas` as grandes áreas do CNPq presentes nas áreas de atuação dos docentes, juntando as grafias por código e por extenso antes de contar, cada uma com o nome, o número de docentes, o número de publicações únicas desses docentes, a parcela em relação à soma das publicações das grandes áreas listadas ("% da produção") e o link para `/explorar/grande-area/{slug}`, da que tem mais publicações para a que tem menos.

#### Scenario: Grafia por extenso
- **WHEN** um registro traz a grande área "Ciências Agrárias" por extenso e outro traz `CIENCIAS_AGRARIAS`
- **THEN** o catálogo mostra uma só grande área "Ciências Agrárias"

#### Scenario: Docente com as duas grafias
- **WHEN** um docente tem uma área gravada como `CIENCIAS_HUMANAS` e outra como "Ciências Humanas"
- **THEN** ele conta uma vez em Ciências Humanas, e as publicações dele também

### Requirement: Navegação e filtro nos catálogos
As três páginas de catálogo SHALL ter abas para alternar entre Assuntos, Áreas e Grandes áreas, uma capa com o total de assuntos do catálogo, de áreas e de grandes áreas, e um campo que filtra os itens pelo nome no próprio navegador, sem diferença de maiúsculas e acentos. Durante o filtro, a contagem MUST mostrar "N itens encontrados", os grupos sem itens visíveis MUST sumir e, sem nenhum item, a página SHALL mostrar "Nenhum item com esse nome." com link para a busca.

#### Scenario: Filtro sem acento
- **WHEN** a pessoa digita "educacao" no catálogo de áreas
- **THEN** a lista mostra as áreas cujo nome contém "Educação" e esconde os grupos que ficaram vazios

### Requirement: Página de um assunto
O sistema SHALL abrir em `/explorar/assunto/{slug}` a página de qualquer termo do índice temático, encontrado pelo slug ou pelo id numérico, com o recorte das publicações cujo título ou palavras-chave contêm o termo (sem diferença de maiúsculas e acentos). Um termo inexistente MUST receber 404 ("Assunto não encontrado.").

#### Scenario: Assunto existente
- **WHEN** a pessoa abre `/explorar/assunto/educacao-especial`
- **THEN** a página traz o panorama, os docentes, a rede e as publicações cujo título ou palavras-chave contêm "Educação Especial"

#### Scenario: Atalhos e assuntos relacionados
- **WHEN** a página de um assunto é aberta
- **THEN** ela mostra até 12 "Assuntos relacionados", com link para os que têm página, e os atalhos "Especialistas neste campo" (busca com o assunto) e "Qualis e periódicos do tema" (pesquisa temática do mesmo termo)

#### Scenario: Assunto inexistente
- **WHEN** a pessoa abre `/explorar/assunto/termo-que-nao-existe`
- **THEN** a resposta é 404

### Requirement: Página de uma área de atuação
O sistema SHALL abrir em `/explorar/area/{slug}` a página da área de atuação do catálogo com esse slug, com o recorte das publicações dos docentes que declaram essa área e a indicação "Área de atuação · {grande área}". Um slug que não está no catálogo MUST receber 404 ("Área de atuação não encontrada.").

#### Scenario: Área com filtro de departamento
- **WHEN** a pessoa abre `/explorar/area/educacao?departamento=ED`
- **THEN** a página considera só os docentes de ED que declaram a área "Educação"

#### Scenario: Área inexistente
- **WHEN** a pessoa abre `/explorar/area/area-que-nao-existe`
- **THEN** a resposta é 404

### Requirement: Página de uma grande área
O sistema SHALL abrir em `/explorar/grande-area/{slug}` a página de uma das grandes áreas do CNPq (inclusive "Outros"), identificada pelo slug do nome, com o recorte das publicações dos docentes que declaram essa grande área em qualquer grafia. Um slug que não corresponde a uma grande área MUST receber 404 ("Grande área não encontrada.").

#### Scenario: Grande área válida
- **WHEN** a pessoa abre `/explorar/grande-area/ciencias-humanas`
- **THEN** a página mostra o panorama dos docentes que declaram Ciências Humanas

### Requirement: Recortes adicionais na página de um campo
A página de um assunto, área ou grande área SHALL aceitar, além do campo que a define, só os recortes de texto (`q` e `exata`), departamento (`departamento`) e período (`de` e `ate`); os demais parâmetros do filtro compartilhado MUST ser ignorados. O formulário "Filtrar este campo" SHALL trazer Assunto, Departamento e Período, e a paginação e os links da página MUST manter os recortes adicionais.

#### Scenario: Parâmetro não aceito
- **WHEN** a pessoa abre `/explorar/area/educacao?tipo=livro`
- **THEN** a página mostra todos os tipos de publicação da área

#### Scenario: Período
- **WHEN** a pessoa abre a página de um assunto com `de=2018&ate=2020`
- **THEN** o panorama, os docentes, a rede e as publicações consideram só os anos de 2018 a 2020

### Requirement: Conteúdo da página de um campo
A página de um campo SHALL mostrar: a capa com o tipo do campo, o nome e os números de publicações, docentes e pares; o panorama do recorte com as pontes (`research-panorama`); até 12 "Docentes que mais publicam neste campo", pelo número de publicações do recorte; "Coautorias neste campo", com o grafo das 150 ligações mais fortes e os 6 pares mais fortes (`coauthorship-network`); e "Publicações relacionadas (N)", 12 por página, da mais recente para a mais antiga.

#### Scenario: Mais docentes do que os exibidos
- **WHEN** o campo tem 30 docentes com produção no recorte
- **THEN** a página mostra 12 e o link "Ver os 30 docentes", que leva à busca com o mesmo recorte

#### Scenario: Campo sem produção no recorte
- **WHEN** o recorte não tem publicações
- **THEN** a página mostra "Nenhum docente com produção neste recorte." e "Nenhuma publicação neste recorte."

#### Scenario: Segunda página de publicações
- **WHEN** a pessoa abre `/explorar/assunto/{slug}?pagina=2`
- **THEN** a lista mostra da 13ª à 24ª publicação mais recente

### Requirement: Atualização dos catálogos
O sistema SHALL guardar os catálogos de assuntos, áreas e grandes áreas por até um dia e MUST recalculá-los depois que o cache de páginas públicas é limpo.

#### Scenario: Índice temático regerado
- **WHEN** o índice temático é regerado e o cache de páginas públicas é limpo
- **THEN** o catálogo de assuntos passa a refletir os termos novos
