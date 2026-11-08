## MODIFIED Requirements

### Requirement: Gestão de usuários do painel
O sistema SHALL oferecer em `/admin/users/` a lista de usuários e as ações de criar (`/admin/users/new`), editar (`/admin/users/{id}/edit`) e excluir (`POST /admin/users/{id}/delete`), todas com validação de token CSRF. A criação SHALL exigir usuário e senha não vazios e o nome MUST ser único; na edição, senha em branco SHALL manter a atual. O papel SHALL ser `ROLE_ADMIN` ou `ROLE_USER` (outro valor MUST virar `ROLE_USER`; ausente, vale `ROLE_ADMIN`). A exclusão MUST NOT permitir excluir o usuário autenticado.

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
