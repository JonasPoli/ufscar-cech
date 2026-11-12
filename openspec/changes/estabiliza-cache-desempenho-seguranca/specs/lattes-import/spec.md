## MODIFIED Requirements

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

#### Scenario: Falha no meio do HTML
- **WHEN** a gravação dos itens de um HTML falha no meio, pelo painel ou pelo marcador
- **THEN** as produções, orientações e demais itens anteriores do docente permanecem como estavam, e a falha é informada

## ADDED Requirements

### Requirement: Leitura do XML sem acesso à rede
O sistema MUST ler os XML do Lattes (importação, `app:sync:keywords` e `app:projects:backfill-members`) sem acesso à rede: DTD e entidades externas citadas no arquivo MUST NOT ser buscadas. O conteúdo do próprio arquivo SHALL ser lido normalmente.

#### Scenario: XML que cita um endereço externo
- **WHEN** um XML de currículo declara uma DTD ou entidade externa com endereço `http://`
- **THEN** nenhuma conexão é aberta para esse endereço durante a leitura

#### Scenario: XML comum do CNPq
- **WHEN** um XML exportado do Lattes é importado
- **THEN** o currículo é importado como antes
