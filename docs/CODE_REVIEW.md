# Code Review — 11/09/2026

Revisão completa do código (`src/`, `templates/`, `config/`, `tests/`, `scripts/`) a partir do commit
`b95a0a5`. Cada achado indica severidade e se já foi corrigido nesta rodada.

Legenda: **✅ corrigido** · **⏳ pendente** (decisão ou trabalho do time).

---

## 1. Segurança

### 1.1 ✅ Crítico — APIs públicas de escrita sem autenticação

`POST /api/photo/save` e `POST /api/curriculum/import-html` (`src/Controller/pub/PhotoApiController.php`)
ficavam fora do firewall (`access_control` cobre apenas `^/admin`) e com CORS liberado para qualquer
origem. Qualquer pessoa na internet podia **sobrescrever a foto de qualquer docente** e **injetar ou
substituir um currículo inteiro** enviando HTML arbitrário — inclusive apagando produções, já que o
parser regrava o currículo.

**Correção:** criado `App\Service\Security\SyncTokenProvider`. Os dois endpoints agora exigem o
cabeçalho `X-Cech-Sync-Token` (ou `token` no corpo), comparado com `hash_equals`. O token é derivado do
`APP_SECRET` via HMAC — não exige variável de ambiente nova e é rotacionado ao trocar o segredo.
O bookmarklet e o script de console em `/admin/curriculum/new` já são renderizados com o token
preenchido; `scripts/browser_lattes_crawler.js` ganhou a constante `CECH_SYNC_TOKEN`.

### 1.2 ✅ Alto — Upload de foto sem validação de conteúdo

`savePhoto`, `AdminCurriculumController::quickPhoto` e `assignUploadedPhoto` gravavam o binário
recebido direto em `public/uploads/photos/*.jpg`, sem verificar se era imagem — bastava trocar a
extensão adivinhada para depositar arquivo arbitrário dentro do *document root*.

**Correção:** os três caminhos passam por `LattesPhotoCrawlerService::storeBinaryPhoto()`, que lê o
MIME-type do próprio conteúdo (`finfo`), aceita apenas JPEG/PNG/GIF/WebP, converte GIF/WebP para JPEG
e gera as variantes `.webp`/`-256.webp`. Isso também elimina três blocos duplicados de gravação de foto.

### 1.3 ✅ Alto — Cache de página servia HTML do administrador ao público

`PageCacheService` gravava qualquer resposta HTML 200 de rota pública, inclusive as renderizadas para
um administrador logado — e `templates/pub/base.html.twig` inclui blocos `is_granted('ROLE_ADMIN')`.
O primeiro acesso de um admin à home fixava por **30 dias** uma página com os elementos do painel
para todos os visitantes. A chave de cache era `md5(URI)` com a query string, então `/busca?q=<aleatório>`
também permitia encher o disco com um arquivo por consulta.

**Correção:** requisições com cookie de sessão não são lidas nem gravadas; respostas que definem
cookies não são gravadas (`isCacheableResponse()`); `/api` e `/busca` entraram na lista de exclusão.

### 1.4 ✅ Médio — Senha do MySQL na linha de comando

`DatabaseBackupService::importDatabase()` montava `mysql -p<senha>`, exposta em `ps aux` para qualquer
usuário do servidor durante a restauração. **Correção:** a senha vai por `MYSQL_PWD` no ambiente do
processo; o `stderr` do cliente nativo passou a ser reportado em `nativeError` em vez de descartado.

### 1.5 ✅ Médio — Criação de usuários sem CSRF e com role livre

`AdminUserController::new/edit` aceitava qualquer string como role (`$request->request->get('role')`)
e não validava token CSRF. **Correção:** whitelist `ROLE_ADMIN`/`ROLE_USER` e token `user_form`
validado nas duas ações (campo adicionado em `templates/admin/user/form.html.twig`).

### 1.6 ⏳ Médio — CSRF ausente na maioria dos formulários administrativos

Cerca de 30 ações POST em `/admin` gravam dados sem `isCsrfTokenValid` (tesauros de autores, países,
instituições, periódicos, bases acadêmicas, importações, SEO, indexação). O cookie de sessão do Symfony
usa `SameSite=Lax` por padrão, o que bloqueia o ataque nos navegadores atuais, mas é a única barreira.
Recomendação: adotar Symfony Forms (CSRF automático) nesses CRUDs ou repetir o par
`csrf_token()` + `isCsrfTokenValid()`.

### 1.7 ⏳ Baixo — Segredos e endereços no repositório

- `.env` versionado traz `APP_SECRET` de desenvolvimento — aceitável se produção sobrescrever via
  `.env.local`; confirme que sobrescreve, porque esse valor assina a sessão **e agora o token de sincronização**.
- `.env.test` versionado traz `DATABASE_URL` com usuário e senha reais do MySQL local. Mover para
  `.env.test.local`.
- `README.md`, `AGENTS.md` e `docs/DEPLOY_E_PRODUCAO.md` publicam IP, usuário SSH e caminho do servidor
  de produção. Se o repositório for (ou vier a ser) público, isso é reconhecimento pronto para um atacante.

---

## 2. Bugs

### 2.1 ✅ Alto — Sincronização pelo bookmarklet sempre terminava em erro

`PhotoApiController::importHtml()` chamava `crawlPhoto($researcher->getIdLattes())` — string — mas o
método recebe `Researcher` e devolve `?string`. Todo currículo importado **sem foto** disparava
`TypeError`, capturado pelo `catch (\Throwable)`, devolvendo HTTP 500 com "Erro ao processar HTML"
*depois* de já ter gravado o currículo. Para o operador, a importação parecia ter falhado.
**Correção:** chamada com a entidade; o próprio serviço grava a foto e faz `flush`.

### 2.2 ✅ Alto — Migration com `CREATE TABLE IF NOT EXISTS` deixou o esquema divergente

`Version20260830015144` cria `academic_database` com `IF NOT EXISTS`. Em qualquer banco onde a tabela
já existia, a coluna `list_download_url` nunca foi criada — e a entidade `AcademicDatabase` a mapeia.
Resultado: **`/admin/academic-databases` e `/admin/journals` respondiam 500** ("Unknown column
't0.list_download_url'"), tanto em desenvolvimento quanto no banco de testes.

**Correção:** `migrations/Version20260911120000.php` adiciona a coluna quando ausente (com `skipIf`
para instalações que já a tenham), aplicada em `cech` e `cech_test`.
**Ação necessária em produção:** rodar `doctrine:migrations:migrate --env=prod`.
**Regra para o futuro:** `IF NOT EXISTS` em migration esconde divergência de esquema — evitar.

### 2.3 ⏳ Baixo — README descreve comandos que não existem

`app:index:curriculums` e `app:index-thematic-terms` não são nomes válidos. Os comandos reais são
`app:curriculum:normalize` e `app:index:topics` (alias `app:index:thematic-terms`). Corrigido em
[`VISAO_GERAL_DO_SISTEMA.md`](VISAO_GERAL_DO_SISTEMA.md); o `README.md` ainda precisa de ajuste.

### 2.4 ⏳ Baixo — Cache público não é invalidado após reindexação

A restauração de backup limpa `var/cache/public_pages`, mas importar currículos ou reindexar não limpa.
Com TTL de 30 dias, dados novos podem demorar a aparecer no portal. Sugestão: chamar
`PageCacheService::clearCache()` ao final dos comandos de importação/indexação.

---

## 3. Qualidade e manutenção

### 3.1 ✅ Testes que nunca rodavam

Três testes apontavam para `/Users/jonaspoli/work/html/ufscar-cech/docs/banco/...` — caminho absoluto
de outra máquina, resultando em erro (não em *skip*). Agora resolvem o caminho a partir do projeto e
são pulados quando `docs/banco/` (fora do versionamento) não está presente.

### 3.2 ✅ Suíte de testes verde

Antes: 89 testes, **18 erros e 7 falhas** — quase todos por `cech_test` sem as migrations aplicadas.
Depois de migrar o banco de teste e aplicar as correções: **94 testes, 557 asserções, 0 falhas**,
3 pulados (dependem de `docs/banco/`) e 1 *risky*.

O *risky* remanescente é `AdminDatabaseBackupControllerTest::testAdminDatabaseBackupGenerateAndDownload`:
`AdminDatabaseBackupController::download()` chama `ob_end_clean()` e fecha um buffer aberto pelo PHPUnit.

### 3.3 ✅ Duplicação removida

- Cabeçalhos CORS eram repetidos em oito respostas do `PhotoApiController`, embora `CorsListener` já os
  adicione a todas as respostas `/api/*`; o mesmo valia para o tratamento manual de `OPTIONS`.
- Três implementações diferentes de "decodificar base64 e salvar foto" convergiram em `storeBinaryPhoto()`.
- Removidos `$settings` sem uso em `SeoController::sitemap`, `$result` sem uso em duas ações de
  restauração e um `use` órfão de `JsonResponse`.

### 3.4 ⏳ Código de scaffolding em produção

`TestDatabaseController`, `SuperTestFieldsController`, entidades `TestDatabase`/`SuperTestFields` e
seus templates são resíduos do gerador de CRUD, expostos em `/admin/test/database` e
`/admin/super/test/fields`. O template `super_test_fields/show.html.twig` imprime conteúdo com `|raw`.
Estão protegidos por `ROLE_ADMIN`, mas deveriam sair do repositório.

### 3.5 ⏳ Classes grandes demais

`StatisticsService` (2.074 linhas), `GenerateIndexCommand` (1.709), `LattesHtmlParserService` (1.006),
`LattesXmlParserService` (999) e `ProfessorController` (750). O caso mais crítico é o
`StatisticsService`: concentra as 18 figuras em SQL bruto, sem cache de resultado — daí o
`ini_set('memory_limit', '512M')` espalhado pelos controllers. Sugestão: quebrar por bloco temático
(corpo docente, formação, produção, redes) e materializar os agregados mais caros.

### 3.6 ⏳ Arquivos que não deveriam estar no Git

`.DS_Store`, `docs/.DS_Store` e `scratch_lattes_415.html` (192 KB, cópia de um currículo real com dados
pessoais) foram **removidos do índice** nesta rodada (permanecem em disco) e `.gitignore` foi atualizado.
`composer.phar` (3,5 MB) segue versionado — a linha correspondente no `.gitignore` está comentada;
decidir se é intencional.

### 3.7 ⏳ Observações menores

- `AdminDatabaseBackupController::download()` tem três rotas com `requirements: ['filename' => '.+']`,
  incluindo `/admin/database/{filename}` como *catch-all* — qualquer GET não previsto sob
  `/admin/database` vira download do backup mais recente. O path traversal está barrado em
  `getBackupFile()` (`basename` + regex), mas o roteamento é confuso e mascara 404 legítimos.
- `CorsListener` reflete qualquer `Origin` em `/api/*`. Com o token exigido isso deixa de ser
  explorável, mas o ideal é uma allowlist (`buscatextual.cnpq.br`, `lattes.cnpq.br` e o próprio domínio).
- `config/packages/doctrine.yaml` não fixa `server_version`, gerando 60+ avisos de depreciação do DBAL
  na suíte. Declarar `8.0.xx` resolve.
- `vendor/liip/imagine-bundle` tem divergência de maiúsculas (`Config/` vs `config/`) que derruba
  comandos do console em modo debug nesta máquina — contornável com `APP_DEBUG=0`, resolvido de vez
  com um `composer install` limpo.
- `phpunit.xml.dist` ainda usa o esquema do PHPUnit 9 (`convertDeprecationsToExceptions`, `<listeners>`).

---

## 4. O que fazer em seguida (sugestão de ordem)

1. Aplicar a migration em produção (`2.2`) e confirmar que `/admin/academic-databases` volta a abrir.
2. Publicar o novo bookmarklet a partir de `/admin/curriculum/new` (o antigo, sem token, passa a receber 401).
3. Conferir se produção sobrescreve `APP_SECRET` em `.env.local` (`1.7`).
4. Adicionar CSRF aos formulários administrativos restantes (`1.6`).
5. Limpar o scaffolding de teste (`3.4`) e revisar o cache pós-importação (`2.4`).
