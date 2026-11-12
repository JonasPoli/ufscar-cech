# curriculum-normalization Specification

## Purpose
Ligar o currículo importado de cada docente às entidades do portal (coautores a docentes e ao tesauro de autores, artigos a periódicos, formações e atuações a instituições) e deduzir a situação do vínculo com a UFSCar, gravando tudo em colunas próprias e sem tocar no dado do Lattes.

## Requirements

### Requirement: Normalização ao final de cada importação
O sistema SHALL normalizar o docente logo depois de gravar o seu currículo, em toda importação de XML ou HTML; na importação de XML, o índice de docentes usado para reconhecer coautores SHALL ser renovado antes da normalização.

#### Scenario: Docente novo reconhecido como coautor
- **WHEN** um docente é importado pela primeira vez por XML
- **THEN** o índice de docentes é renovado e a normalização desse e dos próximos currículos já reconhece o docente novo como coautor

#### Scenario: Importação por HTML
- **WHEN** um currículo é importado a partir do HTML da página do Lattes
- **THEN** a normalização roda ao final, como na importação de XML

### Requirement: Comando de normalização em lote
O sistema SHALL oferecer o comando `app:curriculum:normalize`, que normaliza os docentes em ordem de nome, com `--dept`, `--only-pending` e `--clear-cache`, e termina com um resumo do lote. A fila de nomes de periódicos sem vínculo SHALL ser reconstruída uma só vez, ao fim do lote, e não a cada docente.

#### Scenario: Opções e resumo
- **WHEN** o comando roda com `--dept` (código ou nome), `--only-pending` ou `--clear-cache`
- **THEN** filtra o departamento, processa só os nunca normalizados ou limpa antes os índices em cache de autores, periódicos e instituições
- **AND** termina com o resumo de docentes, produções, autores, coautores do CECH, Qualis e instituições

#### Scenario: Somente pendentes de um departamento
- **WHEN** o comando roda com `--dept=ED --only-pending`
- **THEN** só os docentes do departamento ED sem data de normalização são processados

#### Scenario: Nenhum docente para os critérios
- **WHEN** nenhum docente atende aos filtros
- **THEN** o comando informa que não encontrou docentes e termina com sucesso

#### Scenario: Fila de nomes reconstruída uma vez
- **WHEN** o comando normaliza 100 docentes
- **THEN** a fila de nomes de periódicos sem vínculo é reconstruída uma só vez, depois do último docente, com o mesmo conteúdo que teria se fosse reconstruída a cada docente

### Requirement: Normalização pelo painel
O sistema SHALL oferecer a administradores, em `/admin/indexing`, um panorama da normalização filtrável por departamento, uma fila de docentes (`/admin/indexing/queue`, com opção `only_pending`) e a normalização de um docente por vez (`POST /admin/indexing/step/{id}`), que responde em JSON com as estatísticas e o tempo gasto.

#### Scenario: Normalizar um docente pela fila
- **WHEN** o administrador envia `POST /admin/indexing/step/42`
- **THEN** o docente 42 é normalizado e a resposta traz `success`, o nome, o ID Lattes, as estatísticas e `elapsedMs`

#### Scenario: Conteúdo do panorama
- **WHEN** o panorama é aberto
- **THEN** ele mostra docentes, normalizados, pendentes (nunca normalizados), ativos, com vínculo encerrado, produções, artigos com periódico, com Qualis e com bases, autores, autores normalizados e coautores do CECH

### Requirement: Ligação dos coautores
Para cada autor de cada produção, o sistema SHALL procurar a identidade pelo nome de citação (ou, sem ele, pelo nome completo) e, sem resultado, pelo nome completo e pelo nome de citação; quando a identidade é um docente do portal, SHALL gravar o docente em `matched_researcher_id` e marcar `is_cech_researcher`; quando é uma identidade do tesauro de autores, SHALL gravar `author_identity_id`. Todo autor percorrido é marcado como normalizado (`is_indexed`).

#### Scenario: Coautor que é docente
- **WHEN** o nome de citação de um coautor coincide com o nome ou um nome de citação de um docente do portal
- **THEN** o coautor fica ligado ao docente e marcado como docente do CECH

#### Scenario: Coautor externo conhecido do tesauro
- **WHEN** o nome do coautor corresponde a uma variante ativa de uma identidade ativa do tesauro que não é docente
- **THEN** o coautor recebe essa identidade e fica marcado como não docente

#### Scenario: Coautor não reconhecido
- **WHEN** nenhum nome do coautor é reconhecido
- **THEN** as ligações que ele já tinha não mudam e ele é marcado como normalizado

### Requirement: Ligação dos artigos aos periódicos
Na normalização por docente, o sistema SHALL recalcular `journal_name_normalized` de toda produção e ligar a periódico só os `ARTIGO` com nome de periódico ou ISSN, gravando `qualis_journal_id`, `qualis` e `indexed_databases`; as demais produções MUST ficar sem esses valores. A fila de nomes de periódicos sem vínculo SHALL ser atualizada ao fim da normalização de um docente isolado ou, no lote, uma vez ao fim dele; uma falha nela MUST NOT interromper a normalização.

#### Scenario: Artigo sem vínculo seguro
- **WHEN** o periódico de um artigo não é reconhecido ou é ambíguo
- **THEN** o artigo fica sem `qualis_journal_id`, `qualis` e `indexed_databases`, inclusive os que uma normalização anterior tenha gravado
- **AND** o nome entra na fila de revisão de nomes de periódicos

#### Scenario: Capítulo com título de livro igual ao de uma revista
- **WHEN** um capítulo tem em `journal_name` um título de livro que coincide com o nome de uma revista
- **THEN** o capítulo não recebe periódico, Qualis nem bases

#### Scenario: Docente normalizado isoladamente
- **WHEN** um docente é normalizado pelo painel ou ao fim de uma importação
- **THEN** a fila de nomes de periódicos é atualizada ao final dessa normalização

### Requirement: Ligação das instituições
O sistema SHALL ligar à instituição do tesauro (`institution_id`) cada formação e cada atuação profissional do docente cujo nome de instituição seja reconhecido; o nome que não é reconhecido mantém a ligação que já tinha.

#### Scenario: Formação em instituição conhecida
- **WHEN** a formação traz `Universidade Federal de São Carlos` e essa grafia é nome oficial, nome curto, sigla ou variante ativa de uma instituição ativa
- **THEN** a formação fica ligada a essa instituição

### Requirement: Situação do vínculo com a UFSCar
O sistema SHALL deduzir o ano de entrada, o ano de saída e a situação (ativo ou inativo) do docente a partir das atuações profissionais cujo nome de instituição contém "são carlos" ou "ufscar", sem diferença de caixa. O ano de entrada é o menor ano de início entre os vínculos que não são de substituto, aluno, bolsista, estagiário ou temporário.

#### Scenario: Docente com vínculo atual
- **WHEN** o docente tem um vínculo com a UFSCar atual, sem ano de fim ou que termina no ano corrente ou depois, e um ano de saída anterior ao ano corrente
- **THEN** ele fica ativo e o ano de saída é apagado

#### Scenario: Todos os vínculos encerrados
- **WHEN** todos os vínculos com a UFSCar terminaram em anos passados, o último em 2019
- **THEN** o docente fica inativo com ano de saída 2019, a menos que já tenha um ano de saída anterior ao ano corrente

#### Scenario: Ano de entrada já informado
- **WHEN** o docente já tem ano de entrada (por exemplo, vindo da planilha do CECH)
- **THEN** a normalização não o altera

### Requirement: Marca de normalização
O sistema SHALL registrar em cada docente a data e hora da última normalização, que distingue docentes normalizados de pendentes no painel e na opção `--only-pending`.

#### Scenario: Docente normalizado
- **WHEN** a normalização de um docente termina
- **THEN** a data e hora da normalização é gravada e ele deixa de constar como pendente
