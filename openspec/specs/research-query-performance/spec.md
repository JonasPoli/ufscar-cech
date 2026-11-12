# research-query-performance Specification

## Purpose
Manter rápidas as consultas da pesquisa pública (busca, panorama, rede de coautoria, Explorar e API) com valores calculados e índices no banco, sem alterar o dado do Lattes e sem mudar nenhum resultado.

## Requirements

### Requirement: Chave da obra única calculada pelo banco
Cada registro de `production_items` SHALL ter em `work_key` o MD5 da chave da obra única: `DOI:` com o DOI sem espaços nas pontas e em minúsculas ou, sem DOI, `T:` com tipo, ano e título sem espaços nas pontas e em minúsculas. O banco SHALL calcular e manter a coluna em toda gravação; os importadores MUST NOT gravá-la, e as colunas do Lattes ficam intactas.

#### Scenario: Artigo com DOI
- **WHEN** um artigo é gravado com o DOI ` 10.5555/ABC `
- **THEN** `work_key` é o MD5 de `DOI:10.5555/abc`, e a coluna `doi` continua como veio

#### Scenario: Livro sem DOI
- **WHEN** um livro de 2019 sem DOI é gravado com o título `Educação Especial`
- **THEN** `work_key` é o MD5 de `T:LIVRO:2019:educação especial`

#### Scenario: Currículo reimportado
- **WHEN** a reimportação de um currículo grava um DOI num item que antes não tinha
- **THEN** `work_key` passa a usar o DOI, sem nenhum passo extra da importação

### Requirement: Palavras-chave em texto calculado pelo banco
Cada registro de `production_items` SHALL ter em `keywords_text` a lista de palavras-chave de `extra_data.keywords` como texto, calculada e mantida pelo banco, ou texto vazio quando não há palavras-chave. A coluna `extra_data` MUST ficar intacta.

#### Scenario: Produção com palavras-chave
- **WHEN** uma produção tem `extra_data.keywords` igual a `["Leitura", "Escrita"]`
- **THEN** `keywords_text` contém `Leitura` e `Escrita`

#### Scenario: Produção sem palavras-chave
- **WHEN** uma produção não tem `keywords` em `extra_data`
- **THEN** `keywords_text` é texto vazio, e a pontuação da busca não fica nula por isso

### Requirement: Índices da obra única e da coautoria
O banco SHALL ter índices em `production_items (work_key)` e `(researcher_id, work_key)` e em `production_authors (production_item_id, matched_researcher_id)` e `(matched_researcher_id, production_item_id)`, criados por migration.

#### Scenario: Banco migrado
- **WHEN** as migrations são aplicadas num banco existente
- **THEN** as duas colunas calculadas são preenchidas para todos os registros e os quatro índices existem

### Requirement: Pesquisa pública sobre os valores calculados
A busca, o panorama, a rede de coautoria, as páginas do Explorar e a API pública SHALL usar `work_key` e `keywords_text` em vez de calcular o MD5 e extrair o JSON linha a linha. Os resultados MUST continuar idênticos aos de antes, incluindo as contagens de obras únicas.

#### Scenario: Métricas antes e depois
- **WHEN** `/api/v1/metricas` é pedida com os mesmos dados antes e depois da migration
- **THEN** os totais de publicações únicas, a série anual e os tipos são iguais

#### Scenario: Busca por palavra-chave
- **WHEN** alguém busca um termo que só aparece nas palavras-chave de uma produção
- **THEN** a produção continua sendo encontrada, com a mesma relevância de antes
