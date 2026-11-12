## Why

Em 12/11/2026 o disco da produção (49 GB) chegou a 100%. O cache de páginas (`var/cache/public_pages`) ocupava 26 GB em 23.209 arquivos. Desde 07–08/11, robôs de IA (PerplexityBot, 43,7 mil requisições; Amazonbot, 24 mil) varreram `/explorar/assunto/*` (37,5 mil requisições, 8 mil URLs distintas) e `/professor/{slug}?tema=...` (13 mil). Cada `?tema=` gravava outra cópia do HTML inteiro do perfil, de 1,5 a 6 MB, embora o parâmetro só seja lido pelo JavaScript. Em 08/11 foram 42,6 mil requisições: 36 mil encerradas pelo cliente (499) e 953 com 504. Com o disco cheio, o MySQL não gravava temporários, e `/sitemap.xml`, `/api/v1/metricas` e `/projetos?q=` respondiam 500. O cache foi limpo à mão (26 GB liberados). A produção estava 5 commits atrás do `main`, sem as correções de 07/11.

O incidente mostrou três fraquezas. O cache não tem teto nem formato compacto. Qualquer robô gera páginas sem limite. E as consultas da pesquisa são lentas demais para o servidor (1 vCPU, 2 GB): a frio, a home leva 13 s, `/api/v1/metricas` 26 s e `/busca` de 2 a 5 s. A revisão de 12/11 também achou falhas de segurança no painel e nos comandos de lote.

## What Changes

**Cache de páginas**
- As páginas são gravadas em gzip (`<md5>.html.gz`). Quem aceita gzip recebe o arquivo como está; os demais recebem o HTML descompactado.
- A chave passa a ser o endereço canônico, com os parâmetros em ordem. No perfil do docente, fora de `aba=busca`, os parâmetros não entram na chave: uma só cópia do perfil para qualquer `?tema=`. O campo de filtro do perfil passa a ser preenchido só pelo JavaScript.
- Teto de espaço `PAGE_CACHE_MAX_MB` (padrão 2048 MB). Em média a cada 200 gravações, o descarte apaga as páginas vencidas e, acima do teto, as mais antigas até sobrar 80% dele. Página com mais de 16 MB de HTML não é gravada. `app:cache:clear --prune` roda só o descarte. `/admin/cache` mostra o teto.
- A página em cache é servida por `public/index.php` antes do Symfony, só para GET/HEAD sem cookie de sessão e com o marcador `.enabled` na pasta. A resposta traz `X-Page-Cache: HIT`, `ETag`, `Last-Modified`, `Cache-Control: no-cache` e `Vary: Accept-Encoding`, e responde 304 a um `If-None-Match` igual.
- A validade de 30 dias e as regras de elegibilidade continuam.

**Freio para robôs**
- Nas requisições que não saem do cache, cada robô (nome no User-Agent: bot, crawler, spider, slurp, scrapy) pode pedir até 30 páginas por minuto. Acima disso recebe 429 com `Retry-After: 60`, antes do Symfony. Visitantes comuns, páginas em cache, `/robots.txt` e `/sitemap.xml` nunca são limitados.

**SEO**
- O `robots.txt` padrão proíbe `/busca`, `/api/`, os fragmentos e as exportações do perfil, `/indicadores/fragment/` e as URLs com os parâmetros `tema`, `aba`, `modo`, `ordem`, `ligacoes`, `pares`, `docente`, `t` e `q`, e pede `Crawl-delay: 5`. O texto personalizado em `/admin/seo` continua com prioridade.
- `robots.txt` e `sitemap.xml` respondem com `Cache-Control: public, max-age=3600`.

**Desempenho das consultas**
- `production_items` ganha duas colunas geradas pelo MySQL (STORED), sem alterar dado do Lattes: `work_key` (MD5 da obra única) e `keywords_text` (palavras-chave como texto), com índices em `work_key` e `(researcher_id, work_key)`. `production_authors` ganha os índices `(production_item_id, matched_researcher_id)` e `(matched_researcher_id, production_item_id)`. Migration `Version20261112150000`.
- A pesquisa pública (busca, panorama, rede, Explorar e API) usa essas colunas em vez de calcular MD5 e extrair JSON linha a linha. As contagens de obras únicas continuam idênticas.
- O backup em SQL deixa as colunas geradas fora dos INSERTs, para que a restauração funcione.
- `/departamento/{codigo}` conta as produções dos docentes numa consulta só (antes: 110 consultas e 94 MB de memória).
- O resumo global dos indicadores (home, `/indicadores`, `/sobre-os-dados`) fica no cache da pesquisa por um dia. A aba Redes calcula os pares de coautoria uma vez, em vez de três.
- Os docentes em destaque da home são calculados sem produto cartesiano, na mesma ordem.
- As APIs de temas (`/api/temas/*`) respondem com `Cache-Control: public, max-age=3600`.
- Opcional: as listas de `/periodicos` por base ficam no cache da pesquisa.

Medições locais (modo prod, sem cache de página, antes → depois): `/api/v1/metricas` a frio 2,6 s → 1,2 s; `/busca?q=educacao` 4,0 s → 1,5 s; `/explorar/assunto/{slug}` 1,85 s → 0,82 s.

**Segurança**
- Ações que alteram dados em `/admin` (POST, PUT, PATCH, DELETE) e `GET /admin/database/backup/stream` são recusadas com 403 quando vêm de outro site: `Origin` de outro host, `Origin: null` ou `Sec-Fetch-Site: cross-site`.
- Login com limite de 5 tentativas em 15 minutos.
- Senha nova de usuário com no mínimo 12 caracteres.
- Cabeçalhos em todas as respostas: `Referrer-Policy: strict-origin-when-cross-origin`, `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN` e, em HTTPS, `Strict-Transport-Security`.
- CORS de `/api/` só com os métodos GET, POST e OPTIONS.
- **BREAKING** (painel): sai a rota coringa `/admin/database/{qualquer-coisa}`, e o download de um backup inexistente responde 404 em vez de entregar o mais recente.
- **BREAKING** (painel): saem as telas de teste do gerador de CRUD (`/admin/test/database` e `/admin/super/test/fields`) e as tabelas delas (migration `Version20261112160000`).
- Dependências: Symfony 7.2 (sem suporte) → 7.4 LTS; Twig 3.19 → 3.2x; PhpSpreadsheet atualizado; `symfony/rate-limiter` e `symfony/lock` incluídos; `composer audit` sem alertas nos pacotes de produção.

**Importação e comandos**
- A importação por HTML (painel e marcador) roda numa transação: uma falha no meio não apaga as produções anteriores do docente.
- A limpeza do tesauro de autores sempre religa a verificação de chaves estrangeiras, mesmo com erro.
- Os comandos de escrita em lote (importar currículos, importar o Repositório, indexar temas, normalizar currículos, sincronizar o tesauro de autores, pipeline de periódicos, restaurar o banco e indexar projetos) não rodam em duplicidade: a segunda execução simultânea sai avisando que o comando já está em execução.
- `bin/publish-journals.sh` limpa o cache de páginas com `app:cache:clear`.
- XML lido sem acesso à rede (`LIBXML_NONET`).
- Avisos de produção num arquivo próprio, `var/log/prod-avisos.log`, guardado por 14 dias.

## Capabilities

### New Capabilities

- `bot-throttling`: limite de páginas por minuto para cada robô nas requisições fora do cache.
- `research-query-performance`: chave da obra única e palavras-chave calculadas pelo banco, índices da coautoria e resultados iguais aos de antes.
- `batch-command-locking`: execução única dos comandos de escrita em lote (inclui `app:cache:warm`).
- `security-headers`: cabeçalhos de segurança HTTP em todas as respostas e hosts aceitos (`TRUSTED_HOSTS`).
- `frontend-assets`: recursos de terceiros com versão exata e integridade (SRI), `graph.js` só onde há rede de coautoria e imagens abaixo da dobra com `loading=lazy`.

### Modified Capabilities

- `page-cache`: gzip, endereço canônico, teto com descarte, entrega antes do Symfony, revalidação, marcador `.enabled` e aquecimento (`app:cache:warm`, `app:cache:clear --aquecer`).
- `seo`: `robots.txt` padrão mais restritivo e `Cache-Control` em `robots.txt` e `sitemap.xml`.
- `professor-profile`: HTML do perfil igual para qualquer `tema`, com o filtro preenchido pelo navegador.
- `indicators`: resumo global no cache da pesquisa e pares da aba Redes calculados uma vez.
- `portal-home`: docentes em destaque com contagem direta e desempate estável.
- `department-page`: contagem das produções do corpo docente numa consulta.
- `thematic-search`: `Cache-Control` nas APIs de temas.
- `journal-catalog`: listas guardadas no cache da pesquisa (condicional).
- `admin-access`: verificação de origem, limite de login, senha mínima, remoção das telas de teste, login em português e `app:admin-user` sem imprimir senha nem hash.
- `sync-api`: CORS só com GET, POST e OPTIONS e, fora da leitura pública, só com a lista de origens permitidas.
- `database-backup`: dump sem colunas geradas, download inexistente com 404 e fim da rota coringa.
- `lattes-import`: importação por HTML em transação, sem duplicar produções na reimportação, e XML sem acesso à rede.
- `curriculum-normalization`: a fila de nomes de periódicos é reconstruída uma vez ao fim do lote.
- `profile-photos`: o crawler de fotos do CNPq verifica o TLS, usa só HTTPS e segue no máximo 3 redirecionamentos.
- `research-thesauri`: chaves estrangeiras religadas depois da limpeza do tesauro de autores.

## Segunda fase (12/11/2026, ainda não publicada)

Uma segunda rodada de correções, do mesmo code review, entra neste change.

**Importação, perfil e consultas**
- Parser HTML: depois da transação, `em->clear()` e novo `find()` do docente, como no XML. A normalização e a contagem de produções veem só as produções novas, sem duplicar na reimportação.
- Perfil: os fragmentos `orientations` e `activities` não carregam autores, e a consulta `MAX(year)` redundante saiu. O sitemap lê só `slug`, `id_lattes`, `last_lattes_update` e `updated_at` por DBAL. O HTML e o XML não mudam.
- `ResearchMetricsService` guarda o núcleo (`metrics_core`) e as pontes (`metrics_bridges`) em chaves separadas, com o mesmo resultado.
- `normalizeResearcher(Researcher, bool $rebuildQueue = true)` e `rebuildNameQueue()`: `app:curriculum:normalize` reconstrói a fila de nomes de periódicos uma vez ao fim do lote.

**Aquecimento do cache**
- Novo `app:cache:warm`: pede, uma a uma, as páginas principais (`--url` ou `DEFAULT_URI`, `--pausa=1`, `--apenas`, `--em-processo`), com trava, status e tempo por página; não para em erro e sai com falha se alguma falhar. `app:cache:clear --aquecer [--url=]` chama-o depois de limpar.

**Segurança e configuração**
- `trusted_hosts` pela variável `TRUSTED_HOSTS` (padrão: `localhost`, `127.0.0.1`, `cech.wab.com.br`, `www.cech.wab.com.br`); host fora da lista recebe 400. `trusted_proxies` não foi configurado.
- CORS com lista de origens (`buscatextual.cnpq.br`, `lattes.cnpq.br`, `cech.wab.com.br`, o próprio host e `localhost` só em dev e teste). `GET` em `/api/v1/*` e `/api/temas/*` continua com `*`.
- O crawler de fotos do CNPq verifica o certificado TLS, usa só HTTPS e segue no máximo 3 redirecionamentos.
- `app:admin-user` não imprime mais a senha nem o hash. `default_locale: pt_BR`: o login mostra as mensagens em português.
- Doctrine com `server_version: '8.0.40'`. O exemplo de `DATABASE_URL` em `.env` e `.env.test` não traz credenciais reais; as reais ficam em `.env.test.local`, ignorado pelo Git.

**Dependências e higiene**
- `composer.json`: `php ^8.3` e `config.platform.php` 8.3.0. Saem `symfony/doctrine-messenger` e `symfony/notifier` (sem uso). `composer audit` sem alertas em produção.
- Saem do Git `composer.phar`, `node_modules/` e `scripts/node_modules/` (5.189 arquivos; continuam no disco e entram no `.gitignore`; nada em produção os usa). Saem o gerador de CRUD (`src/Maker`, `GenerateIndexCommand`, `CrudPolisher`, `TemplateService`, `EnumExtension`). `GEMINI.md` aponta para `AGENTS.md`; o README pede PHP 8.3+.

**Esquema**
- Migration `Version20261112180000`: remove as chaves estrangeiras manuais duplicadas de `qualis_journal_academic_database` (`FK_QJ_JOURNAL`, `FK_QJ_DATABASE`; ficam as do Doctrine, com `ON DELETE CASCADE`, agora declarado na entidade `QualisJournal`) e a tabela `messenger_messages` (vazia, sem uso).

**Front-end**
- Versões fixas e SRI (SHA-384): Shoelace 2.19.1, Chart.js 4.4.7, D3 7.9.0, d3-sankey 0.12.3, ECharts 5.5.1, jQuery 3.7.1 e DataTables 1.13.7. `graph.js` só nas páginas com rede de coautoria. Imagens abaixo da dobra com `loading=lazy` e dimensões. Outfit sem o peso 500 e um `preconnect` a menos.

## Non-Goals

Ficam fora deste change (o motivo de cada um está em `design.md`):

- Tirar `public/uploads` do Git: o deploy é `git pull` e apagaria as fotos do servidor.
- Rotacionar a chave do TinyMCE. A senha do `.env.test` é genérica e só vale no MySQL local, sem uso em servidor: não é pendência.
- `trusted_proxies`.
- Limite de taxa no nginx.
- `opcache.max_accelerated_files` (7963, baixo) e preload no `php.ini` do RunCloud.
- Dividir o `StatisticsService` (2.000 linhas).
- Cache dos resultados de `/busca`.
- Trocar o Shoelace por SVG próprio e servir as fontes localmente.
- Banner de consentimento do Google Analytics (decisão do Jonas).
- Zerar o `doctrine:schema:validate` (colunas geradas `work_key` e `keywords_text`).
- Decidir o destino do `symfony/mailer`, sem uso aparente.
- Trocar `serverVersion=8.0` por `8.0.40` na `DATABASE_URL` dos `.env.local` do desenvolvimento e do servidor.
- Normalizar fora do parser HTML e XML em `app:import:lattes` (a fila de nomes ainda é reconstruída por docente nesse comando).
- Índices extras em `production_items`: medidos e descartados (ver `design.md`).
- Trocar o servidor (1 vCPU, 2 GB, 50 GB divididos com outros aplicativos).

## Impact

- Código: `public/index.php`, `src/Support/PageCacheFastPath.php`, `src/Service/PageCacheService.php`, `src/EventListener/PageCacheSubscriber.php`, `src/Command/AppCacheClearCommand.php`, `src/Controller/pub/SeoController.php`, `src/EventListener/CorsListener.php`, `src/Service/Research/ResearchSql.php`, `src/Service/Research/CoauthorshipGraphService.php`, `src/Service/StatisticsService.php`, repositórios de produção e de docentes, `DepartmentController`, `DatabaseBackupService`, `AdminDatabaseBackupController`, `AdminUserController`, `LattesHtmlParserService`, `AuthorThesaurusService`, os oito comandos de lote, um ouvinte novo de origem e de cabeçalhos, templates do perfil e do departamento.
- Removidos: `TestDatabaseController`, `SuperTestFieldsController`, entidades, formulários e templates das telas de teste.
- Banco: migrations `Version20261112150000` (colunas geradas e índices) e `Version20261112160000` (remove `test_database` e `super_test_fields`).
- Configuração: `PAGE_CACHE_MAX_MB` (opcional), `LOCK_DSN=flock`, `config/packages/lock.yaml`, `login_throttling` em `security.yaml`, canal de avisos no `monolog.yaml`.
- Dependências: Symfony 7.4 LTS, Twig 3.2x, PhpSpreadsheet, `symfony/rate-limiter`, `symfony/lock`.
- Produção: depois do deploy, `app:cache:clear --all` é obrigatório (as páginas antigas não estão em gzip). Robôs bem-comportados deixam de varrer as variações por parâmetro; os demais recebem 429 acima de 30 páginas por minuto.

Segunda fase:

- Código: `LattesHtmlParserService`, `CurriculumNormalizationService`, `NormalizeCurriculumsCommand`, `ResearchMetricsService`, `SeoController` (sitemap) e o controlador do perfil, `CacheWarmCommand` (novo), `AppCacheClearCommand`, `CorsListener`, `LattesPhotoCrawlerService`, `AdminUserCommand`, `QualisJournal`, `base.html.twig` e templates com `graph_script`, imagens e recursos de terceiros.
- Removidos: `src/Maker`, `GenerateIndexCommand`, `CrudPolisher`, `TemplateService`, `EnumExtension`, `composer.phar`, `node_modules/` e `scripts/node_modules/` do Git.
- Banco: migration `Version20261112180000`.
- Configuração: `TRUSTED_HOSTS`, `server_version` do Doctrine, `DATABASE_URL` de exemplo sem credenciais, `composer.json` (PHP 8.3).
- Produção: o `git pull` da segunda fase remove `composer.phar` e `node_modules` do servidor (não usados). Trocar `serverVersion` no `.env.local` do servidor, se existir, e conferir que `TRUSTED_HOSTS` cobre todos os acessos ao servidor.
