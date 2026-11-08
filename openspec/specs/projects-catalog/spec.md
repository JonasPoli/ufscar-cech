# projects-catalog Specification

## Purpose
Catálogo institucional dos projetos de pesquisa, extensão, ensino e desenvolvimento declarados no Currículo Lattes pelos docentes do CECH, com um item por projeto mesmo quando vários docentes o declaram. Cobre a listagem `/projetos` com busca e filtros, a página `/projeto/{id}` com equipe e financiadores, a API JSON somente leitura de projetos e os comandos que recalculam o agrupamento e completam integrantes e financiadores.

## Requirements

### Requirement: Unificação do mesmo projeto declarado por vários docentes
O sistema SHALL tratar como um único projeto (grupo) os registros de projeto com o mesmo nome normalizado e o mesmo ano de início. A normalização MUST ignorar acentos, caixa, pontuação e espaços repetidos e decodificar as entidades HTML do Lattes antes de comparar. Registro sem nome utilizável SHALL formar um grupo sozinho. A chave de agrupamento é uma coluna calculada: o nome bruto vindo do Lattes MUST permanecer como veio.

#### Scenario: Grafias diferentes com o mesmo ano de início
- **WHEN** um docente declara "Educação e Sociedade" com início em 2019 e outro declara "EDUCACAO E SOCIEDADE." com início em 2019
- **THEN** o catálogo mostra um único projeto, "Declarado em 2 currículos"

#### Scenario: Mesmo nome com anos de início diferentes
- **WHEN** dois registros têm o mesmo nome normalizado, mas anos de início 2019 e 2020
- **THEN** o catálogo mostra dois projetos distintos

### Requirement: Registro representante do grupo
O sistema SHALL tirar o título, a situação, a natureza e o período de cada grupo do registro representante: o do docente que se declara responsável pelo projeto; na falta dele, o de descrição mais longa; na falta desta, o registro mais antigo.

#### Scenario: Representante é o responsável
- **WHEN** um grupo tem o registro de um docente marcado como responsável e outro registro com descrição mais longa
- **THEN** título, situação, natureza e período do grupo vêm do registro do responsável

#### Scenario: Nenhum responsável no grupo
- **WHEN** nenhum registro do grupo é de um docente responsável
- **THEN** os dados do grupo vêm do registro com a descrição mais longa

### Requirement: Listagem paginada do catálogo
O sistema SHALL servir em `GET /projetos` um item por grupo, 12 por página, do ano de início mais recente para o mais antigo, com os projetos sem ano de início no fim. O cabeçalho SHALL mostrar o total de projetos, quantos estão em andamento e quantos docentes declaram projetos. Cada cartão SHALL mostrar situação, natureza, período, título com link para a página do projeto, resumo da descrição, os docentes do CECH ligados ao projeto e em quantos currículos ele é declarado.

#### Scenario: Primeira página sem filtros
- **WHEN** um visitante abre `/projetos`
- **THEN** a página lista até 12 projetos, do mais recente para o mais antigo, e informa o número total encontrado

#### Scenario: Docentes do CECH no cartão
- **WHEN** um projeto é declarado por um docente e tem como integrantes outros cinco docentes do CECH ligados pelo ID Lattes
- **THEN** o cartão mostra quatro docentes com link para o perfil, os responsáveis primeiro e marcados com estrela, e "+2" para os demais

#### Scenario: Nenhum resultado
- **WHEN** os filtros não correspondem a nenhum projeto
- **THEN** a página mostra "Nenhum projeto encontrado" e um link para ver todos os projetos

### Requirement: Filtros por nome, situação, natureza e departamento
O sistema SHALL filtrar o catálogo pela query string: `q` (trecho do nome do projeto, até 200 caracteres, com espaços repetidos reduzidos a um), `situacao` (EM_ANDAMENTO, CONCLUIDO, DESATIVADO), `natureza` (PESQUISA, EXTENSAO, ENSINO, DESENVOLVIMENTO, OUTRA) e `departamento` (código de departamento com docentes). Nome, situação e natureza SHALL ser avaliados no registro representante. Valor inválido MUST ser descartado sem erro.

#### Scenario: Filtro por situação e natureza
- **WHEN** o visitante abre `/projetos?situacao=EM_ANDAMENTO&natureza=EXTENSAO`
- **THEN** só aparecem projetos cujo representante está em andamento e é de extensão

#### Scenario: Filtro por departamento via integrante
- **WHEN** o visitante filtra por um departamento cujo docente aparece só como integrante ligado de um projeto declarado por docente de outro departamento
- **THEN** esse projeto aparece no resultado, pois o departamento vale para quem declara e para os integrantes ligados a docentes

### Requirement: Filtro por período de execução
O sistema SHALL aceitar `ano_inicial` e `ano_final` (anos de quatro dígitos entre 1900 e 2100) e selecionar os projetos em execução em algum ano do intervalo: início até o ano final e término a partir do ano inicial. Projeto em andamento sem ano de fim SHALL contar como em execução até o ano atual; os demais sem ano de fim, só no ano de início. Anos em ordem invertida MUST ser trocados.

#### Scenario: Projeto em andamento sem ano de fim
- **WHEN** o visitante filtra `ano_inicial=2015&ano_final=2016` e existe um projeto concluído de 2010 a 2012 e outro em andamento desde 2014 sem ano de fim
- **THEN** só o projeto em andamento desde 2014 aparece

#### Scenario: Anos invertidos
- **WHEN** o visitante abre `/projetos?ano_inicial=2020&ano_final=2010`
- **THEN** o sistema redireciona para `/projetos?ano_inicial=2010&ano_final=2020`

### Requirement: URL canônica da listagem
O sistema SHALL redirecionar `/projetos` para a URL canônica quando a query string recebida for diferente dela: só os filtros válidos e não vazios, na ordem do formulário, e `page` apenas quando maior que 1. `page` que não seja inteiro positivo sem zeros à esquerda MUST contar como a primeira página, e página além da última MUST responder 404. A página SHALL declarar a própria URL canônica, com filtros e página, e usar `noindex, follow` quando houver texto em `q`.

#### Scenario: Parâmetros vazios ou desconhecidos
- **WHEN** o visitante abre `/projetos?q=&situacao=XYZ&foo=1`
- **THEN** o sistema redireciona para `/projetos`

#### Scenario: Página 1 explícita
- **WHEN** o visitante abre `/projetos?page=1`
- **THEN** o sistema redireciona para `/projetos`

#### Scenario: Página inexistente
- **WHEN** o visitante abre uma página maior que o total de páginas do filtro
- **THEN** o sistema responde 404

### Requirement: Página do projeto
O sistema SHALL servir em `GET /projeto/{id}` (id numérico) o projeto do grupo do registro `{id}`, unindo todos os registros do grupo; qualquer registro do grupo SHALL abrir a mesma página, com a URL do representante como canônica. Id inexistente MUST responder 404. A página SHALL mostrar título, situação, natureza, período, descrição, integrantes, responsáveis, financiadores, os docentes que declaram o projeto e um link para o mesmo conteúdo em JSON.

#### Scenario: Registro não representante
- **WHEN** o visitante abre `/projeto/{id}` com o id do registro de um docente que não é o representante do grupo
- **THEN** a página mostra o projeto unificado e declara como canônica a URL `/projeto/{id do representante}`

#### Scenario: Situação divergente entre currículos
- **WHEN** o representante diz "Em andamento" e outro currículo do mesmo grupo diz "Concluído"
- **THEN** a situação aparece como "Em andamento" com a observação "(outros currículos: concluído)"

#### Scenario: Quem declara o projeto
- **WHEN** dois docentes declaram o projeto e um deles se diz responsável em algum registro
- **THEN** "Declarado no Lattes por" lista cada docente uma vez, com o departamento, e o responsável primeiro com o selo "Coordenação"

#### Scenario: Projeto inexistente
- **WHEN** o visitante abre `/projeto/999999999`
- **THEN** o sistema responde 404

### Requirement: Equipe do projeto
O sistema SHALL mostrar como equipe os integrantes do registro representante, sem juntar as listas dos outros currículos, e, quando o representante não informa equipe, os do registro do grupo com a maior equipe. Os responsáveis SHALL vir primeiro. Integrante ligado a um docente do CECH pelo ID Lattes, ou sem ID Lattes e com o nome completo de um docente que declara o projeto, SHALL ter link para o perfil e o selo "Docente do CECH".

#### Scenario: Equipe vinda de outro currículo
- **WHEN** o representante não informa integrantes e outro registro do grupo informa cinco
- **THEN** a página mostra os cinco integrantes e diz "Equipe como informada no Currículo Lattes de" seguido do nome do docente

#### Scenario: Integrante sem ID Lattes com o nome de quem declara
- **WHEN** um integrante sem ID Lattes tem o mesmo nome completo normalizado de um docente que declara o projeto
- **THEN** o integrante aparece com link para o perfil desse docente

#### Scenario: Equipe não informada
- **WHEN** nenhum registro do grupo informa integrantes
- **THEN** a página diz "A equipe não foi informada no Currículo Lattes."

### Requirement: Financiadores do projeto
O sistema SHALL juntar os financiadores de todos os registros do grupo, um por instituição (comparando o nome normalizado), somando as naturezas do apoio e omitindo a natureza não informada. Quando nenhum registro tem financiadores detalhados, SHALL usar o financiador simples informado em cada registro.

#### Scenario: Mesmo financiador em dois currículos
- **WHEN** um currículo informa "FAPESP" com natureza bolsa e outro informa "Fapesp" com natureza auxílio financeiro
- **THEN** a página mostra um único financiador com as duas naturezas, bolsa e auxílio financeiro

#### Scenario: Sem financiadores
- **WHEN** nenhum registro do grupo informa financiador
- **THEN** a página mostra "Não informados"

### Requirement: Rótulos e textos de exibição
O sistema SHALL exibir situação, natureza e natureza do financiamento com rótulos em português e, para código desconhecido, o próprio código em minúsculas com a inicial maiúscula e espaços no lugar de sublinhados. O período SHALL seguir os formatos "2019 – atual", "2015 – 2018", "2020", "Até 2018" e "Ano não informado". Os textos SHALL sair com as entidades HTML do Lattes decodificadas, sem alterar o dado gravado.

#### Scenario: Projeto em andamento sem ano de fim
- **WHEN** um projeto em andamento começou em 2019 e não tem ano de fim
- **THEN** o período aparece como "2019 – atual"

#### Scenario: Projeto concluído sem ano de fim
- **WHEN** um projeto concluído começou em 2020 e não tem ano de fim
- **THEN** o período aparece como "2020"

#### Scenario: Código desconhecido
- **WHEN** a natureza gravada é "PROJETO_ESPECIAL"
- **THEN** o rótulo exibido é "Projeto especial"

### Requirement: API JSON de projetos
O sistema SHALL servir, sem autenticação, `GET /api/v1/projetos` (mesmos filtros e paginação da listagem, com `count`, `page`, `pages`, `per_page`, `filters` e `results`) e `GET /api/v1/projetos/{id}` (o projeto unificado, com descrição, financiadores, integrantes e quem declara), em JSON UTF-8 sem escape e com `Cache-Control: public, max-age=3600`. Filtro inválido MUST ser ignorado sem redirecionamento; página além da última e id inexistente MUST responder 404 com mensagem de erro em JSON.

#### Scenario: Lista filtrada em JSON
- **WHEN** um sistema externo chama `/api/v1/projetos?situacao=CONCLUIDO&foo=1`
- **THEN** recebe JSON com `filters` igual a `{"situacao": "CONCLUIDO"}` e só projetos concluídos em `results`, cada um com as URLs da página e da API e os docentes ligados

#### Scenario: Integrantes no detalhe
- **WHEN** um sistema externo chama `/api/v1/projetos/{id}`
- **THEN** cada integrante vem com o papel `responsavel` ou `participante` e, quando ligado, o docente com a URL do perfil

#### Scenario: Página além da última
- **WHEN** a chamada pede `page=9` e o filtro tem uma página
- **THEN** a resposta é 404 com `error` e o total de `pages`

### Requirement: Recalcular agrupamento e vínculos dos integrantes
O sistema SHALL oferecer o comando `app:index:projects`, que recalcula a chave de agrupamento de cada registro de projeto e liga cada integrante ao docente com o mesmo ID Lattes, desligando quando não há mais correspondência. O comando SHALL gravar só onde o valor muda e MUST NOT alterar dados brutos do Lattes. Com `--researcher=<ID Lattes>`, SHALL se limitar aos projetos desse docente e aos integrantes com o ID dele.

#### Scenario: Execução repetida
- **WHEN** o administrador roda `app:index:projects` duas vezes seguidas sem mudanças nos dados
- **THEN** a segunda execução informa zero chaves alteradas e zero integrantes religados, além das estatísticas do catálogo

#### Scenario: Docente inexistente
- **WHEN** o administrador roda `app:index:projects --researcher=0000000000000000`
- **THEN** o comando termina com falha informando que o docente não foi encontrado

### Requirement: Completar integrantes e financiadores a partir dos XML
O sistema SHALL oferecer o comando `app:projects:backfill-members` (`--dir`, `--file`, `--dry-run`, `--ignore-date`), que relê os XML do Lattes e substitui só os integrantes e financiadores dos projetos já importados, sem reimportar o currículo. Cada projeto do XML SHALL ser casado com um registro do docente pelo nome gravado e ano de início (depois, pelo nome normalizado); projeto sem par MUST NOT ser criado nem alterado. Ao gravar, o comando SHALL rodar a indexação dos projetos.

#### Scenario: Simulação
- **WHEN** o administrador roda `app:projects:backfill-members --dir=<pasta> --dry-run`
- **THEN** o comando mostra quantos integrantes e financiadores seriam gravados e não altera o banco

#### Scenario: XML de outra data
- **WHEN** o XML de um docente tem data de atualização diferente da registrada no banco e o comando roda sem `--ignore-date`
- **THEN** o arquivo é pulado e listado nos avisos

#### Scenario: Sem pasta nem arquivo
- **WHEN** o comando é executado sem `--dir` nem `--file` existentes
- **THEN** ele termina com falha pedindo a pasta ou o arquivo

### Requirement: Projetos sem título
Um projeto sem título (gravado como "Projeto sem título" pela importação por HTML) MUST NOT ser agrupado com outros: cada registro sem título é um item próprio do catálogo.

#### Scenario: Dois docentes com projeto sem título no mesmo ano
- **WHEN** dois docentes têm, cada um, um projeto sem título iniciado em 2019
- **THEN** o catálogo mostra dois itens, cada um com a equipe e os financiadores do seu registro
