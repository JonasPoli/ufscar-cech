# admin-access Specification

## Purpose
Acesso ao painel administrativo do portal: login e logout, proteção de todas as rotas sob `/admin`, usuários e papéis, o comando de usuário administrador, o painel inicial com contagens, os relatórios institucionais e as telas de teste geradas pelo gerador de CRUD.

## Requirements

### Requirement: Login por formulário
O sistema SHALL autenticar usuários em `/login` por nome de usuário e senha, com token CSRF obrigatório, mostrando a mensagem do erro de autenticação e o último usuário digitado. Após o login, o usuário SHALL ser levado à página protegida que tentou abrir ou, sem ela, ao painel `/admin/`. As senhas MUST ser guardadas como hash.

#### Scenario: Login válido
- **WHEN** um administrador informa usuário e senha corretos em `/login`
- **THEN** ele é autenticado e levado ao painel `/admin/`

#### Scenario: Senha errada
- **WHEN** alguém informa uma senha errada
- **THEN** o formulário volta com a mensagem de erro e o usuário digitado preenchido

#### Scenario: Envio sem token CSRF
- **WHEN** o formulário de login é enviado sem um token CSRF válido
- **THEN** a autenticação é recusada

### Requirement: Logout
O sistema SHALL encerrar a sessão do usuário em `/logout`.

#### Scenario: Sair do painel
- **WHEN** um administrador autenticado abre `/logout`
- **THEN** a sessão é encerrada e as rotas de `/admin` voltam a exigir login

### Requirement: Proteção das rotas do painel
Toda rota cujo caminho começa por `/admin` MUST exigir o papel `ROLE_ADMIN`, incluindo as telas de teste. Visitante não autenticado MUST ser redirecionado para `/login`; usuário autenticado sem `ROLE_ADMIN` MUST receber acesso negado (403). As funções do painel (usuários, backup e restauração, cache, SEO, relatórios, indexação e cadastros) MUST NOT estar disponíveis em rotas fora de `/admin` sem credencial.

#### Scenario: Visitante anônimo
- **WHEN** um visitante sem sessão abre `/admin/database/backup/download`
- **THEN** ele é redirecionado para `/login` e nenhum arquivo é entregue

#### Scenario: Usuário só com ROLE_USER
- **WHEN** um usuário com papel `ROLE_USER` autenticado abre `/admin/users/`
- **THEN** o sistema responde 403

#### Scenario: Tela de teste sem login
- **WHEN** um visitante sem sessão abre `/admin/test/database` ou `/admin/super/test/fields`
- **THEN** ele é redirecionado para `/login`

### Requirement: Papéis de usuário
O sistema SHALL reconhecer os papéis `ROLE_ADMIN` (acesso ao painel) e `ROLE_USER` (sem acesso ao painel). Todo usuário SHALL ter `ROLE_USER` implicitamente, além do papel gravado. Não há hierarquia nem outros papéis.

#### Scenario: Papel implícito
- **WHEN** um usuário tem gravado apenas `ROLE_ADMIN`
- **THEN** os papéis dele são `ROLE_ADMIN` e `ROLE_USER`

### Requirement: Gestão de usuários do painel
O sistema SHALL oferecer em `/admin/users/` a lista de usuários e as ações de criar (`/admin/users/new`), editar (`/admin/users/{id}/edit`) e excluir (`POST /admin/users/{id}/delete`), todas com validação de token CSRF. A criação SHALL exigir usuário e senha não vazios e o nome MUST ser único; na edição, senha em branco SHALL manter a atual. O papel SHALL ser `ROLE_ADMIN` ou `ROLE_USER` (outro valor vira `ROLE_USER`). A exclusão MUST NOT permitir excluir o usuário autenticado.

#### Scenario: Papel forjado
- **WHEN** o formulário de criação é enviado com o papel `ROLE_SUPER_ADMIN`
- **THEN** o usuário é criado com `ROLE_USER`

#### Scenario: Excluir a si mesmo
- **WHEN** o administrador tenta excluir o próprio usuário
- **THEN** a exclusão é recusada com a mensagem "Você não pode excluir o usuário com o qual está autenticado."

#### Scenario: Edição sem senha
- **WHEN** o administrador edita um usuário trocando só o nome e deixando a senha em branco
- **THEN** o nome muda e a senha continua a mesma

#### Scenario: Nome já usado
- **WHEN** o administrador cria ou renomeia um usuário com o nome de outro
- **THEN** a tela mostra "Já existe um usuário …" e nada é gravado, sem erro 500

### Requirement: Comando de usuário administrador
O sistema SHALL oferecer o comando `app:admin-user [usuário] [senha]`, que lista os usuários existentes com seus papéis e cria o usuário informado ou, se ele já existir, redefine a senha dele. Em ambos os casos o usuário SHALL ficar com o papel `ROLE_ADMIN`. Sem argumentos, o comando SHALL perguntar o usuário (padrão `admin`) e a senha.

#### Scenario: Usuário novo
- **WHEN** o comando é executado com `novoadmin` e uma senha
- **THEN** o usuário `novoadmin` é criado com `ROLE_ADMIN` e consegue entrar no painel

#### Scenario: Usuário existente
- **WHEN** o comando é executado com um usuário que já existe e uma senha nova
- **THEN** a senha desse usuário é trocada e o papel passa a ser `ROLE_ADMIN`

### Requirement: Painel inicial
O sistema SHALL mostrar em `/admin/` as contagens de docentes, produções, países, instituições e autores do tesauro, os cinco docentes cadastrados por último e os seis departamentos com mais docentes de vínculo vigente, além do menu para as seções do painel (currículos, importação, indexação, periódicos, fila de nomes, tesauro de categorias, bases acadêmicas, países, instituições, autores, relatórios, SEO, cache, backup e usuários).

#### Scenario: Abertura do painel
- **WHEN** um administrador abre `/admin/`
- **THEN** vê as cinco contagens, os últimos docentes e os principais departamentos

### Requirement: Relatórios institucionais
O sistema SHALL mostrar em `/admin/reports/` a produção por departamento (até 50, com docentes de vínculo vigente e suas produções), a distribuição dos artigos por estrato Qualis e a contagem de produções por tipo. SHALL exportar os relatórios de departamentos (`/admin/reports/departments/export/{format}`) e Qualis (`/admin/reports/qualis/export/{format}`): `json` gera um anexo JSON, e qualquer outro formato gera um anexo CSV em UTF-8 com BOM.

#### Scenario: Exportar departamentos em CSV
- **WHEN** o administrador abre `/admin/reports/departments/export/csv`
- **THEN** baixa `relatorio_departamentos.csv` com código, departamento, total de docentes e total de produções

#### Scenario: Exportar Qualis em JSON
- **WHEN** o administrador abre `/admin/reports/qualis/export/json`
- **THEN** baixa `relatorio_qualis.json` com o total de artigos por estrato

### Requirement: Telas de teste do gerador de CRUD
O sistema SHALL manter as telas de teste do gerador de CRUD em `/admin/test/database` e `/admin/super/test/fields` (listar, criar, ver, editar e excluir registros de teste, com exclusão protegida por token CSRF) acessíveis só a administradores. Essas telas MUST NOT aparecer no menu do painel, e nenhum recurso do portal depende delas.

#### Scenario: Menu do painel
- **WHEN** um administrador abre qualquer página do painel
- **THEN** o menu não tem link para as telas de teste

### Requirement: Elementos de administrador nas páginas públicas
As páginas públicas SHALL mostrar o botão "Painel Admin" somente a quem tem `ROLE_ADMIN`. Como essas páginas variam conforme o visitante, a resposta de um visitante com sessão MUST NOT ser servida a outros visitantes pelo cache de páginas.

#### Scenario: Administrador navegando no portal
- **WHEN** um administrador autenticado abre a página inicial
- **THEN** vê o botão "Painel Admin", e essa versão da página não é gravada no cache público
