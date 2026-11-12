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
- `batch-command-locking`: execução única dos comandos de escrita em lote.
- `security-headers`: cabeçalhos de segurança HTTP em todas as respostas.

### Modified Capabilities

- `page-cache`: gzip, endereço canônico, teto com descarte, entrega antes do Symfony, revalidação e marcador `.enabled`.
- `seo`: `robots.txt` padrão mais restritivo e `Cache-Control` em `robots.txt` e `sitemap.xml`.
- `professor-profile`: HTML do perfil igual para qualquer `tema`, com o filtro preenchido pelo navegador.
- `indicators`: resumo global no cache da pesquisa e pares da aba Redes calculados uma vez.
- `portal-home`: docentes em destaque com contagem direta e desempate estável.
- `department-page`: contagem das produções do corpo docente numa consulta.
- `thematic-search`: `Cache-Control` nas APIs de temas.
- `journal-catalog`: listas guardadas no cache da pesquisa (condicional).
- `admin-access`: verificação de origem, limite de login, senha mínima e remoção das telas de teste.
- `sync-api`: CORS só com GET, POST e OPTIONS.
- `database-backup`: dump sem colunas geradas, download inexistente com 404 e fim da rota coringa.
- `lattes-import`: importação por HTML em transação e XML sem acesso à rede.
- `research-thesauri`: chaves estrangeiras religadas depois da limpeza do tesauro de autores.

## Non-Goals

Ficam fora deste change (o motivo de cada um está em `design.md`):

- Trocar o Shoelace servido por CDN e pôr SRI nos scripts.
- Tirar do Git `public/uploads`, `node_modules` e `composer.phar`.
- Rotacionar a chave do TinyMCE que ficou no histórico do Git.
- `server_version` do Doctrine e credenciais do `.env.test`.
- Renomear as chaves estrangeiras de `qualis_journal_academic_database` (diferença no `doctrine:schema:validate`).
- Verificação de TLS no crawler de fotos do CNPq.
- `trusted_hosts` e `trusted_proxies`.
- Lista de origens permitidas no CORS.
- Limite de taxa no nginx.
- `opcache.max_accelerated_files` (7963, baixo) e preload no `php.ini` do RunCloud.
- Dividir o `StatisticsService`.
- Cache dos resultados de `/busca`.
- Aquecer o cache depois de uma limpeza.
- Trocar o servidor (1 vCPU, 2 GB, 50 GB divididos com outros aplicativos).

## Impact

- Código: `public/index.php`, `src/Support/PageCacheFastPath.php`, `src/Service/PageCacheService.php`, `src/EventListener/PageCacheSubscriber.php`, `src/Command/AppCacheClearCommand.php`, `src/Controller/pub/SeoController.php`, `src/EventListener/CorsListener.php`, `src/Service/Research/ResearchSql.php`, `src/Service/Research/CoauthorshipGraphService.php`, `src/Service/StatisticsService.php`, repositórios de produção e de docentes, `DepartmentController`, `DatabaseBackupService`, `AdminDatabaseBackupController`, `AdminUserController`, `LattesHtmlParserService`, `AuthorThesaurusService`, os oito comandos de lote, um ouvinte novo de origem e de cabeçalhos, templates do perfil e do departamento.
- Removidos: `TestDatabaseController`, `SuperTestFieldsController`, entidades, formulários e templates das telas de teste.
- Banco: migrations `Version20261112150000` (colunas geradas e índices) e `Version20261112160000` (remove `test_database` e `super_test_fields`).
- Configuração: `PAGE_CACHE_MAX_MB` (opcional), `LOCK_DSN=flock`, `config/packages/lock.yaml`, `login_throttling` em `security.yaml`, canal de avisos no `monolog.yaml`.
- Dependências: Symfony 7.4 LTS, Twig 3.2x, PhpSpreadsheet, `symfony/rate-limiter`, `symfony/lock`.
- Produção: depois do deploy, `app:cache:clear --all` é obrigatório (as páginas antigas não estão em gzip). Robôs bem-comportados deixam de varrer as variações por parâmetro; os demais recebem 429 acima de 30 páginas por minuto.
