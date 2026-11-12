# lattes-import Specification

## Purpose
Trazer para o portal os currículos Lattes dos docentes do CECH, a partir do XML exportado do CNPq, do HTML da página pública do Lattes ou da planilha institucional, mantendo o dado do Lattes como veio e substituindo por inteiro o currículo de cada docente a cada nova importação.

## Requirements

### Requirement: Importação de XML pela linha de comando
O sistema SHALL oferecer o comando `app:import:lattes`, que importa os arquivos `*.xml` de uma pasta (`--dir`, padrão `docs/banco/CECH`, incluindo subpastas, em ordem de nome) ou um único arquivo (`--file`), processa cada arquivo de forma independente e termina com código de falha quando algum arquivo não foi importado.

#### Scenario: Lote com um arquivo inválido
- **WHEN** a pasta contém um arquivo que não é XML do Lattes (raiz diferente de `CURRICULO-VITAE`) entre arquivos válidos
- **THEN** os arquivos válidos são importados
- **AND** o comando lista até 10 erros, cada um com o nome do arquivo e a mensagem (por exemplo, `Invalid XML structure for Lattes CV`), e termina com código diferente de zero

#### Scenario: Caminho inexistente
- **WHEN** `--dir` ou `--file` aponta para um caminho que não existe
- **THEN** o comando termina com erro sem importar nada

#### Scenario: Pasta sem XML
- **WHEN** a pasta não contém nenhum arquivo `*.xml`
- **THEN** o comando avisa que não há arquivo para importar e termina com sucesso

### Requirement: Identificação do docente pelo ID Lattes
O sistema SHALL identificar o docente de cada currículo pelo ID Lattes de exatamente 16 dígitos, tomado, nesta ordem, do atributo `NUMERO-IDENTIFICADOR` do XML, do ID informado por quem importa e dos 16 dígitos do nome do arquivo; o docente com esse ID é atualizado e, se não existir, é criado.

#### Scenario: XML sem NUMERO-IDENTIFICADOR
- **WHEN** o arquivo `lattes1234567890123456.xml` não traz o atributo `NUMERO-IDENTIFICADOR`
- **THEN** o currículo é gravado no docente de ID Lattes `1234567890123456`

#### Scenario: Nenhuma fonte com 16 dígitos
- **WHEN** nem o XML, nem o ID informado, nem o nome do arquivo têm 16 dígitos (por exemplo, um upload temporário chamado `/tmp/phpw3Hq9N`)
- **THEN** a importação daquele arquivo falha com a mensagem de que o ID Lattes não pôde ser determinado
- **AND** nenhum docente é criado

#### Scenario: Docente já cadastrado
- **WHEN** o ID Lattes do XML já pertence a um docente
- **THEN** o currículo desse docente é atualizado, sem criar outro registro

### Requirement: Recusa de XML mais antigo que o currículo gravado
O sistema SHALL pular, sem alterar nada, o XML cuja `DATA-ATUALIZACAO` (formato DDMMAAAA) seja anterior à data de última atualização do Lattes já gravada para o docente, salvo quando quem importa pede explicitamente (`--force` no comando; opção "Importar mesmo com data antiga" nas telas).

#### Scenario: XML antigo sem forçar
- **WHEN** o XML tem `DATA-ATUALIZACAO="01012020"` e o docente está gravado com atualização em 10/05/2024
- **THEN** o arquivo é pulado
- **AND** o comando lista até 10 arquivos pulados e sugere `--force`; a tela de um docente avisa com a data gravada e a tela de lote lista até 5 arquivos

#### Scenario: Mesma data ou data ausente
- **WHEN** a data do XML é igual à gravada, ou um dos dois lados não tem data
- **THEN** o arquivo é importado normalmente

#### Scenario: Importação forçada
- **WHEN** o XML antigo é importado com `--force` ou com a opção marcada na tela
- **THEN** o currículo é substituído e a data de última atualização passa a ser a do XML

### Requirement: Substituição completa do currículo
A cada importação o sistema SHALL apagar e regravar, a partir do arquivo, a produção (com seus autores), as formações, as orientações de origem Lattes, os prêmios, as áreas de atuação, as atuações profissionais, os projetos, as bancas, as participações em eventos e os idiomas do docente, numa única transação para esse conjunto de itens.

#### Scenario: Orientações do Repositório preservadas
- **WHEN** o docente tem orientações com origem `repository_ufscar` e o seu XML é reimportado
- **THEN** essas orientações continuam no banco
- **AND** só as orientações de origem `lattes` são apagadas e regravadas

#### Scenario: Item retirado do Lattes
- **WHEN** uma produção que estava no banco não existe mais no XML importado
- **THEN** ela deixa de existir no portal

#### Scenario: Falha ao gravar os itens
- **WHEN** a gravação dos itens falha no meio do arquivo
- **THEN** os itens anteriores do docente permanecem como estavam
- **AND** os dados gerais do docente (nome, slug, nomes de citação, data de atualização, endereço) e as variantes de nome no tesauro de autores já ficam gravados com os valores do novo XML

### Requirement: Preservação dos dados brutos do Lattes
O sistema MUST guardar os valores vindos do Lattes como vieram no arquivo, alterando-os apenas para tirar os espaços das pontas e cortar o que excede o tamanho da coluna (no XML, o ORCID também perde o prefixo `https://orcid.org/`). Normalização, enriquecimento e resolução MUST ser gravados em colunas próprias, sem sobrescrever as colunas que recebem o dado do Lattes.

#### Scenario: Entidades HTML do Lattes
- **WHEN** o XML traz um título como `Educa&ccedil;&atilde;o`
- **THEN** a coluna do título guarda exatamente `Educa&ccedil;&atilde;o`
- **AND** as páginas exibem `Educação`, porque o texto é decodificado só na exibição

#### Scenario: Resultado da normalização em colunas próprias
- **WHEN** a normalização liga artigos a periódicos, coautores a docentes ou ao tesauro e formações a instituições
- **THEN** `journal_name`, `issn`, `title`, `author_name`, `citation_name` e `institution_name` continuam como vieram
- **AND** o resultado fica em `journal_name_normalized`, `qualis_journal_id`, `qualis`, `indexed_databases`, `author_identity_id`, `matched_researcher_id`, `is_cech_researcher`, `is_indexed` e `institution_id`

#### Scenario: Valor maior que a coluna
- **WHEN** um valor excede o tamanho da coluna de destino (por exemplo, um nome de periódico com mais de 500 caracteres)
- **THEN** ele é gravado cortado no limite da coluna, sem outra alteração

### Requirement: Dados gerais do docente
O sistema SHALL preencher, a partir de `DADOS-GERAIS`, o nome completo, o slug, os nomes em citações bibliográficas, a nacionalidade e o local de nascimento, o ORCID, o resumo do currículo e o endereço profissional (instituição, unidade, cidade, UF, país, CEP, telefone ou ramal), e SHALL gravar `DATA-ATUALIZACAO` como data da última atualização do Lattes. Departamento e e-mail não vêm do XML.

#### Scenario: Slug já usado por outro docente
- **WHEN** o slug gerado do nome completo já pertence a outro docente
- **THEN** o slug do docente importado recebe o sufixo `-<ID Lattes>`

#### Scenario: E-mail ausente do XML
- **WHEN** o endereço profissional do XML não tem o atributo `E-MAIL` (caso dos currículos atuais, que trazem o endereço em outro atributo)
- **THEN** o e-mail do docente não é preenchido nem alterado

### Requirement: Produção e coautores
O sistema SHALL gravar cada produção do XML com um tipo (`item_type`) definido pelo bloco de origem, com seus coautores (nome completo, nome para citação, ID Lattes e ordem de autoria) e com até seis palavras-chave.

#### Scenario: Tipos de produção
- **WHEN** o XML traz produções bibliográficas, técnicas e artísticas
- **THEN** os tipos gravados são `ARTIGO`, `LIVRO`, `CAPITULO`, `EVENTO`, `TEXTO_JORNAL`, `SOFTWARE`, `PATENTE`, `MARCA`, `TRABALHO_TECNICO` (trabalho técnico, produto, processo, desenho industrial), `OUTRA_TECNICA` (cada filho de `DEMAIS-TIPOS-DE-PRODUCAO-TECNICA`, com a tag na natureza), `PRODUCAO_ARTISTICA` e `OUTRA` (demais produções bibliográficas, demais trabalhos e artigos aceitos)
- **AND** o artigo aceito para publicação fica como `OUTRA`, com natureza `ARTIGO-ACEITO-PARA-PUBLICACAO`, e não conta como artigo

#### Scenario: Artigo publicado
- **WHEN** o XML traz um `ARTIGO-PUBLICADO` com periódico, ISSN, volume, fascículo, página inicial e final
- **THEN** a produção é gravada como `ARTIGO` com `journal_name`, `issn`, `volume`, `issue` e `pages` no formato `inicial-final` (ou só o lado que existir)

#### Scenario: Item sem título
- **WHEN** um item técnico, artístico, de demais produções, de demais trabalhos ou um artigo aceito não tem título
- **THEN** o item não é gravado

### Requirement: Formação, atuação, orientações, bancas, eventos e projetos
O sistema SHALL importar do XML as formações acadêmicas e complementares, as atuações profissionais com seus vínculos, as áreas de atuação, os prêmios, os idiomas, as orientações concluídas e em andamento, as bancas, as participações em eventos e os projetos de pesquisa com integrantes e financiadores.

#### Scenario: Tipo e situação da orientação
- **WHEN** o XML traz uma orientação concluída ou em andamento
- **THEN** o tipo (`MESTRADO`, `DOUTORADO`, `POS_DOUTORADO`, `INICIACAO_CIENTIFICA`, `TCC_GRADUACAO`, `ESPECIALIZACAO` ou `OUTRA`) vem da tag ou, na falta, do atributo `NATUREZA`, a situação é `CONCLUIDA` ou `EM_ANDAMENTO` e a origem é `lattes`
- **AND** a orientação sem nome do orientando não é gravada

#### Scenario: Vínculo profissional atual
- **WHEN** um vínculo não tem ano de fim, ou termina (mês padrão 12) no mês corrente ou depois
- **THEN** ele é marcado como atual
- **AND** o enquadramento ou tipo de vínculo `LIVRE` é trocado pelo campo padrão do Lattes ou fica vazio

#### Scenario: Coordenação e integrantes de projeto
- **WHEN** um projeto lista como integrante o próprio docente com `FLAG-RESPONSAVEL="SIM"`
- **THEN** o projeto é marcado como coordenado pelo docente
- **AND** cada integrante com o ID Lattes de um docente do portal é ligado a ele, inclusive os integrantes de projetos de outros currículos que têm o ID do docente importado
- **AND** o financiador sem nome e sem código não é gravado

### Requirement: Departamento informado na importação
Como o XML não traz o departamento, o sistema SHALL aplicar aos docentes importados o departamento informado no formato `CODIGO|Nome` (`--dept` no comando; campo nas telas) e SHALL manter o departamento atual do docente quando nada é informado.

#### Scenario: Formato inválido no comando
- **WHEN** `--dept` não tem a barra vertical ou tem código ou nome vazio
- **THEN** o comando termina com o erro `Use --dept="CODIGO|Nome do departamento".` sem importar nada

#### Scenario: Só o código na tela
- **WHEN** a tela recebe apenas `ED`
- **THEN** o código e o nome do departamento ficam `ED`

#### Scenario: Departamento não informado
- **WHEN** nenhum departamento é informado
- **THEN** o docente existente mantém o seu departamento e o docente novo fica sem departamento

### Requirement: Importação pelo painel administrativo
O sistema SHALL permitir que administradores (`ROLE_ADMIN`) importem um docente em `/admin/curriculum/new` (ID Lattes ou URL, departamento, arquivo XML ou HTML colado, busca opcional da foto oficial) e vários XML de uma vez em `/admin/curriculum/import` (departamento opcional), sem que um arquivo com erro impeça os seguintes.

#### Scenario: Cadastro sem arquivo enviado
- **WHEN** nenhum XML é enviado em `/admin/curriculum/new`
- **THEN** o sistema usa `docs/banco/CECH/lattes<ID>.xml` ou `docs/banco/CECH/<ID>.xml`, se existir
- **AND** sem XML nem HTML, o docente é mantido ou criado com o nome `Pesquisador Lattes <ID>` e recebe o departamento informado

#### Scenario: ID Lattes inválido
- **WHEN** o campo de ID não contém 16 dígitos
- **THEN** a tela mostra o erro de que o ID deve ter exatamente 16 dígitos e nada é gravado

#### Scenario: Lote com erro
- **WHEN** vários XML são enviados em `/admin/curriculum/import` e um deles falha
- **THEN** os demais são importados e a tela informa quantos foram importados, até 5 arquivos pulados e até 5 erros
- **AND** o XML sem `NUMERO-IDENTIFICADOR` usa os 16 dígitos do nome original do arquivo

### Requirement: Importação do HTML da página do Lattes
O sistema SHALL importar um currículo a partir do HTML da página pública do Lattes, colado em `/admin/curriculum/new` ou recebido pela API de sincronização, com a mesma substituição de itens, numa única transação, e a mesma preservação das orientações do Repositório, mas sem conferir a data de atualização e com menos dados que o XML.

#### Scenario: Descoberta do ID na página
- **WHEN** o ID Lattes não é informado
- **THEN** o sistema usa um ID de 16 dígitos encontrado na página (endereço `lattes.cnpq.br/<ID>` ou campo `id` com 16 dígitos), nunca o K-ID de 10 caracteres
- **AND** sem ID, a importação falha com `Não foi possível identificar o ID Lattes no HTML informado.`

#### Scenario: Produção extraída do texto
- **WHEN** a página traz listas de produção
- **THEN** cada item recebe o tipo pela seção em que aparece, e os autores são separados por ponto e vírgula, com o mesmo texto em nome completo e nome de citação e sem ID Lattes
- **AND** os espaços repetidos do texto extraído são reduzidos a um

#### Scenario: HTML de data antiga
- **WHEN** o HTML traz uma data de atualização anterior à gravada
- **THEN** o currículo é importado assim mesmo

#### Scenario: Reimportação do mesmo HTML
- **WHEN** o mesmo HTML é importado duas vezes
- **THEN** a normalização e a contagem de produções do relatório veem só os itens recém-gravados, e o docente não fica com produções duplicadas

#### Scenario: Falha no meio do HTML
- **WHEN** a gravação dos itens de um HTML falha no meio, pelo painel ou pelo marcador
- **THEN** as produções, orientações e demais itens anteriores do docente permanecem como estavam, e a falha é informada

### Requirement: Relatório de alterações da importação
O sistema SHALL produzir, a cada importação de currículo (XML ou HTML), um relatório que compara o currículo antes e depois, lista as produções (por tipo), orientações, projetos e prêmios novos e traz uma frase de resumo, mostrada na tela e devolvida pela API.

#### Scenario: Docente novo
- **WHEN** o docente não existia antes da importação
- **THEN** a frase é `Docente <nome> cadastrado com sucesso! Total importado: ...` com as quantidades de artigos, livros, capítulos, eventos e orientações

#### Scenario: Nada novo
- **WHEN** o currículo reimportado não tem item novo
- **THEN** a frase informa que o currículo foi sincronizado e nenhuma nova produção ou orientação foi identificada

#### Scenario: Critério de item novo
- **WHEN** uma produção tem tipo, título (sem diferença de caixa e de espaços) e ano que não existiam antes
- **THEN** ela entra no relatório como nova e na contagem da frase de resumo

### Requirement: Etapas depois da importação pela web
Depois de importar pelo painel ou pela API de sincronização, o sistema SHALL reaplicar as teses do Repositório UFSCar aos docentes importados, quando `docs/banco/TeD-UFSCar.csv` existe, e limpar o cache de páginas públicas; falhas nessas etapas MUST virar avisos sem desfazer a importação, e a mensagem de sucesso SHALL avisar que temas e pipeline de revistas não rodam na importação pela web.

#### Scenario: Poucos docentes
- **WHEN** até 3 docentes são importados
- **THEN** o Repositório é reaplicado com uma passada restrita a cada docente; acima disso, com uma passada única para todos

#### Scenario: Falha no Repositório
- **WHEN** a reaplicação das teses falha
- **THEN** o currículo continua importado e a tela mostra um aviso que recomenda rodar `app:import:repository`

#### Scenario: Falha ao limpar o cache
- **WHEN** o cache de páginas não pode ser limpo
- **THEN** a tela avisa que as páginas do docente podem mostrar o currículo antigo e indica `/admin/cache`

### Requirement: Importação em produção pelo script
O sistema SHALL oferecer `bin/import-curriculos.sh [--check] [--dept="CODIGO|Nome"] arquivo.xml|pasta ...`, que confere os arquivos e, fora do modo de conferência, faz em ordem: backup do banco, envio dos XML para `docs/banco/CECH/lote-<data>/`, `app:import:lattes`, `app:import:repository` (se houver o CSV), `app:journals:pipeline`, `app:index:topics` e `app:cache:clear`, no servidor de produção ou, com `LOCAL=1`, no banco local.

#### Scenario: Conferência
- **WHEN** o script roda com `--check`
- **THEN** cada arquivo aparece como `novo`, `atualiza` ou `pulado` (XML mais antigo que o banco), com o aviso de que docente novo sem `--dept` fica sem departamento
- **AND** nada é alterado

#### Scenario: Arquivo que não é currículo
- **WHEN** um arquivo não contém `CURRICULO-VITAE` ou não tem ID Lattes no atributo nem no nome
- **THEN** o script para antes do backup, sem alterar nada

#### Scenario: Falha em parte dos arquivos
- **WHEN** a importação falha em algum arquivo
- **THEN** as etapas seguintes rodam assim mesmo e o script termina com aviso e código 1
- **AND** sem o arquivo de backup gerado, o script para antes de alterar o banco

### Requirement: Complemento pela planilha de docentes do CECH
O sistema SHALL oferecer o comando `app:import:excel`, que lê a planilha de docentes (`--info-file`, padrão `docs/banco/2026-08-23 - Info docentes do CECH.xlsx`) e, em cada linha com ID Lattes de 16 dígitos na coluna A, grava o departamento (coluna B), o ORCID (coluna G, só se o docente não tiver um), o ano de entrada (Y) e o de saída (Z), criando o docente com o nome da coluna C quando ele não existe. A planilha não altera o Qualis.

#### Scenario: Código de departamento conhecido e desconhecido
- **WHEN** a coluna B traz `ED` ou um código fora da tabela, como `XYZ`
- **THEN** o nome do departamento fica `Departamento de Educação` ou `Departamento (XYZ)`, respectivamente

#### Scenario: Planilha sem docentes
- **WHEN** nenhuma linha tem ID Lattes de 16 dígitos na coluna A, ou o arquivo não existe ou não pode ser lido
- **THEN** o comando termina com erro

#### Scenario: Opção removida
- **WHEN** o comando recebe `--prod-file`
- **THEN** a opção é ignorada com um aviso de que o Qualis vem do cadastro de periódicos

### Requirement: Leitura do XML sem acesso à rede
O sistema MUST ler os XML do Lattes (importação, `app:sync:keywords` e `app:projects:backfill-members`) sem acesso à rede: DTD e entidades externas citadas no arquivo MUST NOT ser buscadas. O conteúdo do próprio arquivo SHALL ser lido normalmente.

#### Scenario: XML que cita um endereço externo
- **WHEN** um XML de currículo declara uma DTD ou entidade externa com endereço `http://`
- **THEN** nenhuma conexão é aberta para esse endereço durante a leitura

#### Scenario: XML comum do CNPq
- **WHEN** um XML exportado do Lattes é importado
- **THEN** o currículo é importado como antes
