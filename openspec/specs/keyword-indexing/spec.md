# keyword-indexing Specification

## Purpose
Construção do índice temático (termos e vínculo termo–docente) a partir das palavras-chave declaradas e de expressões mineradas nos títulos de produções, orientações e projetos, e sincronização das palavras-chave do Lattes em produções antigas. O índice alimenta a Pesquisa Temática, o filtro `tema` da pesquisa e o catálogo de assuntos, sem nunca alterar o dado bruto.

## Requirements
### Requirement: Comando de reconstrução do índice temático
O sistema SHALL reconstruir o índice temático inteiro com o comando de console `app:index:topics` (também disponível como `app:topics:index` e `app:index:thematic-terms`), que não recebe opções, mostra o progresso em 4 etapas e termina informando o tempo gasto, o total de termos e o total de vínculos com docentes. A importação de currículos pela web MUST NOT reconstruir o índice e SHALL avisar o administrador de que é preciso rodar o comando.

#### Scenario: Execução do comando
- **WHEN** o operador roda `php bin/console app:index:topics`
- **THEN** o índice é reconstruído e o comando informa o total de termos e de vínculos

#### Scenario: Importação pela web
- **WHEN** um administrador importa um currículo pelo painel
- **THEN** a mensagem de sucesso avisa que a busca temática não foi atualizada e indica `app:index:topics` ou o script de importação em lote

### Requirement: Fontes e pesos do índice
O sistema SHALL indexar apenas itens ligados a um docente cadastrado, com estes pesos por ocorrência: em produções, cada palavra-chave declarada no Lattes vale 2 (inteira, como veio) e as expressões mineradas do título valem 1; em orientações, as palavras-chave (separadas por ponto e vírgula ou vírgula) valem 2, as expressões do título valem 2 e as do título alternativo, quando diferente do título, valem 1; em projetos de pesquisa, as expressões do nome valem 2.

#### Scenario: Palavra-chave declarada numa orientação
- **WHEN** uma orientação tem as palavras-chave "Inclusão escolar; Educação especial"
- **THEN** os dois termos recebem 2 ocorrências cada para o orientador

#### Scenario: Item sem docente
- **WHEN** uma produção não está ligada a um docente cadastrado
- **THEN** ela não contribui para o índice

### Requirement: Mineração de expressões nos títulos
O sistema SHALL extrair expressões dos títulos ignorando palavras com menos de 3 letras, palavras vazias (português, inglês e termos genéricos acadêmicos e institucionais, como "estudo", "análise", "universidade"), números e algarismos romanos. SHALL formar pares de palavras válidas consecutivas e trios palavra–conector–palavra (conectores como "de", "em", "para", "of", "and"). Uma palavra solta SHALL virar termo só com ao menos 4 letras e quando aparecer fora das expressões formadas.

#### Scenario: Expressão com conector
- **WHEN** um título contém "formação de professores"
- **THEN** a expressão "Formação de Professores" é contada

#### Scenario: Palavra absorvida por expressão
- **WHEN** o título é "Educação especial inclusiva"
- **THEN** entram "educação especial" e "especial inclusiva", e as palavras soltas não entram

### Requirement: Normalização e forma canônica dos termos
O sistema SHALL limpar a pontuação nas pontas de cada termo, descartar termos com menos de 3 caracteres, numéricos ou que sejam palavra vazia, e agrupar as grafias pela forma normalizada (minúsculas, sem acentos e cedilha, demais símbolos trocados por espaço). O nome exibido SHALL ser a primeira grafia encontrada, com iniciais maiúsculas exceto nos conectores, e cada termo SHALL registrar a origem: `keyword`, `mined` ou `hybrid`.

#### Scenario: Grafias com e sem acento
- **WHEN** um docente declara "Educação Especial" e, depois, outro declara "educacao especial"
- **THEN** as duas formas viram um único termo, exibido como "Educação Especial"
- **AND** as ocorrências e os docentes das duas grafias somam no mesmo termo

#### Scenario: Origem do termo
- **WHEN** um termo é primeiro minerado de títulos e depois encontrado como palavra-chave declarada
- **THEN** sua origem é `hybrid`
- **AND** um termo encontrado só em títulos tem origem `mined`, e um encontrado primeiro como palavra-chave, `keyword`

### Requirement: Vínculo entre termo e docente
O sistema SHALL registrar, para cada par termo–docente, a soma dos pesos das ocorrências e um título de exemplo (o primeiro trabalho em que o termo apareceu para o docente). Cada termo SHALL guardar o total de ocorrências (soma de todos os docentes) e o número de docentes. Termos com menos de 2 ocorrências no total MUST ser descartados como ruído.

#### Scenario: Termo de ocorrência única
- **WHEN** uma expressão aparece uma única vez, com peso 1, em todo o acervo
- **THEN** ela não entra no índice

#### Scenario: Contagem por docente
- **WHEN** um docente tem a mesma palavra-chave declarada em três produções
- **THEN** o vínculo desse docente com o termo registra 6 ocorrências

### Requirement: Slug do termo
O sistema SHALL gerar para cada termo um slug único em ASCII minúsculo a partir do nome exibido; em caso de colisão, SHALL acrescentar `-2`, `-3` e assim por diante, e quando o nome não produzir slug SHALL usar `termo-` seguido de um código derivado da forma normalizada. O slug SHALL ser o identificador usado nos links públicos de tema.

#### Scenario: Dois termos com o mesmo slug
- **WHEN** dois termos distintos geram o slug `educacao-especial`
- **THEN** o segundo recebe `educacao-especial-2`

### Requirement: Troca atômica do índice
O sistema SHALL montar o índice novo à parte e colocá-lo no lugar do atual numa única troca, de modo que a busca temática nunca fique vazia durante a reconstrução. Se a reconstrução falhar, o índice anterior MUST continuar em uso e o comando SHALL terminar com erro; sobras de uma execução interrompida SHALL ser descartadas na seguinte. Apagar um docente ou um termo SHALL apagar os vínculos correspondentes, e os ids numéricos dos termos podem mudar a cada reconstrução.

#### Scenario: Falha durante a carga
- **WHEN** a gravação dos termos falha no meio da reconstrução
- **THEN** a Pesquisa Temática continua respondendo com o índice anterior

#### Scenario: Execução após uma interrupção
- **WHEN** uma reconstrução anterior foi interrompida e deixou tabelas de trabalho
- **THEN** a próxima execução as descarta antes de começar

#### Scenario: Docente apagado após a troca
- **WHEN** um docente é apagado depois de uma reconstrução
- **THEN** os vínculos dele com os termos também são apagados

### Requirement: Preservação do dado bruto na indexação
A indexação temática MUST apenas ler produções, orientações, projetos e docentes, sem alterar títulos, palavras-chave ou qualquer outro dado vindo do Lattes ou do Repositório Institucional; toda normalização, contagem e vínculo SHALL ser gravado exclusivamente nas tabelas próprias do índice temático.

#### Scenario: Título com grafia irregular
- **WHEN** um título no Lattes tem caixa alta e pontuação irregular
- **THEN** o título permanece como veio, e só o termo derivado no índice recebe a forma normalizada

### Requirement: Comando de sincronização de palavras-chave
O sistema SHALL oferecer `app:sync:keywords`, para produções importadas antes de o importador gravar as palavras-chave, com as opções `--dir` (padrão `docs/banco/CECH`) e `--file`. Para cada XML do Lattes, SHALL identificar o docente pelo ID Lattes do currículo ou pelo nome do arquivo `lattes{id}.xml`, ler até 6 palavras-chave de cada produção bibliográfica e técnica e casar a produção com a do banco pelo título normalizado (sem caixa, pontuação e espaços repetidos).

#### Scenario: Arquivo inexistente
- **WHEN** o operador roda `app:sync:keywords --file=nao-existe.xml`
- **THEN** o comando termina com erro "Arquivo não encontrado"

#### Scenario: Diretório sem XML
- **WHEN** o diretório informado não tem arquivos `.xml`
- **THEN** o comando avisa que nenhum arquivo foi encontrado e termina com sucesso

#### Scenario: Currículo sem docente no banco
- **WHEN** o ID Lattes de um XML não corresponde a nenhum docente cadastrado
- **THEN** o arquivo é ignorado e entra na contagem de arquivos sem docente do resumo

### Requirement: Regras de segurança da sincronização
O comando `app:sync:keywords` MUST gravar palavras-chave só quando o título identificar um único item no banco e uma única produção no XML do mesmo docente, a produção do XML tiver palavras-chave e o item ainda não tiver nenhuma, preservando os demais dados complementares do item. Um arquivo com falha MUST NOT interromper os demais, mas o comando SHALL terminar com falha listando os arquivos com problema.

#### Scenario: Título repetido no mesmo docente
- **WHEN** o docente tem dois itens com o mesmo título no banco, ou o XML tem duas produções com o mesmo título
- **THEN** nenhum item recebe palavras-chave por esse título, que conta como ambíguo no resumo

#### Scenario: Item que já tem palavras-chave
- **WHEN** o item do banco já tem palavras-chave
- **THEN** elas são mantidas como estão e o item entra na contagem dos mantidos

#### Scenario: Um arquivo com erro
- **WHEN** um dos arquivos do diretório não é um currículo Lattes
- **THEN** os outros arquivos são processados e o comando termina com código de falha, listando o arquivo
