## MODIFIED Requirements

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

## REMOVED Requirements

### Requirement: Telas de teste do gerador de CRUD
**Reason**: As telas `/admin/test/database` e `/admin/super/test/fields` eram código de demonstração do gerador de CRUD, sem uso no portal, e ampliavam a superfície de ataque do painel.
**Migration**: Nada a migrar: nenhum recurso depende delas. A migration `Version20261112160000` apaga as tabelas `test_database` e `super_test_fields`.

## ADDED Requirements

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
