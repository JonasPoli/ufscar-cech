# research-thesauri Specification

## Purpose
Reunir sob um nome preferido as muitas grafias de autores, instituições e países que aparecem nos currículos, para reconhecer coautores que são docentes do portal, ligar formações e atuações a instituições e exibir países com nome e bandeira, com curadoria, fusão e intercâmbio de arquivos pelo painel.

## Requirements
### Requirement: Forma normalizada dos nomes
O sistema SHALL comparar nomes de autores, instituições e países pela forma normalizada: sem acentos e outras marcas diacríticas, com pontuação e símbolos trocados por espaço, espaços repetidos reduzidos a um, sem espaços nas pontas e toda em maiúsculas ou toda em minúsculas; o slug é a forma minúscula com hífens no lugar do que não é letra ou número.

#### Scenario: Nome com acento e pontuação
- **WHEN** o nome é `Universidade Federal de São Carlos` ou `SILVA, J. A.`
- **THEN** as formas normalizadas são `UNIVERSIDADE FEDERAL DE SAO CARLOS` e `SILVA J A`

#### Scenario: Slug
- **WHEN** o texto é `João da Silva Santos`
- **THEN** o slug é `joao-da-silva-santos`

### Requirement: Identidade e variantes do docente no tesauro de autores
A cada importação de currículo, o sistema SHALL garantir para o docente uma identidade no tesauro de autores, localizada pelo nome completo normalizado (ou pelo nome preferido igual ao nome completo) e criada se não existir, com o nome completo como variante de origem `lattes`, cada nome de citação (separados por ponto e vírgula) como variante de origem `citation` e o ORCID como identificador externo, sem duplicar o que a identidade já tem.

#### Scenario: Nome de citação novo
- **WHEN** o docente passa a declarar no Lattes o nome de citação `SOUZA, M. A.`
- **THEN** a variante `SOUZA, M. A.` é acrescentada à sua identidade e o índice de reconhecimento de coautores é renovado

#### Scenario: Identidade com ORCID
- **WHEN** a identidade já tem um ORCID
- **THEN** nenhum outro ORCID é acrescentado

### Requirement: Sincronização completa do tesauro de autores
O sistema SHALL oferecer o comando `app:thesaurus:sync-authors`, que, sem apagar nada, acrescenta ao tesauro as identidades e variantes dos docentes (nome completo, nomes de citação e ORCID) e cria identidades para os coautores das produções que ainda não correspondem a nenhuma identidade ou variante, inclusive pela forma invertida do nome; `--researchers-only` limita a sincronização aos docentes e `--clean` apaga todo o tesauro de autores antes, mediante confirmação.

#### Scenario: Coautor em formato ABNT
- **WHEN** um coautor sem identidade aparece como `GREGOLIN, José Angelo Rodrigues`
- **THEN** a identidade criada tem o nome preferido `José Angelo Rodrigues GREGOLIN`, e o nome de citação vira variante

#### Scenario: Limpeza sem confirmação
- **WHEN** o comando roda com `--clean --no-interaction`
- **THEN** nada é apagado nem sincronizado e o comando termina com código de falha

#### Scenario: Limpeza confirmada
- **WHEN** `--clean` é confirmado
- **THEN** identidades, variantes e identificadores externos são apagados, inclusive fusões e correções manuais, as produções perdem a ligação com as identidades e o comando recomenda rodar `app:curriculum:normalize`

### Requirement: Reconhecimento de autores
O sistema SHALL reconhecer um nome de autor primeiro contra os docentes do portal (nome completo e nomes de citação, nas formas normalizada, sem pontuação e invertida entre `Sobrenome, Prenome` e `Prenome Sobrenome`) e, se não houver docente, contra as variantes ativas de identidades ativas do tesauro e depois contra os nomes normalizados das identidades. O índice de docentes SHALL ficar em cache por até 30 dias.

#### Scenario: Renovação do índice de docentes
- **WHEN** um currículo XML é importado, o tesauro ganha variantes de um docente, um docente é excluído ou rodam os comandos de sincronização do tesauro ou de normalização com `--clear-cache`
- **THEN** o índice de docentes é renovado
- **AND** na exclusão de docente pelo painel o cache de páginas públicas também é limpo, e as importações seguintes não tentam ligar coautores ao docente excluído

#### Scenario: Forma invertida
- **WHEN** o coautor está escrito `Maria Aparecida Souza` e o docente declara `SOUZA, Maria Aparecida`
- **THEN** o coautor é reconhecido como esse docente

#### Scenario: Exibição de autores
- **WHEN** uma página lista os autores de uma produção
- **THEN** o autor ligado a um docente aparece com link para `/professor/<slug>`, o docente dono do currículo aparece destacado e o autor de uma identidade do tesauro mostra o nome preferido como dica

### Requirement: Curadoria do tesauro de autores
O sistema SHALL oferecer a administradores, em `/admin/authors`, a lista das identidades (as primeiras 500 em ordem de nome preferido), o cadastro de identidade, a edição do nome preferido com o acréscimo de uma variante de origem `manual` e a exclusão de identidade com token CSRF.

#### Scenario: Variante acrescentada à mão
- **WHEN** o administrador edita uma identidade e informa uma nova variante
- **THEN** a variante é gravada com origem `manual` e a sua forma normalizada

#### Scenario: Exclusão de identidade
- **WHEN** uma identidade é excluída
- **THEN** as suas variantes e identificadores somem e as produções ligadas a ela ficam sem identidade

### Requirement: Reconhecimento de instituições
O sistema SHALL reconhecer o nome de uma instituição pela forma normalizada do nome oficial, do nome curto, da sigla ou de uma variante ativa de instituição ativa; sem correspondência exata, SHALL tentar cada parte separada por hífen e, para nomes com mais de 4 caracteres, a primeira chave que começa pelo nome ou pela qual o nome começa. O índice de instituições SHALL ficar em cache por até 30 dias.

#### Scenario: Sigla e nome juntos
- **WHEN** a instituição vem como `UFSCar - Universidade Federal de São Carlos`
- **THEN** ela é reconhecida pela parte que corresponde à sigla ou ao nome oficial

#### Scenario: Selo de instituição
- **WHEN** uma página exibe uma instituição reconhecida
- **THEN** ela aparece como `SIGLA - Nome oficial` (se o nome não contém a sigla), com a bandeira do país da instituição

### Requirement: Curadoria do tesauro de instituições
O sistema SHALL oferecer a administradores, em `/admin/institutions`, a lista (as primeiras 500 em ordem de nome oficial), o cadastro e a edição de nome oficial, nome curto, sigla, tipo e natureza jurídica, o acréscimo de uma variante na edição e a exclusão com token CSRF.

#### Scenario: Exclusão de instituição
- **WHEN** uma instituição é excluída
- **THEN** as formações e atuações ligadas a ela ficam sem instituição

### Requirement: Reconhecimento de países
O sistema SHALL reconhecer um país pelo código ISO de duas ou três letras, pelo nome comum, pelo nome oficial, por uma variante ativa de país ativo ou por uma lista embutida de grafias usuais (como `Brasil`, `Brazil`, `EUA`, `USA`, `Inglaterra`) e, sem correspondência, pela primeira chave com mais de 3 caracteres contida no nome ou que o contém; o resultado serve à exibição (bandeira e nome em português) e não é gravado nos currículos.

#### Scenario: Grafia em inglês
- **WHEN** o país de nascimento vem como `Brazil`
- **THEN** a página exibe a bandeira do Brasil e o nome `Brasil`

#### Scenario: País desconhecido
- **WHEN** o texto não corresponde a nenhum país
- **THEN** a página exibe o texto original com o ícone de globo

### Requirement: Curadoria do tesauro de países
O sistema SHALL oferecer a administradores, em `/admin/countries`, o cadastro e a edição de nome comum, nome oficial, códigos ISO, anos de fundação e extinção e situação, a substituição de todas as variantes pela lista digitada (uma por linha), a exclusão de variante e o desmembramento de uma variante em um país novo, essas duas com token CSRF.

#### Scenario: Lista de variantes editada
- **WHEN** o administrador salva a lista de variantes sem uma grafia que existia e com uma nova
- **THEN** a grafia retirada é apagada e a nova é criada

#### Scenario: Desmembrar variante
- **WHEN** o administrador desmembra a variante `Alemanha Ocidental`
- **THEN** é criado o país `Alemanha Ocidental`, com ela como variante, e a variante sai do país de origem

### Requirement: Países históricos
O sistema SHALL oferecer o comando `app:geography:seed-historical-countries`, que cria ou atualiza uma lista fixa de países extintos e recentes (como União Soviética, Iugoslávia, Tchecoslováquia, Alemanha Oriental, Sudão do Sul e Kosovo), localizados pelo ISO de três letras, pelo de duas ou pelo nome comum, gravando nomes, códigos, anos de fundação e extinção, situação ativa e as variantes que faltam.

#### Scenario: País já cadastrado
- **WHEN** a União Soviética já existe com o código `SUN`
- **THEN** o registro é atualizado com os nomes, códigos e anos da lista, e só as variantes ausentes são acrescentadas

### Requirement: Fusão de registros duplicados
O sistema SHALL permitir a administradores fundir duas ou mais identidades de autor, instituições ou países num registro principal: os nomes e as variantes dos registros secundários viram variantes do principal (sem repetir formas normalizadas já existentes), os identificadores externos dos autores são somados ao principal e os registros secundários são excluídos.

#### Scenario: Fusão de instituições
- **WHEN** o administrador funde `Univ. Fed. de São Carlos` em `Universidade Federal de São Carlos`
- **THEN** `Univ. Fed. de São Carlos` passa a ser variante da principal e o registro secundário deixa de existir
- **AND** a tela confirma a fusão com o nome do registro principal

#### Scenario: Registro principal inexistente
- **WHEN** o registro principal informado não existe
- **THEN** nada é alterado e a tela mostra o erro da fusão

#### Scenario: Ligações do registro secundário
- **WHEN** produções, formações ou atuações estavam ligadas a um registro secundário
- **THEN** depois da fusão elas ficam sem ligação, porque a fusão não as transfere para o registro principal

### Requirement: Importação e exportação de tesauros
O sistema SHALL exportar os tesauros de autores, instituições e países (`/admin/<authors|institutions|countries>/export/{formato}`) nos formatos VantagePoint `.the` (padrão, termos em minúsculas), CSV (`preferred_name`,`variant_name`, uma linha por variante, UTF-8 com BOM), JSON e XML, e SHALL importar arquivos nesses formatos (detectando UTF-16 e ISO-8859-1), acrescentando o termo preferido que não existe e as suas variantes.

#### Scenario: Arquivo VantagePoint
- **WHEN** o arquivo traz `**#brasil` seguido de linhas `100 1 ^brazil$`
- **THEN** o termo preferido é `brasil` e `brazil` é uma das suas variantes

#### Scenario: CSV com variantes agrupadas
- **WHEN** o CSV tem o cabeçalho `preferred_name;variant_name` e a linha `Brasil;Brazil|BRA`
- **THEN** `Brazil` e `BRA` são importadas como variantes de `Brasil`

#### Scenario: Reimportação do mesmo arquivo
- **WHEN** o mesmo arquivo é importado duas vezes
- **THEN** autores e instituições não ganham variantes repetidas, enquanto cada variante de país é gravada de novo a cada importação
