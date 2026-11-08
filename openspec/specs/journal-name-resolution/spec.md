# journal-name-resolution Specification

## Purpose
Ligar o nome e o ISSN de periódico que vêm dos artigos do Lattes a uma revista do cadastro (`qualis_journals`), sem alterar o dado bruto. Cobre as formas normalizadas, as etapas do resolver, o tesauro de grafias (`journal_name_variants`) com a sua trava de integridade, o aprendizado de grafias, a fila de revisão de nomes (`journal_name_candidates`) e as correções curadas por arquivo.

## Requirements
### Requirement: Dado bruto do Lattes preservado
O sistema MUST NOT alterar `journal_name`, `issn`, `title` nem outro campo bruto dos itens importados do Lattes ao resolver periódicos. O resultado SHALL ser gravado só em colunas derivadas (`production_items.qualis_journal_id`, `qualis`, `indexed_databases`, `journal_name_normalized`) e no cadastro de revistas (variantes, fila de nomes). Aceitar ou rejeitar um nome MUST NOT apagar nem editar o artigo.

#### Scenario: Reindexação dos artigos
- **WHEN** `app:index:journals` é executado
- **THEN** `journal_name` e `issn` de todos os itens ficam exatamente como estavam
- **AND** só as colunas derivadas mudam

#### Scenario: Nome rejeitado
- **WHEN** um administrador rejeita um nome na fila
- **THEN** os artigos com esse nome continuam com o `journal_name` original e sem vínculo

### Requirement: Formas normalizadas dos nomes
O sistema SHALL usar duas formas derivadas: a forma do tesauro (maiúsculas, sem acento nem pontuação), gravada em `normalized_title`, `journal_name_normalized` e `normalized_name`; e a chave do resolver (entidades HTML decodificadas, minúsculas, só letras e dígitos latinos, espaços colapsados). As duas MUST dar o mesmo resultado em macOS e Linux.

#### Scenario: Chave do resolver
- **WHEN** o nome é "Ciência e Educação (UNESP)" ou "EDUCAÇÃO & SOCIEDADE"
- **THEN** as chaves são "ciencia e educacao unesp" e "educacao sociedade"

#### Scenario: Título com acentos
- **WHEN** o nome é "História da Educação"
- **THEN** a chave é "historia da educacao", sem truncar caracteres

### Requirement: Preenchimento das colunas normalizadas
O comando `app:journals:normalize-titles [--only-missing]` SHALL preencher `qualis_journals.normalized_title` e `production_items.journal_name_normalized` onde o valor calculado difere do gravado (ou só onde está vazio, com a opção) e SHALL separar, nas colunas normalizadas de ISSN, os ISSNs colados no mesmo campo, um por coluna, sem mudar o texto original das colunas de ISSN.

#### Scenario: ISSNs colados
- **WHEN** uma linha tem `issn = "1806-9053, 0102-6909"`
- **THEN** as colunas normalizadas passam a ter `18069053` e `01026909` em colunas separadas
- **AND** o texto de `issn` continua igual

#### Scenario: Execução repetida
- **WHEN** o comando roda duas vezes seguidas
- **THEN** a segunda execução não grava nenhuma linha

### Requirement: Resolução em etapas
O sistema SHALL resolver o periódico de um artigo nesta ordem, parando no primeiro acerto: (1) cada ISSN extraído do campo, mesmo sem dígito verificador válido, nas quatro colunas normalizadas de ISSN, vencendo a linha de menor id; (2) a chave do nome contra títulos, títulos sem parênteses e variantes ativas; (3) o nome sem os trechos entre parênteses; (4) a chave compacta, sem conectivos, só com 2 palavras ou mais.

#### Scenario: ISSN vence o nome
- **WHEN** o artigo traz o ISSN da revista A e o nome da revista B
- **THEN** o artigo é ligado à revista A

#### Scenario: Conectivos diferentes
- **WHEN** o artigo traz "Educação e Sociedade", sem ISSN, e o cadastro tem "EDUCAÇÃO & SOCIEDADE"
- **THEN** o artigo é ligado a essa revista pela chave compacta

#### Scenario: Complemento entre parênteses
- **WHEN** o artigo traz "Revista X (Cidade)" e o cadastro só tem "REVISTA X"
- **THEN** o artigo é ligado a "REVISTA X"

### Requirement: Nome ambíguo nunca resolve sozinho
Nas etapas por nome, um nome que casa com linhas de mais de uma família MUST NOT gerar vínculo: o artigo fica sem periódico e o nome entra na fila como `ambiguous`. Dentro de uma só família, vale a linha de menor id entre os títulos encontrados ou, se só houve variantes, a linha da variante. O palpite por palavras-chave MUST servir só de sugestão na fila.

#### Scenario: Nome de duas revistas
- **WHEN** um artigo sem ISSN traz um nome que é título de duas famílias diferentes
- **THEN** o artigo fica sem vínculo e o nome aparece na aba "Ambíguos" da fila

#### Scenario: Só palavras-chave em comum
- **WHEN** o nome só se parece com uma revista pelas palavras-chave
- **THEN** o artigo fica sem vínculo e a fila mostra a revista como sugestão "palavras-chave"

### Requirement: Estrato e bases gravados no artigo
Ao resolver um artigo, o sistema SHALL gravar a linha encontrada em `qualis_journal_id`, o seu estrato em `qualis` e as bases dela, em JSON, em `indexed_databases` (nulo sem bases). Se a linha não tem estrato, o artigo SHALL ir para a linha da família que dá o estrato: a canônica ou, sem estrato nela, a de menor id que tem. Artigo sem vínculo seguro SHALL ficar com as três colunas vazias.

#### Scenario: Linha impressa sem Qualis
- **WHEN** o ISSN do artigo é o da linha impressa, sem Qualis, e a linha online da mesma família é A1
- **THEN** o artigo é ligado à linha online e recebe A1

#### Scenario: Nome que passou a ser ambíguo
- **WHEN** um artigo ligado numa indexação anterior agora tem nome ambíguo e nenhum ISSN conhecido
- **THEN** a reindexação limpa `qualis_journal_id`, `qualis` e `indexed_databases`

### Requirement: Indexação em lote dos artigos
O comando `app:index:journals` (alias `app:indexing:journals`) SHALL resolver todos os itens `ARTIGO` com o cadastro carregado em memória, com as mesmas etapas da resolução sob demanda, gravar as colunas derivadas e `journal_name_normalized`, limpar periódico, Qualis e bases dos itens que não são artigo, reconstruir a fila de nomes e mostrar os totais. A normalização por docente na importação usa o mesmo resolver.

#### Scenario: Item que não é artigo
- **WHEN** um capítulo tinha `qualis_journal_id` gravado por uma indexação antiga
- **THEN** `app:index:journals` limpa `qualis_journal_id`, `qualis` e `indexed_databases` dele

#### Scenario: Resumo da indexação
- **WHEN** a indexação termina
- **THEN** o comando mostra artigos processados, periódicos vinculados, estratos atribuídos, artigos com bases, itens não artigo limpos e o tempo

### Requirement: Fila de revisão de nomes
O sistema SHALL manter em `journal_name_candidates` uma linha por `journal_name_normalized` de artigos sem vínculo, com artigos, docentes distintos, grafia e ISSN mais frequentes, até 3 títulos de amostra (os mais recentes), sugestão e status `pending` ou `ambiguous`. A reconstrução SHALL preservar o status de nomes `accepted` ou `rejected` e as sugestões por ISSN, e SHALL apagar os nomes abertos que sumiram, exceto os sugeridos por ISSN.

#### Scenario: Nome que deixou de estar sem vínculo
- **WHEN** os artigos de um nome pendente passam a ter vínculo
- **THEN** a próxima reconstrução tira o nome da fila

#### Scenario: Decisão preservada
- **WHEN** um nome rejeitado continua sem vínculo
- **THEN** a reconstrução atualiza as contagens e as amostras e mantém o status `rejected`

#### Scenario: Comando da fila
- **WHEN** `app:journals:names:queue` roda sem opções
- **THEN** mostra as contagens por status; com `--rebuild`, reconstrói a fila antes e avisa se há artigos sem `journal_name_normalized`

### Requirement: Tela de revisão de nomes
A tela `/admin/journal-names`, só para `ROLE_ADMIN`, SHALL listar os nomes por aba (Pendentes, Ambíguos, Aceitos, Rejeitados, com contagens), 50 por página, por artigos decrescente, com busca pela grafia. Cada nome SHALL mostrar grafia, ISSN visto, artigos, docentes, amostras, a sugestão com link para o periódico e um campo de ID, preenchido pela sugestão ou pela busca de periódicos por nome.

#### Scenario: Busca de periódico por nome
- **WHEN** o administrador digita pelo menos 3 letras na busca de periódico
- **THEN** `/admin/journal-names/search?q=` devolve até 15 famílias cujo título, nome exibido ou variante ativa contém o termo, com o id da canônica, o nome, os ISSNs e o estrato

#### Scenario: Termo curto
- **WHEN** a busca recebe menos de 3 letras
- **THEN** a lista de periódicos vem vazia

### Requirement: Decisões sobre um nome da fila
Toda ação da tela de nomes SHALL ser POST com token CSRF; token inválido não altera nada. Aceitar, só para nome aberto, SHALL gravar o nome como variante `curated` na canônica do periódico escolhido, revincular os artigos com aquele nome e marcar `accepted` com revisor e data. Rejeitar SHALL só marcar `rejected`. Reabrir SHALL valer só para nome rejeitado, que volta a `pending`.

#### Scenario: Aceitar em linha membro
- **WHEN** o administrador aceita "Rev. Bras. Educ." para o periódico #10, membro da família #7
- **THEN** a variante `curated` é gravada no #7, os artigos com esse nome são revinculados e o aviso mostra quantos

#### Scenario: Nome de outra família
- **WHEN** o nome já é título ou variante de outra família
- **THEN** aparece "A variação «…» já pertence ao periódico #id «Título». Remova-a daqui ou mescle os periódicos." e nada é gravado

#### Scenario: Reabrir nome aceito
- **WHEN** o administrador tenta reabrir um nome aceito
- **THEN** aparece "Só nomes rejeitados podem ser reabertos." e nada muda

### Requirement: Trava de integridade do tesauro
Todo criador de variantes MUST passar pela trava: um nome, na forma do tesauro (até 500 caracteres), pertence a uma só família; nome que já é variante (de qualquer status) ou título de outra família MUST ser recusado como conflito; na mesma família não é gravado de novo; texto que parece código (tags HTML, CSS, pilha de erro, `HTTP 500`, `/vendor/`) MUST ser recusado. A variante nova SHALL ser gravada na linha canônica da família.

#### Scenario: Variante digitada no formulário
- **WHEN** o administrador digita no formulário do periódico uma variação que é título de outra família
- **THEN** o formulário volta com o erro de conflito e nada é gravado

#### Scenario: Página de erro numa lista importada
- **WHEN** uma importação traz a grafia "HTTP 500 <small>Internal Server Error</small>"
- **THEN** a grafia não vira variante

#### Scenario: Variante numa linha membro
- **WHEN** o administrador acrescenta uma variação no formulário de uma linha membro
- **THEN** ela é gravada na canônica e um aviso diz em qual periódico ficou

### Requirement: Aprendizado de grafias do Lattes
O comando `app:journals:names:learn [--dry-run] [--min-articles=2] [--only=nome]` SHALL tratar como confirmado o nome de um artigo ligado cujo ISSN é da família ligada. Nome que não é título nem variante: com pelo menos `min-articles` artigos de uma só família, vira variante `lattes` na canônica com a grafia mais frequente; com menos, entra na fila sugerido por ISSN; em famílias diferentes, entra como `ambiguous`. Nomes já revisados são respeitados.

#### Scenario: Dois artigos confirmam a grafia
- **WHEN** "Rev Bras Educ" aparece em 2 artigos ligados pelo ISSN à família #7
- **THEN** a grafia vira variante `lattes` do #7
- **AND** se o nome estava aberto na fila, ele passa a `accepted`, revisado por `auto:issn`

#### Scenario: Um artigo só
- **WHEN** a grafia aparece em 1 artigo confirmado
- **THEN** ela entra na fila como pendente, com sugestão por ISSN para a canônica

#### Scenario: ISSNs de famílias diferentes
- **WHEN** o mesmo nome aparece com ISSNs de duas famílias
- **THEN** nada é aprendido e o nome entra na fila como ambíguo

### Requirement: Títulos das listas externas como variantes
O comando `app:journals:names:learn-external [--file] [--dry-run]` SHALL gravar como variante `external`, na canônica, o título de um registro do artefato de evidências (inclusive títulos alternativos do OpenAlex) cujos ISSNs apontam para uma só família, se o nome não for título nem variante, não estiver rejeitado na fila, só aparecer numa família e for coerente com um título dela. O que não passa MUST NOT ir para a fila.

#### Scenario: Título coerente
- **WHEN** um registro da Scopus aponta pelo ISSN para a família #7 e o título tem nota de semelhança de 0,6 ou mais com um título dela
- **THEN** o título vira variante `external` do #7

#### Scenario: Título incoerente
- **WHEN** o título não se parece com nenhum título da família e só uma lista o traz
- **THEN** ele não é gravado nem vai para a fila

### Requirement: Correções de nomes por arquivo
O comando `app:journals:overrides:import <arquivo.jsonl> [--dry-run]` SHALL aceitar linhas `name` (variante `curated` na família localizada por ISSN ou título exato, revinculação dos artigos e candidato da fila aceito) e `name_reject` (nome da fila rejeitado). Cada linha é tudo ou nada; linha inválida, sem periódico ou com mais de uma família SHALL ser pulada e reportada; erro de banco aborta; `--dry-run` desfaz tudo no fim.

#### Scenario: Nome curado
- **WHEN** o arquivo traz `{"type":"name","name":"Educação e Sociedade","journal":{"issn":"0101-7330"}}`
- **THEN** a família desse ISSN ganha a variante `curated`, os artigos com o nome são revinculados e o candidato da fila é aceito

#### Scenario: Rejeitar nome já aceito
- **WHEN** uma linha `name_reject` cita um nome aceito
- **THEN** a linha é pulada com "o nome já foi aceito; desfazer um aceite é outra operação"

### Requirement: ISSN das revistas do SciELO sem ISSN
O comando `app:journals:scielo:enrich [arquivo] [--dry-run]` SHALL preencher as colunas de ISSN (impresso, online e o código) das linhas ligadas à base `scielo` sem nenhum ISSN válido cuja chave de título é igual à de exatamente um título do cadastro do SciELO ArticleMeta, e o `display_title` quando ele ainda é automático. O `title` MUST ficar intacto; não casados e ambíguos SHALL ser listados.

#### Scenario: Título casado com um registro
- **WHEN** uma linha SciELO sem ISSN tem o mesmo título de um único periódico do ArticleMeta
- **THEN** recebe os ISSNs impresso e online e o nome exibido do SciELO

#### Scenario: Nome exibido manual
- **WHEN** a linha tem `display_title_source = 'manual'`
- **THEN** só os ISSNs são preenchidos
