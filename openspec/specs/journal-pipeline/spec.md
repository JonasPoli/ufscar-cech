# journal-pipeline Specification

## Purpose
Sequência de produção dos dados de periódicos em um comando só (`app:journals:pipeline`), com a auditoria dos vínculos artigo→periódico antes e depois e o relatório só de leitura. Os artefatos chegam ao servidor prontos (evidências, SciELO, respostas de IA, overrides), sempre chaveados por ISSN ou título, nunca por id do banco.

## Requirements
### Requirement: Ordem fixa dos passos
O comando `app:journals:pipeline [--derived=docs/banco/derived]` SHALL rodar, cada um pelo seu comando e sem interação, nesta ordem: normalize-titles, audit-before, scielo-enrich, area-thesaurus-first, evidence-load, families-first, index, families, learn, overrides-names, learn-external, families-final, index-again, audit-after, area-thesaurus, title-rules, classify, ai-import, overrides-areas, classify-again, report, cache-clear. As migrations MUST NOT fazer parte da sequência.

#### Scenario: Execução completa
- **WHEN** todos os passos terminam sem erro
- **THEN** o comando mostra um resumo com a situação e o tempo de cada passo e termina com sucesso

#### Scenario: Carga de evidências no pipeline
- **WHEN** o passo evidence-load roda
- **THEN** ele carrega `journal_evidence.jsonl.gz` da pasta derivada com criação de linhas para artigos e evidência legada

### Requirement: Parada no primeiro erro
O pipeline SHALL parar no primeiro passo que terminar com erro, sem executar os seguintes, mostrar o resumo do que rodou e terminar com falha.

#### Scenario: Passo com erro
- **WHEN** o passo families falha
- **THEN** index e os passos seguintes não são executados
- **AND** o resumo mostra families como "falhou" e o comando termina com código de erro

### Requirement: Seleção de passos
O pipeline SHALL aceitar `--only` e `--skip` (nomes separados por vírgula ou repetindo a opção) e `--from` (começa por um passo e segue até o fim), sempre mantendo a ordem fixa. Nome de passo desconhecido MUST encerrar com erro que lista os passos válidos, e uma combinação que não seleciona nenhum passo também MUST encerrar com erro.

#### Scenario: Só alguns passos
- **WHEN** o comando roda com `--only=normalize-titles,audit-before`
- **THEN** só esses dois passos são executados, nessa ordem

#### Scenario: Passo inexistente
- **WHEN** o comando roda com `--from=foo`
- **THEN** termina com `Passo desconhecido: "foo"` seguido da lista de passos, sem executar nada

### Requirement: Artefatos obrigatórios e passos opcionais
Antes de qualquer passo, o pipeline SHALL conferir na pasta derivada `scielo_articlemeta.json` (se scielo-enrich foi escolhido) e `journal_evidence.jsonl.gz` (se evidence-load, algum passo de famílias ou learn-external foi escolhido) e, faltando algum, MUST terminar com erro sem tocar no banco. Sem `overrides/names.jsonl`, `overrides/areas.jsonl` ou respostas de IA (`ai/*.out.jsonl`, `ai_titles/*.out.jsonl`), o passo correspondente SHALL ser pulado com aviso.

#### Scenario: Artefato de evidências ausente
- **WHEN** `journal_evidence.jsonl.gz` não está na pasta derivada
- **THEN** o comando lista os artefatos ausentes e termina sem executar nenhum passo

#### Scenario: Sem overrides de áreas
- **WHEN** `overrides/areas.jsonl` não existe
- **THEN** o passo overrides-areas aparece como "pulado" e o pipeline continua

### Requirement: Auditoria dos vínculos artigo→periódico
O comando `app:journals:links:audit` SHALL exigir exatamente uma de `--snapshot=<arquivo>` (grava, por artigo, o periódico, o estrato e as bases, mais os totais) e `--compare=<arquivo>` (mostra iguais, que mudaram de periódico, que ganharam e que perderam vínculo, a variação por estrato e por base, os 30 maiores pares antes→depois e três casos conhecidos que devem dar zero), com `--json` opcional. O comando MUST só ler o banco.

#### Scenario: Comparação depois da reindexação
- **WHEN** a comparação roda com um retrato anterior
- **THEN** mostra quantos artigos mudaram de periódico, ganharam e perderam vínculo e as violações dos casos conhecidos

#### Scenario: Opções erradas
- **WHEN** o comando recebe `--snapshot` e `--compare` juntos, ou nenhum dos dois
- **THEN** termina com erro pedindo um, e somente um, dos parâmetros

### Requirement: Retrato de cada execução do pipeline
O passo audit-before SHALL gravar o retrato em `var/audit/pipeline-<AAAAMMDD-HHMMSS>-before.json` e MUST falhar se esse arquivo já existir. O passo audit-after SHALL comparar com o retrato da mesma execução ou, se audit-before não rodou nela, com o `pipeline-*-before.json` mais recente, avisando; sem nenhum retrato, o passo é pulado.

#### Scenario: Retomada com `--from`
- **WHEN** o pipeline roda com `--from=index-again`
- **THEN** audit-after compara com o retrato mais recente de uma execução anterior e avisa disso

#### Scenario: Nenhum retrato
- **WHEN** não há nenhum `pipeline-*-before.json` em `var/audit`
- **THEN** audit-after é pulado

### Requirement: Relatório dos periódicos
O comando `app:journals:report` SHALL mostrar, sem gravar nada, o cadastro e as famílias, as famílias visíveis (com artigos), as famílias visíveis classificadas por confiança da área principal, os artigos por base, as variantes por tipo, a fila de nomes por status e os `normalized_name` repetidos em `journal_name_variants`.

#### Scenario: Relatório no fim do pipeline
- **WHEN** o passo report roda
- **THEN** o relatório é impresso e nenhuma tabela é alterada
