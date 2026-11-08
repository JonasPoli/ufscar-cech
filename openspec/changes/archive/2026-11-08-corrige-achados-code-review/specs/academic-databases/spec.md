## MODIFIED Requirements

### Requirement: Cadastro de bases de indexação
A tela `/admin/academic-databases`, só para `ROLE_ADMIN`, SHALL listar as bases com a quantidade de revistas ligadas e busca, e permitir criar e editar nome, sigla, URL, URL da lista, descrição, formatos, colunas de assinatura e logotipo, com token CSRF. O logotipo MUST ser PNG, JPG, WebP, GIF ou SVG pelo conteúdo e vai para `/images/databases/`. A sigla SHALL ser gravada em minúsculas e MUST ser única. A exclusão, por POST com CSRF, remove a base e os seus vínculos.

#### Scenario: Sigla repetida
- **WHEN** o administrador cadastra uma base com a sigla de outra
- **THEN** aparece "Já existe uma base de indexação com a sigla …" e nada é gravado

#### Scenario: Revistas de uma base
- **WHEN** o administrador clica em ver as revistas de uma base
- **THEN** abre a lista de periódicos filtrada pela sigla da base (`/admin/journals/?database=<sigla>`)

#### Scenario: Logotipo que não é imagem
- **WHEN** o administrador envia como logotipo um arquivo PHP com nome `.png`
- **THEN** nada é gravado e a tela informa os tipos aceitos

## ADDED Requirements

### Requirement: Exclusão de periódico no admin
Ao excluir um periódico em `/admin/journals` (POST com o token do periódico), o sistema SHALL tirar dos artigos ligados a ele o vínculo, o selo Qualis e as bases, que vinham da revista excluída, e limpar o cache de páginas e o do resolvedor de periódicos.

#### Scenario: Artigo de revista excluída
- **WHEN** o administrador exclui uma revista com artigos ligados e Qualis A1
- **THEN** esses artigos ficam sem periódico, sem selo Qualis e sem bases até a próxima indexação, e a mensagem informa quantos foram afetados
