## 1. Cache de páginas e robôs

- [x] 1.1 `PageCacheFastPath`: endereço canônico (parâmetros em ordem; perfil sem parâmetros fora de `aba=busca`), arquivo `<md5>.html.gz`, cookie de sessão e `ETag`. Verificar com `tests/Support/PageCacheFastPathTest.php`.
- [x] 1.2 `PageCacheService` grava em gzip, recusa HTML acima de 16 MB e devolve a página com `ETag`, `Last-Modified`, `Cache-Control: no-cache` e 304. Verificar com `PageCacheServiceTest` (compactação, um arquivo por perfil, ordem dos parâmetros, página enorme, validadores).
- [x] 1.3 Teto `PAGE_CACHE_MAX_MB` (padrão 2048) e descarte em média a cada 200 gravações: vencidas e, acima do teto, as mais antigas até 80%. `app:cache:clear --prune` roda só o descarte. Verificar com o teste do descarte e rodando `--prune` no ambiente local.
- [x] 1.4 `/admin/cache` mostra o teto ao lado do espaço ocupado. Verificar com teste funcional da tela.
- [x] 1.5 Marcador `.enabled`: criado ao gravar com o cache ligado, removido pelo `PageCacheSubscriber` com o cache desligado e por `app:cache:clear`. Verificar com o teste do marcador.
- [x] 1.6 `public/index.php` serve a página em cache antes do kernel (GET/HEAD, sem cookie de sessão, com marcador), em gzip ou descompactada, e responde 304 a `If-None-Match` igual. Verificar com `curl -I` local em modo prod: `X-Page-Cache: HIT`, `Content-Encoding: gzip`, 304 e `HEAD` sem corpo.
- [x] 1.7 Perfil: a caixa de filtro sai vazia do servidor e é preenchida pelo JavaScript a partir de `tema`, `keyword` ou `q`. Verificar com teste que compara o HTML de `/professor/{slug}?tema=a` e `?tema=b` e abrindo o perfil com `?tema=` no navegador.
- [x] 1.8 Freio para robôs: robô pelo User-Agent, cota própria de 30 por minuto fora do cache, 429 com `Retry-After: 60` e `Cache-Control: no-store`; visitantes, páginas em cache, `/robots.txt` e `/sitemap.xml` isentos. Verificar com `PageCacheFastPathTest` (limite, minuto seguinte, isenções, dois robôs).
- [x] 1.9 `robots.txt` padrão novo (caminhos, parâmetros, `Crawl-delay: 5`) e `Cache-Control: public, max-age=3600` em `robots.txt` e `sitemap.xml`; o texto personalizado continua com prioridade. Verificar com teste funcional do `SeoController` (padrão, personalizado, cabeçalho).
- [x] 1.10 Documentar em `docs/` o cache em gzip, o teto, `--prune`, o marcador `.enabled`, o freio e o `robots.txt`. Verificar que os comandos citados rodam como escritos.

## 2. Desempenho das consultas

- [x] 2.1 Migration `Version20261112150000`: `work_key` e `keywords_text` (STORED) e os índices em `production_items` e `production_authors`; entidades com as colunas somente leitura e os índices. Verificar com `doctrine:migrations:migrate`, volta à versão anterior e `migrate` de novo no ambiente local, e `doctrine:schema:validate` sem diferença nessas tabelas.
- [x] 2.2 `ResearchSql::workKey()` e `keywords()` usam as colunas; as consultas de pares exigem `matched_researcher_id IS NOT NULL` dos dois lados. Verificar com teste que compara, sobre os mesmos dados, contagens de obras únicas, séries, palavras-chave e pares com a fórmula antiga, e com os testes de `tests/Service/Research`.
- [x] 2.3 Backup em SQL sem as colunas geradas nos INSERTs. Verificar gerando um dump local e restaurando-o com `app:database:restore` sem comandos que falham.
- [x] 2.4 `/departamento/{codigo}`: produções de todos os docentes numa consulta (`countPerResearcher`) e template sem `r.productions|length`. Verificar com teste funcional (contagens iguais às de antes, zero para docente sem produção) e no profiler (número de consultas).
- [x] 2.5 Indicadores: resumo global no `cache.research`; pares da Figura 16 calculados uma vez por requisição, com desempate pelo id dos docentes. Verificar com teste do `StatisticsService` (resumo reaproveitado; os 8 primeiros iguais ao início da lista completa).
- [x] 2.6 Home: docentes em destaque numa passada por `production_items`, ordenados por A1, total e id. Verificar com teste do `ResearcherRepository` (contagens e desempate) e comparando a lista da home antes e depois com a base local.
- [x] 2.7 `/api/temas/*`: `Cache-Control: public, max-age=3600` nas respostas 200 a GET. Verificar com `ThematicSearchControllerTest` (200 com o cabeçalho, 404 sem).
- [x] 2.8 `/periodicos`: listas por base e área no `cache.research` (feita; o delta `specs/journal-catalog/` fica). Verificar com teste do serviço de periódicos.
- [x] 2.9 Medir a frio, em modo prod e sem cache de página, `/api/v1/metricas`, `/busca?q=educacao`, `/explorar/assunto/{slug}`, um departamento e a home, antes e depois. Verificar registrando os números em `docs/CAPACIDADE_DO_SERVIDOR.md`.

## 3. Segurança

- [x] 3.1 Ouvinte de origem: 403 para POST, PUT, PATCH e DELETE em `/admin` e para `GET /admin/database/backup/stream` com `Origin` de outro host, `Origin: null` ou `Sec-Fetch-Site: cross-site`. Verificar com testes funcionais (outro site, origem nula, cross-site, mesmo host, sem cabeçalhos).
- [x] 3.2 `login_throttling` com 5 tentativas em 15 minutos. Verificar com teste funcional: a 6ª tentativa é recusada mesmo com a senha certa.
- [x] 3.3 Senha nova com no mínimo 12 caracteres em `/admin/users/`, na criação e na edição. Verificar com teste funcional (senha curta recusada; senha em branco na edição mantém a atual).
- [x] 3.4 Cabeçalhos `Referrer-Policy`, `X-Content-Type-Options` e `X-Frame-Options` em todas as respostas (ouvinte de resposta e `PageCacheFastPath` no HIT, no 304 e no 429); `Strict-Transport-Security` só em HTTPS. Verificar com testes (página gerada, página do cache, 429, HTTP sem HSTS).
- [x] 3.5 CORS de `/api/` só com `GET, POST, OPTIONS`. Verificar com teste do preflight e de uma resposta comum.
- [x] 3.6 Backup: sai a rota `/admin/database/{filename}`; arquivo inválido ou inexistente responde 404. Verificar com testes funcionais (nome inexistente, `..%2F..%2F.env`, `/admin/database/qualquer-coisa`, pasta vazia).
- [x] 3.7 Remover as telas de teste do gerador de CRUD (controladores, entidades, repositórios, formulários e templates) e criar a migration `Version20261112160000`, que apaga `super_test_fields` e `test_database`. Verificar com `debug:router` sem rotas de teste, `migrate` e volta à versão anterior no ambiente local, e teste de 404.
- [x] 3.8 Atualizar `docs/CODE_REVIEW.md` e `docs/ADMIN_GUIDE.md` com o que foi corrigido e as pendências. Verificar que cada pendência traz o motivo.

## 4. Importação e comandos

- [x] 4.1 Importação por HTML (painel e marcador) numa transação. A importação do Repositório continua em lotes atômicos, por decisão registrada. Verificar com teste que força falha no meio da gravação e confirma que as produções anteriores do docente continuam.
- [x] 4.2 `truncateAuthorThesaurus()` religa `FOREIGN_KEY_CHECKS` num `finally`. Verificar com teste que força erro na limpeza e confere `@@FOREIGN_KEY_CHECKS = 1`.
- [x] 4.3 `symfony/lock` com `LOCK_DSN=flock` e trava em `app:import:lattes`, `app:import:repository`, `app:index:topics`, `app:curriculum:normalize`, `app:thesaurus:sync-authors`, `app:journals:pipeline`, `app:database:restore` e `app:index:projects`. Verificar com teste que segura a trava e roda o comando (aviso, banco intacto) e com duas execuções em paralelo no ambiente local.
- [x] 4.4 `bin/publish-journals.sh` roda `app:cache:clear` depois do pipeline. Verificar com `bash -n bin/publish-journals.sh` e lendo o passo no script.
- [x] 4.5 `LIBXML_NONET` em toda leitura de XML (parser do Lattes, `app:sync:keywords`, `app:projects:backfill-members` e arquivos do tesauro). Verificar com teste de um XML com DTD externa e com `grep` sem `simplexml_load_*` ou `loadXML` sem a opção.
- [x] 4.6 Canal de avisos do Monolog em produção: `var/log/prod-avisos.log`, rotação diária, 14 arquivos. Verificar com `lint:yaml config` e `debug:config monolog --env=prod`.
- [x] 4.7 Documentar a trava, o `publish-journals.sh` e o log de avisos em `docs/DEPLOY_E_PRODUCAO.md` e `docs/COMANDOS.md`.

## 5. Dependências

- [x] 5.1 Symfony 7.2 → 7.4 LTS em `composer.json` (inclusive `extra.symfony.require`) e `symfony.lock`, com as receitas novas. Verificar com `composer install` limpo e `bin/console about`.
- [x] 5.2 Twig 3.2x, PhpSpreadsheet atualizado, `symfony/rate-limiter` e `symfony/lock`. Verificar com `composer audit --no-dev` sem alertas.
- [x] 5.3 Corrigir as depreciações do 7.4 que aparecerem. Verificar com a suíte sem avisos novos de depreciação.
- [x] 5.4 Atualizar a versão do Symfony em `openspec/config.yaml` (context), `README.md` e `docs/`. Verificar com `grep -rn "Symfony 7.2"` sem ocorrências fora do histórico.

## 6. Verificação e publicação

- [x] 6.1 Rodar `APP_ENV=test ./vendor/bin/phpunit --testdox` e verificar a suíte completa passando.
- [x] 6.2 Rodar `php bin/console lint:twig templates` e `php bin/console lint:yaml config` e verificar que não há erros.
- [x] 6.3 Rodar `openspec validate estabiliza-cache-desempenho-seguranca --strict` e verificar que passa.
- [x] 6.4 Comparar a medição antes/depois (2.9) com os números da proposta e verificar que nenhuma rota piorou.
- [x] 6.5 Registrar a entrada de 12/11/2026 em `config/novidades.yaml`. Verificar com `app:novidades:pendentes` sem commits pendentes.
- [x] 6.6 Antes do deploy, conferir `df -h /` e gerar o backup `app:database:dump --filename=antes_estabiliza_<data> --env=prod`. Verificar que o `.sql.zip` existe em `var/backups`.
- [x] 6.7 Deploy pelo plano de `design.md`: `git pull --ff-only`, `composer install --no-dev --optimize-autoloader`, `doctrine:migrations:migrate`, `./build.sh`, `cache:clear --env=prod` e `app:cache:clear --all --env=prod`. Verificar que cada passo termina sem erro.
- [x] 6.8 Conferir em produção com `curl`: HIT, gzip e 304; `robots.txt` e `sitemap.xml` com o cabeçalho; 429 para robô acima de 30 páginas; cabeçalhos de segurança numa página gerada e numa do cache; 403 de origem; uma ação do painel funcionando; 404 nas telas de teste; tempos a frio. Verificar anotando os resultados no relato do deploy.
- [ ] 6.9 Um dia depois do deploy, conferir `du -sh var/cache/public_pages` abaixo do teto e `var/log/prod-avisos.log` sem erro novo.

## Workflow follow-up

- Ao arquivar, sincronizar os deltas com as specs principais.
- Ao arquivar, tirar do Purpose de `openspec/specs/admin-access/spec.md` a menção às telas de teste do gerador de CRUD (o delta não altera o Purpose).
- A tarefa 2.8 foi feita: manter `specs/journal-catalog/`.
