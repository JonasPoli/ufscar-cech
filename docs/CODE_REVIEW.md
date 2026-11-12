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

### 1.6 ✅ Médio — CSRF ausente na maioria dos formulários administrativos (resolvido em 12/11/2026)

**Resolvido na revisão de 12/11/2026:** as ações que alteram dados em `/admin` passaram a recusar com 403 o pedido que vem de outro site (veja a seção "Revisão de 12/11/2026"). A verificação é por origem, não por token em cada formulário. O texto original do achado segue abaixo.

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

### 2.4 ⏳ Baixo — Cache público não é invalidado após reindexação (resolvido em parte)

A restauração de backup limpa `var/cache/public_pages`. A importação de currículo pelo admin e pelo navegador também limpa
(`CurriculumPostImportService`). Continua sem limpar o `app:import:lattes` pelo terminal; quem limpa é o
`bin/import-curriculos.sh`, no fim. Com TTL de 30 dias, dados novos podem demorar a aparecer no portal. Sugestão: chamar
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

### 3.4 ✅ Código de scaffolding em produção (resolvido em 12/11/2026)

Resolvido: as telas de teste saíram do admin e a migration `Version20261112160000` removeu as tabelas `test_database` e `super_test_fields`. Texto original:

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

**12/11/2026:** a rota coringa de download de backup foi removida (arquivo inexistente responde 404) e o CORS de `/api/` ficou só com GET, POST e OPTIONS. Seguem pendentes a allowlist de origens, o `server_version` e o `phpunit.xml.dist`.

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

---

## 5. Revisão de 12/11/2026

### O incidente

Em 12/11/2026 o disco da produção (49 GB) encheu. O cache de páginas (`var/cache/public_pages`) tinha 26 GB em 23.209 arquivos. Robôs de IA (PerplexityBot, Amazonbot) varreram `/explorar/assunto/*` e `/professor/{slug}?tema=...`, e cada `?tema=` gravava outra cópia do perfil inteiro (de 1,5 a 6 MB). Com o disco cheio, o MySQL não gravava tabelas temporárias, e `/sitemap.xml`, `/api/v1/metricas` e `/projetos?q=` davam erro 500. O cache foi limpo com `app:cache:clear` e 26 GB voltaram a ficar livres.

### O que foi corrigido

1. **Cache de páginas.** Os arquivos são gravados em gzip (`<md5>.html.gz`). A chave usa o endereço canônico, com os parâmetros ordenados. No perfil do docente, fora da aba `aba=busca`, os parâmetros não entram na chave: há uma só cópia por docente. Há um teto de espaço, `PAGE_CACHE_MAX_MB` (padrão 2048 MB), com descarte automático das páginas vencidas e das mais antigas (até 80% do teto), em média a cada 200 gravações. Página acima de 16 MB não é gravada. `php bin/console app:cache:clear --prune` roda só o descarte. A página em cache é servida por `public/index.php` antes do Symfony (`App\Support\PageCacheFastPath`), com `ETag`, `Last-Modified`, `Cache-Control: no-cache` e resposta 304. O arquivo marcador `.enabled`, na pasta do cache, autoriza esse atalho e só existe com `PAGE_CACHE_ENABLED` ligado.
2. **Freio para robôs.** Fora do cache, cada robô (nome no User-Agent) pode pedir até 30 páginas por minuto. Acima disso recebe 429 com `Retry-After: 60`. Os contadores ficam em `var/cache/bot_throttle`. `robots.txt` e `sitemap.xml` ficam de fora.
3. **`robots.txt`.** O padrão proíbe `/busca`, `/api/`, fragmentos e exportações, e as URLs com os parâmetros `tema`, `aba`, `modo`, `ordem`, `ligacoes`, `pares`, `docente`, `t` e `q`. Tem `Crawl-delay: 5`. `robots.txt` e `sitemap.xml` saem com `Cache-Control` de 1 hora.
4. **Banco.** A migration `Version20261112150000` cria em `production_items` as colunas geradas (STORED) `work_key` (MD5 da obra única: DOI ou tipo + ano + título) e `keywords_text` (palavras-chave de `extra_data.keywords`), e os índices `idx_prod_work_key` e `idx_prod_researcher_work`. Em `production_authors` cria `idx_pa_item_matched` e `idx_pa_matched_item`. O dado do Lattes não muda. A pesquisa pública (`ResearchSql`) usa essas colunas. O backup em SQL deixa as colunas geradas fora dos INSERTs. A migration `Version20261112160000` remove as tabelas das telas de teste do gerador de CRUD.
5. **Desempenho.** `/departamento/{codigo}` conta as produções numa consulta. O resumo global dos indicadores fica no cache da pesquisa (`cache.research`). A aba Redes calcula os pares uma vez. Os docentes em destaque da home não usam mais produto cartesiano. `/api/temas/*` sai com `Cache-Control` de 1 hora. Medidas locais a frio: `/api/v1/metricas` de 2,6 s para 1,2 s; `/busca?q=educacao` de 4,0 s para 1,5 s; `/explorar/assunto/{slug}` de 1,85 s para 0,82 s.
6. **Segurança.** Ações que alteram dados em `/admin` (e `GET /admin/database/backup/stream`) são recusadas com 403 quando vêm de outro site (`Origin` de outro host ou `Sec-Fetch-Site: cross-site`). O login tem limite de 5 tentativas em 15 minutos. A senha nova exige no mínimo 12 caracteres. Foram acrescentados os cabeçalhos `Referrer-Policy`, `X-Content-Type-Options`, `X-Frame-Options` e HSTS. O CORS de `/api/` aceita só GET, POST e OPTIONS. A rota coringa de download de backup saiu.
7. **Importação e comandos.** A importação por HTML roda em transação. O tesauro de autores sempre religa as chaves estrangeiras. Os comandos de escrita em lote têm trava: a segunda execução simultânea sai avisando. `bin/publish-journals.sh` limpa o cache de páginas com `app:cache:clear`. O XML é lido sem acesso à rede. Os avisos de produção vão para `var/log/prod-avisos.log`.
8. **Dependências.** Symfony 7.2 para 7.4 LTS, Twig e PhpSpreadsheet atualizados, `symfony/rate-limiter` e `symfony/lock` incluídos (`LOCK_DSN=flock` no `.env`).

### O que ficou pendente

- Shoelace carregado de CDN e falta de SRI nos scripts.
- Uploads, `node_modules` e `composer.phar` no Git (o deploy é por `git pull`).
- Rotacionar a chave do TinyMCE, que ficou no histórico do Git.
- `server_version` do Doctrine e credenciais no `.env.test`.
- Chaves estrangeiras de `qualis_journal_academic_database` fora do padrão: `doctrine:schema:validate` nunca fica verde.
- TLS desligado no crawler de fotos do CNPq.
- `trusted_hosts` e `trusted_proxies`.
- Allowlist de origens no CORS.
- Limite de taxa no nginx, além do freio da aplicação.
- `opcache.max_accelerated_files` baixo (7963) e sem preload.
- `StatisticsService` com 2 mil linhas.
- `/busca` sem cache de resultado.
- Aquecimento do cache depois de limpar: a primeira visita a cada página volta a ser lenta.
- Servidor de 1 vCPU, 2 GB de RAM e 50 GB de disco, dividido com outros aplicativos.
