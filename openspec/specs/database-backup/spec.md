# database-backup Specification

## Purpose
Backup completo ("superdump") e restauração do banco MySQL do portal, pelo painel administrativo em `/admin/database/backup` e pelos comandos `app:database:dump` e `app:database:restore`, com os arquivos guardados em `var/backups`.

## Requirements

### Requirement: Pasta de backups
O sistema SHALL guardar os backups em `var/backups`, criando a pasta quando não existir, junto com um `.gitignore` que impede versionar os arquivos. Os backups reconhecidos SHALL ser os arquivos `.zip`, `.gz` e `.sql` dessa pasta, listados do mais recente para o mais antigo pela data de modificação. Os arquivos gerados pela suíte de testes (nome contendo `_test_` ou começando por `test_ctrl_`) MUST ficar fora da lista fora do ambiente de testes.

#### Scenario: Dump de teste na pasta
- **WHEN** a pasta tem `backup_cech_2026-11-01.sql.zip` e `test_ctrl_restore_abc.sql.zip` e o ambiente é de produção
- **THEN** a lista mostra apenas `backup_cech_2026-11-01.sql.zip`

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

### Requirement: Tela de backup no painel
O sistema SHALL mostrar em `/admin/database/backup` (e em `/admin/database`) o panorama do banco — nome, versão do servidor, número de tabelas, linhas estimadas e tamanho de dados e índices, com o detalhe por tabela — e a lista de backups com tamanho e data, com as ações de baixar, restaurar e excluir. A tela SHALL ser acessível apenas a administradores.

#### Scenario: Abrir a tela
- **WHEN** um administrador abre `/admin/database/backup`
- **THEN** vê o panorama do banco e os backups existentes, do mais recente para o mais antigo

### Requirement: Geração de backup pelo painel com progresso
O sistema SHALL gerar um backup pelo painel de duas formas: em `GET /admin/database/backup/stream`, transmitindo o progresso como eventos (Server-Sent Events) com os estados `starting`, `dumping_table`, `compressing`, `completed` ou `error`; e em `POST /admin/database/backup/generate`, que gera o arquivo e volta à tela com uma mensagem de sucesso ou de erro. Nas duas formas a compactação em zip SHALL ser o padrão.

#### Scenario: Geração com terminal ao vivo
- **WHEN** o administrador clica em "Iniciar Exportação (.sql.zip)"
- **THEN** a tela mostra o progresso tabela por tabela (tabela atual, percentual e linhas processadas) e, no fim, o nome, os tamanhos, a duração e o link para baixar o arquivo gerado

#### Scenario: Erro durante o dump
- **WHEN** a geração falha no meio
- **THEN** o fluxo de eventos envia `error` com a mensagem

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

### Requirement: Exclusão de backup
O sistema SHALL excluir um backup em `POST /admin/database/backup/delete/{arquivo}` somente com o token CSRF próprio daquele arquivo, aplicando as mesmas regras de nome e pasta do download, e SHALL informar o sucesso ou a falha.

#### Scenario: Token inválido
- **WHEN** a exclusão chega sem o token CSRF do arquivo
- **THEN** nada é excluído e a tela mostra "Token de segurança inválido."

### Requirement: Restauração pelo painel
O sistema SHALL restaurar o banco a partir de um backup da pasta (`POST /admin/database/backup/restore/{arquivo}`) ou de um arquivo enviado (`POST /admin/database/backup/upload-restore`), ambos com token CSRF; o envio MUST aceitar só `.sql`, `.zip` e `.gz`, com o formato lido do nome original. A sessão SHALL ser encerrada; sem erros, o login mostra a restauração concluída; com comandos SQL que falharam, mostra o aviso de restauração incompleta com a quantidade.

#### Scenario: Restaurar um backup da lista
- **WHEN** o administrador confirma a restauração de um backup da lista
- **THEN** o banco passa a ter os dados do backup, a sessão é encerrada e o navegador vai para `/login?restored=1`, onde a tela avisa que a base foi restaurada e pede o login com as credenciais do banco importado

#### Scenario: Envio de arquivo com extensão não aceita
- **WHEN** o administrador envia `dados.txt` para restaurar
- **THEN** nada é restaurado e a tela informa que só são permitidos `.sql`, `.zip` ou `.gz`

#### Scenario: Envio de um .sql
- **WHEN** o administrador envia um arquivo `.sql` gerado pelo portal
- **THEN** o banco é restaurado a partir dele e a sessão é encerrada

#### Scenario: Envio de um .zip
- **WHEN** o administrador envia `backup.sql.zip`
- **THEN** o SQL de dentro do zip é restaurado, embora o arquivo temporário do upload não tenha extensão

#### Scenario: Restauração com comandos que falharam
- **WHEN** alguns comandos do arquivo falham durante a restauração
- **THEN** o navegador vai para `/login?restored=parcial&falhas=<n>` e a tela avisa que a base pode ter ficado incompleta

### Requirement: Motor de restauração
Para um backup da pasta `var/backups` ou um caminho passado ao comando, o sistema SHALL reconhecer o formato pela extensão (ou pelo formato informado, no upload): de `.zip`, extrai o primeiro `.sql`; de `.gz`, descompacta; o temporário MUST ser removido. A importação SHALL usar o cliente `mysql`, com a senha em variável de ambiente, e, se ele faltar ou falhar, executar o SQL comando a comando, contando os que falham. No fim, SHALL apagar as páginas em cache e os cálculos da pesquisa.

#### Scenario: Servidor sem o cliente mysql
- **WHEN** a restauração roda num servidor sem o binário `mysql`
- **THEN** o SQL é executado pela aplicação, os comandos que falham são contados e a restauração só é informada como bem-sucedida se nenhum falhou

#### Scenario: Zip sem SQL
- **WHEN** o administrador restaura um `.zip` da pasta de backups que não contém nenhum arquivo `.sql`
- **THEN** a restauração falha com "Nenhum arquivo .sql encontrado dentro do pacote ZIP."

### Requirement: Comando de backup
O sistema SHALL oferecer `app:database:dump`, que mostra o panorama do banco, gera o superdump em `var/backups` com barra de progresso e informa arquivo, caminho, tamanhos, tabelas, linhas e duração. A opção `--no-zip` SHALL manter o `.sql` sem compactar, e `--filename` (`-f`) SHALL definir o nome do arquivo sem extensão. Em caso de erro, o comando MUST terminar com falha.

#### Scenario: Nome personalizado
- **WHEN** o administrador roda `app:database:dump --filename=antes_periodicos_2026-11-07`
- **THEN** o arquivo gerado é `var/backups/antes_periodicos_2026-11-07.sql.zip`

### Requirement: Comando de restauração
O sistema SHALL oferecer `app:database:restore [arquivo]`, que restaura a partir do caminho informado ou, se ele não existir como caminho, do arquivo com esse nome em `var/backups`; sem argumento ou com `--latest`, SHALL usar o backup mais recente da pasta. Antes de restaurar, em modo interativo, MUST pedir confirmação, salvo com `--force` (`-f`). Arquivo não encontrado, pasta vazia ou comando SQL que falhou MUST terminar com falha; recusar a confirmação SHALL cancelar sem alterar o banco.

#### Scenario: Restaurar o mais recente sem perguntar
- **WHEN** o administrador roda `app:database:restore --latest --force`
- **THEN** o banco é restaurado a partir do backup mais recente sem pedir confirmação

#### Scenario: Confirmação recusada
- **WHEN** o administrador roda `app:database:restore` e responde "n" à confirmação
- **THEN** o comando informa que a restauração foi cancelada e o banco não muda

#### Scenario: Restauração incompleta pelo terminal
- **WHEN** algum comando SQL do arquivo falha
- **THEN** o comando informa quantos falharam e os primeiros erros e termina com falha
