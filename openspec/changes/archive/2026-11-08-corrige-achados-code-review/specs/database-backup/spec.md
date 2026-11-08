## MODIFIED Requirements

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
