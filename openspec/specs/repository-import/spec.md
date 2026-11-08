# repository-import Specification

## Purpose
Completar as orientações de mestrado e doutorado dos docentes com as teses e dissertações do Repositório Institucional da UFSCar (arquivo `TeD-UFSCar.csv`), enriquecendo as orientações que já vieram do Lattes e cadastrando as que só existem no Repositório, sem duplicar registros a cada execução.

## Requirements

### Requirement: Comando de importação do Repositório
O sistema SHALL oferecer o comando `app:import:repository` (alias `app:import:ted`), que lê o CSV do Repositório (`--file`, padrão `docs/banco/TeD-UFSCar.csv`) e aceita filtro por centro (`--center`), restrição a um docente (`--researcher`, por ID Lattes de 16 dígitos, ID interno ou slug), limite de linhas (`--limit`) e simulação (`--dry-run`), terminando com uma tabela de totais.

#### Scenario: Tabela de totais
- **WHEN** a importação termina
- **THEN** a tabela mostra linhas do CSV, linhas processadas, linhas com orientador e com coorientador reconhecidos, linhas sem docente, orientações enriquecidas, novas e já vinculadas, o tempo e o pico de memória

#### Scenario: Entrada inválida
- **WHEN** `--researcher` não corresponde a nenhum docente, o CSV não existe ou o cabeçalho do CSV tem menos de 10 colunas
- **THEN** o comando termina com erro (no primeiro caso, `Docente não encontrado`) sem gravar nada

#### Scenario: Filtro por centro
- **WHEN** o comando roda com `--center=CECH`
- **THEN** são puladas as linhas cujo centro preenchido não contém `CECH` (sem diferença de caixa); as linhas sem centro continuam sendo processadas

### Requirement: Leitura das linhas do CSV
O sistema SHALL ler de cada linha (separada por vírgula, com pelo menos 5 colunas) o tipo, o título, o título alternativo, o autor, os orientadores e coorientadores (nomes, IDs Lattes e ORCIDs), o programa, o centro, o campus, a data de defesa, o resumo, os assuntos, o DOI, a URL persistente, o Handle e o UUID do item, e SHALL deduzir o tipo da orientação e o ano.

#### Scenario: Tese e dissertação
- **WHEN** o tipo contém `Tese` (sem diferença de caixa)
- **THEN** a orientação é de `DOUTORADO`; qualquer outro tipo vira `MESTRADO`

#### Scenario: Data de defesa
- **WHEN** a data começa por `AAAA-MM-DD`
- **THEN** a data de defesa é gravada e o ano vem dela; se só os quatro primeiros caracteres forem um ano, apenas o ano é usado

#### Scenario: Link do Repositório
- **WHEN** a linha não tem URL persistente mas tem Handle
- **THEN** o link passa a ser `https://repositorio.ufscar.br/handle/<Handle>`

### Requirement: Reconhecimento de orientadores e coorientadores
O sistema SHALL reconhecer como orientadores e coorientadores os docentes do portal cujo ID Lattes de 16 dígitos ou ORCID aparece nas colunas correspondentes e, só quando nenhum é reconhecido assim, os docentes cujo nome completo normalizado coincide com um dos nomes separados por ponto e vírgula; linhas sem nenhum docente reconhecido são contadas como não vinculadas e ignoradas.

#### Scenario: Reconhecimento pelo ID Lattes
- **WHEN** a coluna de Lattes dos orientadores contém uma URL com o ID de 16 dígitos de um docente
- **THEN** a obra é processada para esse docente como orientador

#### Scenario: Orientador que também é coorientador
- **WHEN** o mesmo docente aparece como orientador e como coorientador
- **THEN** a obra é processada uma vez só, como orientação

#### Scenario: Docente de outro centro
- **WHEN** nenhum orientador ou coorientador da linha é docente do portal
- **THEN** a linha é ignorada e contada como não vinculada

### Requirement: Idempotência por Handle e UUID
O sistema SHALL tratar como já vinculada a obra cujo Handle ou UUID já está numa orientação de mestrado ou doutorado do docente, sem criar outra; nesse caso apenas os campos vazios da orientação são preenchidos com os dados do Repositório, e a orientação é marcada como concluída.

#### Scenario: Reexecução da importação
- **WHEN** a importação roda de novo com o mesmo CSV
- **THEN** nenhuma orientação é duplicada e as obras já importadas são contadas como já vinculadas

### Requirement: Enriquecimento de orientação existente
Quando o docente já tem uma orientação do mesmo tipo (mestrado ou doutorado) que corresponde à obra pelo orientando ou pelo título, o sistema SHALL enriquecer essa orientação em vez de criar outra, preenchendo apenas os campos vazios do Repositório (link, Handle, UUID, data de defesa, programa, centro, campus, resumo, assuntos, DOI, ORCID do orientando, título alternativo). Situação e curso vindos do Lattes MUST ficar intactos.

#### Scenario: Critério de correspondência
- **WHEN** há mais de uma orientação candidata do mesmo tipo
- **THEN** vale a mais forte: orientando com o mesmo nome normalizado ou título idêntico; depois um título contido no outro (título da obra com mais de 15 caracteres); por último um nome contido no outro, com palavras inteiras e pelo menos dois nomes no menor
- **AND** uma orientação que já tem outro Handle ou UUID do Repositório nunca é usada

#### Scenario: Orientação do Lattes encontrada pelo orientando
- **WHEN** o Lattes do docente tem a dissertação de `Maria Silva` e o Repositório traz a dissertação de `Maria Silva`, com resumo e link
- **THEN** a orientação existente ganha o link, o resumo e os demais dados vazios, e é contada como enriquecida
- **AND** o link que a orientação já tinha não é trocado pelo do Repositório

#### Scenario: Orientação em andamento já defendida
- **WHEN** a orientação encontrada está em andamento no Lattes
- **THEN** a situação gravada continua "em andamento", mas a orientação passa a contar como concluída nas páginas e nos indicadores, porque tem o Handle do Repositório

#### Scenario: Programa do Repositório
- **WHEN** a orientação do Lattes é enriquecida com o programa "PPGE"
- **THEN** o curso do Lattes não muda, o programa fica guardado à parte e é exibido quando o Lattes não informa o curso

#### Scenario: Nome parecido de outro aluno
- **WHEN** o Lattes tem a dissertação de `Maria Silva` e o CSV traz, antes dela, a de `Maria Silva Santos`, com outro título
- **THEN** a dissertação de `Maria Silva` enriquece o registro do Lattes e a de `Maria Silva Santos` vira uma orientação nova

### Requirement: Cadastro de orientação vinda só do Repositório
Quando a obra não corresponde a nenhuma orientação do docente, o sistema SHALL criar uma orientação concluída, com origem `repository_ufscar`, instituição `Universidade Federal de São Carlos`, marcada como coorientação quando o docente é coorientador, e com os dados da obra (orientando, título, título alternativo, ano, data de defesa, programa como curso, centro, campus, link, Handle, UUID, resumo, assuntos, DOI e ORCID do orientando).

#### Scenario: Obra ausente do Lattes
- **WHEN** a tese orientada pelo docente não está no seu currículo Lattes
- **THEN** uma orientação de doutorado concluída é criada com origem `repository_ufscar`
- **AND** ela sobrevive às reimportações do currículo Lattes

#### Scenario: Coorientação
- **WHEN** o docente aparece só como coorientador da obra
- **THEN** a orientação criada é marcada como coorientação

### Requirement: Importação restrita a um docente
O sistema SHALL permitir processar o CSV só para um docente, pelo comando (`--researcher`) ou pelo botão da tela do docente no painel (`POST /admin/curriculum/{id}/import-repository`, com token CSRF), considerando apenas as obras em que ele é orientador ou coorientador.

#### Scenario: Botão do painel com obras encontradas
- **WHEN** o administrador aciona a importação do Repositório na tela do docente
- **THEN** a tela informa quantos trabalhos foram encontrados e quantos foram enriquecidos, cadastrados e já vinculados

#### Scenario: Nenhuma obra ou CSV ausente
- **WHEN** nenhuma obra do docente é encontrada, ou `docs/banco/TeD-UFSCar.csv` não existe no servidor
- **THEN** a tela mostra um aviso ou erro e nada é gravado

#### Scenario: Token inválido
- **WHEN** o token CSRF do formulário é inválido
- **THEN** a tela pede para recarregar a página e nada é importado

### Requirement: Simulação
O sistema SHALL, com `--dry-run`, ler todo o CSV e calcular os mesmos totais sem gravar nenhuma alteração no banco.

#### Scenario: Simulação concluída
- **WHEN** o comando roda com `--dry-run`
- **THEN** a tabela de totais é exibida, com o aviso de que nada foi gravado
