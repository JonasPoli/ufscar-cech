## MODIFIED Requirements

### Requirement: Comando de normalização em lote
O sistema SHALL oferecer o comando `app:curriculum:normalize`, que normaliza os docentes em ordem de nome, com filtro por departamento (`--dept`, por código ou nome), opção de processar só os nunca normalizados (`--only-pending`) e de limpar antes os índices em cache de autores, periódicos e instituições (`--clear-cache`), e que termina com um resumo de docentes, produções, autores, coautores do CECH, Qualis e instituições. A fila de nomes de periódicos sem vínculo SHALL ser reconstruída uma só vez, ao fim do lote, e não a cada docente.

#### Scenario: Somente pendentes de um departamento
- **WHEN** o comando roda com `--dept=ED --only-pending`
- **THEN** só os docentes do departamento ED sem data de normalização são processados

#### Scenario: Nenhum docente para os critérios
- **WHEN** nenhum docente atende aos filtros
- **THEN** o comando informa que não encontrou docentes e termina com sucesso

#### Scenario: Fila de nomes reconstruída uma vez
- **WHEN** o comando normaliza 100 docentes
- **THEN** a fila de nomes de periódicos sem vínculo é reconstruída uma só vez, depois do último docente, com o mesmo conteúdo que teria se fosse reconstruída a cada docente

### Requirement: Ligação dos artigos aos periódicos
Na normalização por docente, o sistema SHALL recalcular `journal_name_normalized` de toda produção e SHALL ligar a periódico apenas as produções do tipo `ARTIGO` que têm nome de periódico ou ISSN, gravando `qualis_journal_id`, `qualis` e `indexed_databases`; as demais produções MUST ficar sem esses três valores. A fila de nomes de periódicos sem vínculo é atualizada ao final da normalização de um docente isolado ou, no comando em lote, uma vez ao fim do lote; uma falha nessa fila não interrompe a normalização.

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
