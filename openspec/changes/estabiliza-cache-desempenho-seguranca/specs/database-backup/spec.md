## MODIFIED Requirements

### Requirement: Conteúdo do superdump
O sistema SHALL gerar um arquivo SQL com todas as tabelas do banco, cada uma com `DROP TABLE IF EXISTS`, a definição completa da tabela e os dados em comandos `INSERT` de até 500 linhas, sem as colunas geradas pelo banco, com checagem de chaves estrangeiras desligada e tudo dentro de uma transação. O nome padrão SHALL ser `backup_{banco}_{AAAA-MM-DD_HH-MM-SS}`. Por padrão o arquivo SHALL ser compactado em `.sql.zip`, removendo o `.sql` intermediário; sem compactação, fica o `.sql`.

#### Scenario: Dump compactado
- **WHEN** um backup é gerado com a compactação padrão
- **THEN** `var/backups` passa a ter `backup_{banco}_{data}.sql.zip` e não fica o `.sql` solto

#### Scenario: Restauração do dump em outro servidor
- **WHEN** o `.sql` extraído do zip é importado com o cliente `mysql` num banco vazio
- **THEN** as tabelas e os dados ficam iguais aos do banco de origem

#### Scenario: Tabela com colunas geradas
- **WHEN** o backup inclui `production_items`
- **THEN** a definição da tabela traz `work_key` e `keywords_text` como colunas geradas, os `INSERT` não trazem valores para elas, e a restauração as recalcula sem erro

### Requirement: Download de backup
O sistema SHALL entregar um backup como anexo em `/admin/database/backup/download/{arquivo}` (e no equivalente `/admin/database/backup/{arquivo}`). O nome MUST ser reduzido ao nome base e aceito só com letras, números, `_`, `-` e `.`, com extensão `.zip`, `.gz` ou `.sql`, dentro de `var/backups`. Arquivo inválido ou inexistente MUST responder 404, sem entregar outro backup. `/admin/database/backup/download` SHALL baixar o mais recente. Não há rota coringa em `/admin/database/{qualquer-coisa}`.

#### Scenario: Tentativa de sair da pasta
- **WHEN** um administrador pede `/admin/database/backup/download/..%2F..%2F.env`
- **THEN** a resposta é 404 e nenhum arquivo fora de `var/backups` é entregue

#### Scenario: Arquivo inexistente
- **WHEN** um administrador pede o download de um nome que não existe e há backups na pasta
- **THEN** a resposta é 404 e nenhum backup é entregue

#### Scenario: Pasta vazia
- **WHEN** um administrador abre `/admin/database/backup/download` e não há nenhum backup
- **THEN** o sistema volta à tela de backup com a mensagem de erro

#### Scenario: Endereço fora das rotas de backup
- **WHEN** um administrador abre `/admin/database/qualquer-coisa`
- **THEN** a resposta é 404
