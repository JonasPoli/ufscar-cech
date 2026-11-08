# department-page Specification

## Purpose
Lista dos departamentos do CECH e página de cada departamento, com o corpo docente, os números de produção, o panorama da produção e a rede de coautoria do departamento.

## Requirements

### Requirement: Lista de departamentos
O sistema SHALL listar em `/departamentos` até 100 departamentos com pelo menos um docente de vínculo vigente, agrupados pelo código gravado (`DCSo` lido como `CS`; sem código, pelo nome), cada um com código, nome oficial, número de docentes vigentes e de registros de produção deles (sem unificar repetições), do que tem mais docentes para o que tem menos (empate pelas produções). Cada cartão MUST levar a `/departamento/{código}` ou, sem código, a `/departamento/{nome}`.

#### Scenario: Docente com vínculo encerrado
- **WHEN** um departamento tem 10 docentes com vínculo vigente e 3 com vínculo encerrado
- **THEN** o cartão do departamento informa 10 docentes

#### Scenario: Código antigo de Ciências Sociais
- **WHEN** há docentes vigentes gravados com os códigos `CS` e `DCSo`
- **THEN** a lista mostra um único cartão `CS`, "Departamento de Ciências Sociais", somando os dois grupos

### Requirement: Localização do departamento
O sistema SHALL abrir `/departamento/{codeOrSlug}` com os docentes cujo código de departamento gravado é igual ao valor da URL e, se não houver nenhum, com os docentes cujo nome de departamento gravado é igual ao valor. Sem nenhum docente, a resposta MUST ser 404 ("Departamento não encontrado."). O nome e o código exibidos SHALL ser os gravados no primeiro docente em ordem alfabética, com o valor da URL quando faltarem.

#### Scenario: Acesso pelo código
- **WHEN** a pessoa abre `/departamento/LE`
- **THEN** a página lista os docentes gravados com o código `LE`

#### Scenario: Departamento inexistente
- **WHEN** a pessoa abre `/departamento/XYZ` e nenhum docente tem esse código nem esse nome de departamento
- **THEN** a resposta é 404

### Requirement: Cabeçalho do departamento
A página SHALL mostrar o código, o nome do departamento, a trilha Início › Departamentos › código, o número de docentes listados e o número de registros de produção dos docentes cujo código ou nome de departamento gravado é igual ao do departamento.

#### Scenario: Números do cabeçalho
- **WHEN** a página de um departamento com 12 docentes é aberta
- **THEN** o cabeçalho mostra 12 docentes e o total de produções declaradas nos currículos deles

### Requirement: Corpo docente
A página SHALL listar, em ordem alfabética, todos os docentes do departamento, com vínculo vigente ou encerrado, juntando as grafias equivalentes do código e do nome (por exemplo, `CS` e `DCSo`), cada um com foto ou iniciais, nome, número de produções declaradas e link para o perfil. O cartão MUST mostrar o período do vínculo: "Desde {início}" para quem está ativo e, para os demais, "{início}–{fim}", "Saída" no lugar do fim ausente ou "Histórico" sem início.

#### Scenario: Docente com vínculo encerrado
- **WHEN** um docente do departamento teve vínculo de 1995 a 2018
- **THEN** ele aparece na lista com "1995–2018" em destaque

#### Scenario: Código alternativo
- **WHEN** há docentes gravados como `CS` e outros como `DCSo` e alguém abre `/departamento/CS`
- **THEN** todos aparecem na lista

### Requirement: Panorama do departamento
A página SHALL mostrar "Panorama da produção do departamento" com o panorama (`research-panorama`) do recorte `departamento={código}`, que considera também as grafias equivalentes do código, incluindo a série anual, os tipos, os assuntos em destaque e as pontes entre departamentos e entre grandes áreas.

#### Scenario: Pontes a partir do departamento
- **WHEN** um docente do departamento publica com um docente de outro departamento
- **THEN** a matriz de pontes entre departamentos do panorama mostra o par entre os dois departamentos

### Requirement: Rede de coautoria do departamento
A página SHALL mostrar "Coautorias do departamento", com o grafo interativo (`coauthorship-network`) das 150 ligações mais fortes do recorte do departamento, os 6 pares mais fortes com o total de pares e a janela das publicações de cada par no mesmo recorte.

#### Scenario: Abrir um par
- **WHEN** a pessoa clica num par da lista do departamento
- **THEN** a janela mostra as publicações em comum do par declaradas em currículos do departamento
