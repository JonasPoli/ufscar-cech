# admin-access Specification

## Purpose
Acesso ao painel administrativo do portal: login e logout, proteção de todas as rotas sob `/admin`, usuários e papéis, o comando de usuário administrador, o painel inicial com contagens e os relatórios institucionais.

## Requirements

### Requirement: Login por formulário
O sistema SHALL autenticar usuários em `/login` por nome de usuário e senha, com token CSRF obrigatório, mostrando em português a mensagem do erro de autenticação e o último usuário digitado. Após o login, o usuário SHALL ser levado à página protegida que tentou abrir ou, sem ela, ao painel `/admin/`. As senhas MUST ser guardadas como hash.

#### Scenario: Login válido
- **WHEN** um administrador informa usuário e senha corretos em `/login`
- **THEN** ele é autenticado e levado ao painel `/admin/`

#### Scenario: Senha errada
- **WHEN** alguém informa uma senha errada
- **THEN** o formulário volta com a mensagem de erro e o usuário digitado preenchido

#### Scenario: Mensagem em português
- **WHEN** alguém informa uma senha errada
- **THEN** a mensagem de erro aparece em português, como "Credenciais inválidas.", e não em inglês

#### Scenario: Envio sem token CSRF
- **WHEN** o formulário de login é enviado sem um token CSRF válido
- **THEN** a autenticação é recusada

### Requirement: Logout
O sistema SHALL encerrar a sessão do usuário em `/logout`.

#### Scenario: Sair do painel
- **WHEN** um administrador autenticado abre `/logout`
- **THEN** a sessão é encerrada e as rotas de `/admin` voltam a exigir login

### Requirement: Proteção das rotas do painel
Toda rota cujo caminho começa por `/admin` MUST exigir o papel `ROLE_ADMIN`. Visitante não autenticado MUST ser redirecionado para `/login`; usuário autenticado sem `ROLE_ADMIN` MUST receber acesso negado (403). As funções do painel (usuários, backup e restauração, cache, SEO, relatórios, indexação e cadastros) MUST NOT estar disponíveis em rotas fora de `/admin` sem credencial.

#### Scenario: Visitante anônimo
- **WHEN** um visitante sem sessão abre `/admin/database/backup/download`
- **THEN** ele é redirecionado para `/login` e nenhum arquivo é entregue

#### Scenario: Usuário só com ROLE_USER
- **WHEN** um usuário com papel `ROLE_USER` autenticado abre `/admin/users/`
- **THEN** o sistema responde 403

#### Scenario: Tela de teste sem login
- **WHEN** um visitante sem sessão abre `/admin/test/database` ou `/admin/super/test/fields`
- **THEN** a resposta é 404, porque essas telas não existem mais

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
O sistema SHALL oferecer o comando `app:admin-user [usuário] [senha]`, que lista os usuários existentes com seus papéis e cria o usuário informado ou, se ele já existir, redefine a senha dele. Em ambos os casos o usuário SHALL ficar com o papel `ROLE_ADMIN`. Sem argumentos, o comando SHALL perguntar o usuário (padrão `admin`) e a senha. O comando MUST NOT imprimir a senha nem o hash dela.

#### Scenario: Usuário novo
- **WHEN** o comando é executado com `novoadmin` e uma senha
- **THEN** o usuário `novoadmin` é criado com `ROLE_ADMIN` e consegue entrar no painel

#### Scenario: Usuário existente
- **WHEN** o comando é executado com um usuário que já existe e uma senha nova
- **THEN** a senha desse usuário é trocada e o papel passa a ser `ROLE_ADMIN`

#### Scenario: Saída sem segredos
- **WHEN** o comando termina
- **THEN** a saída não contém a senha informada nem o hash gravado

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

### Requirement: Elementos de administrador nas páginas públicas
As páginas públicas SHALL mostrar o botão "Painel Admin" somente a quem tem `ROLE_ADMIN`. Como essas páginas variam conforme o visitante, a resposta de um visitante com sessão MUST NOT ser servida a outros visitantes pelo cache de páginas.

#### Scenario: Administrador navegando no portal
- **WHEN** um administrador autenticado abre a página inicial
- **THEN** vê o botão "Painel Admin", e essa versão da página não é gravada no cache público

### Requirement: Sem telas de teste no painel
O painel MUST NOT expor telas de teste do gerador de CRUD. `/admin/test/database` e `/admin/super/test/fields`, com qualquer subcaminho, SHALL responder 404, e o banco MUST NOT ter as tabelas `test_database` e `super_test_fields`.

#### Scenario: Endereço antigo da tela de teste
- **WHEN** um administrador abre `/admin/test/database` ou `/admin/super/test/fields/new`
- **THEN** a resposta é 404

#### Scenario: Banco migrado
- **WHEN** as migrations são aplicadas
- **THEN** as tabelas `test_database` e `super_test_fields` não existem mais

### Requirement: Recusa de ações vindas de outro site
Os pedidos `POST`, `PUT`, `PATCH` e `DELETE` a rotas de `/admin`, e o `GET /admin/database/backup/stream`, MUST ser recusados com 403, sem efeito, quando trazem `Origin` de outro host, `Origin: null` ou `Sec-Fetch-Site: cross-site`. Sem nenhum desses cabeçalhos, o pedido SHALL seguir com as demais verificações.

#### Scenario: Formulário de outro site
- **WHEN** uma página de outro domínio faz o navegador do administrador logado enviar `POST /admin/cache/clear`
- **THEN** a resposta é 403 e o cache não é limpo

#### Scenario: Origem nula
- **WHEN** um `POST /admin/users/new` chega com `Origin: null`
- **THEN** a resposta é 403 e nenhum usuário é criado

#### Scenario: Backup disparado por outro site
- **WHEN** um `GET /admin/database/backup/stream` chega com `Sec-Fetch-Site: cross-site`
- **THEN** a resposta é 403 e nenhum backup é gerado

#### Scenario: Ação feita no próprio painel
- **WHEN** o administrador envia um formulário do painel, com `Origin` igual ao host do portal
- **THEN** a ação segue normalmente

#### Scenario: Cliente sem os cabeçalhos
- **WHEN** um `POST` a `/admin` chega sem `Origin` e sem `Sec-Fetch-Site`
- **THEN** a verificação de origem não o recusa, e valem o login, o papel e o token CSRF da ação

### Requirement: Limite de tentativas de login
O login SHALL aceitar no máximo 5 tentativas a cada 15 minutos para o mesmo usuário vindo do mesmo endereço. Acima disso, MUST recusar a autenticação, mesmo com a senha certa, e mostrar a mensagem de excesso de tentativas até o intervalo passar.

#### Scenario: Senhas erradas em sequência
- **WHEN** alguém erra a senha de `admin` 5 vezes em 10 minutos e tenta de novo com a senha certa
- **THEN** o login é recusado com a mensagem de excesso de tentativas

#### Scenario: Depois do intervalo
- **WHEN** passam 15 minutos desde as tentativas que bloquearam o login
- **THEN** o usuário consegue entrar com a senha certa

### Requirement: Tamanho mínimo da senha
Toda senha nova definida em `/admin/users/`, na criação ou na edição, MUST ter no mínimo 12 caracteres. Com menos, nada SHALL ser gravado e a tela SHALL informar o mínimo. Senha em branco na edição continua mantendo a atual.

#### Scenario: Senha curta na criação
- **WHEN** o administrador cria um usuário com a senha `abc12345`
- **THEN** o usuário não é criado e a tela informa que a senha precisa de pelo menos 12 caracteres

#### Scenario: Troca de senha na edição
- **WHEN** o administrador edita um usuário e informa uma senha nova de 14 caracteres
- **THEN** a senha é trocada
