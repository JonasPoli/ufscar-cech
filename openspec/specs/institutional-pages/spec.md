# institutional-pages Specification

## Purpose
Páginas institucionais do portal público: "Sobre nós", "Sobre os dados" e "Dúvidas frequentes". Explicam o que é o portal, de onde vêm os dados e como interpretar os números; só "Sobre os dados" consulta o banco, para mostrar os números do momento.

## Requirements
### Requirement: Navegação entre as páginas institucionais
As três páginas SHALL ter uma navegação comum ("Páginas sobre o portal") com links para `/sobre`, `/sobre-os-dados` e `/duvidas`, marcando a página atual. "Sobre nós" e "Sobre os dados" SHALL ter um índice "Nesta página" com âncoras para as seções, e "Dúvidas frequentes" um índice "Categorias".

#### Scenario: Página atual marcada
- **WHEN** a pessoa abre `/sobre`
- **THEN** o link "Sobre nós" da navegação aparece marcado como página atual

### Requirement: Sobre nós
O sistema SHALL oferecer `/sobre` com as seções "O que é o portal", "O que você encontra aqui" (com um cartão e um link para cada recurso: busca de especialistas, perfis, coautorias, Explorar, projetos, indicadores, periódicos e departamentos), "De onde vêm os dados" (com link para "Sobre os dados"), "Quem mantém e quem desenvolve" (o CECH mantém; o NIT Materiais desenvolve, com link externo seguro) e "Acessibilidade".

#### Scenario: Links para os recursos
- **WHEN** a pessoa abre `/sobre`
- **THEN** a seção "O que você encontra aqui" traz links para `/coautorias`, `/explorar` e `/indicadores`, entre outros

### Requirement: Números de "Sobre os dados"
O sistema SHALL mostrar em `/sobre-os-dados`, contados quando a página é gerada: docentes com vínculo vigente; currículos no portal, com a nota de quantos têm vínculo encerrado; publicações únicas (DOI ou, sem DOI, tipo, ano e título); total de termos do índice temático; orientações concluídas e em andamento; e as datas, no formato dia/mês/ano, da atualização mais recente e da mais antiga feitas pelo próprio docente no currículo Lattes (não a data da importação).

#### Scenario: Docentes com vínculo encerrado
- **WHEN** o portal tem 415 currículos, dos quais 380 com vínculo vigente
- **THEN** a página mostra 380 docentes com vínculo vigente e 415 currículos, com a nota "Inclui 35 docentes com vínculo encerrado."

#### Scenario: Todos com vínculo vigente
- **WHEN** nenhum currículo do portal tem vínculo encerrado
- **THEN** a nota dos currículos diz "Todos com vínculo vigente."

#### Scenario: Sem datas de atualização
- **WHEN** nenhum currículo traz a data de atualização do Lattes
- **THEN** os dois números de atualização mostram "Sem dados"

### Requirement: Fontes e interpretação dos dados
A página `/sobre-os-dados` SHALL explicar, em seções com âncora, as fontes (Currículo Lattes, planilha de docentes do CECH, Repositório TeD-UFSCar, Qualis CAPES e bases internacionais), a atualização dos currículos, como interpretar os números, a metodologia de periódicos, Qualis e bases, os limites dos dados e como pedir correções.

#### Scenario: Definição de publicação única
- **WHEN** a pessoa lê "Como interpretar os números"
- **THEN** o texto informa que a publicação conta uma vez quando o DOI é igual ou, sem DOI, quando o tipo, o ano e o título são iguais
- **AND** a seção explica também coautoria entre docentes do CECH, vínculo, orientações concluídas e em andamento, Qualis, bases internacionais, assuntos, áreas e departamento

### Requirement: Dúvidas frequentes
O sistema SHALL oferecer `/duvidas` com as categorias "Busca e resultados", "Perfis e coautorias", "Projetos", "Dados e cobertura" e "Acessibilidade", cada uma com âncora própria e pelo menos duas perguntas, e cada pergunta com âncora própria. Abrir `/duvidas#{id}` de uma pergunta (ou trocar para essa âncora) MUST abrir a resposta correspondente. Com JavaScript, a página SHALL mostrar os botões para abrir e fechar todas as respostas.

#### Scenario: Link direto para uma pergunta
- **WHEN** a pessoa abre `/duvidas#exportar-rede`
- **THEN** a resposta sobre exportar a rede para o VOSviewer aparece aberta

#### Scenario: Abrir todas as respostas
- **WHEN** a pessoa aciona "Abrir todas as respostas"
- **THEN** todas as perguntas da página ficam abertas
