## Context

O motivo está em `proposal.md` (Why). Restrições que moldam a solução:

- Servidor de produção no RunCloud com 1 vCPU, 2 GB de RAM e 50 GB de disco, dividido com outros aplicativos. Não há cron configurado para o portal, nem Redis ou Memcached. O deploy é por `git pull`.
- O PHP-FPM atende cada requisição dando partida no kernel do Symfony. Até aqui, uma página em cache também passava pelo kernel (container, roteador, ouvintes) antes de o `PageCacheSubscriber` devolver o arquivo.
- Regra fixa do projeto: o dado do Lattes nunca é alterado; o que é calculado vai em colunas novas. Mudança de esquema só por migration.
- MySQL 8.0 no ambiente local e 8.4 em produção.
- A pesquisa pública identifica a obra única pela regra de `research-panorama` (DOI ou tipo + ano + título). Até aqui, cada consulta recalculava o MD5 dessa chave e extraía `extra_data.keywords` linha a linha, em tabelas com centenas de milhares de registros.
- O painel tem 21 formulários sem token CSRF. Os que têm token (SEO, bases, usuários, backup, cache) continuam com ele.

## Goals / Non-Goals

**Goals:**

- O cache de páginas nunca mais encher o disco: teto de espaço, arquivos compactados e uma só cópia por perfil.
- Página em cache servida sem dar partida no Symfony.
- Robôs não conseguirem gerar páginas novas mais rápido do que o servidor aguenta.
- Consultas da pesquisa mais rápidas a frio, com os mesmos resultados.
- Fechar as falhas de segurança achadas na revisão de 12/11 que cabem na aplicação.
- Comandos de lote e importação por HTML sem estados pela metade.

**Non-Goals (pendências, com o motivo):**

- **Uploads de fotos no Git**: o deploy é por `git pull`; tirar `public/uploads` do repositório apagaria as fotos do servidor no pull seguinte. Exige antes mover as fotos para fora do repositório e mudar o deploy.
- **Rotacionar a chave do TinyMCE e a senha de root do MySQL do `.env.test`**: a chave já não é citada no código, mas depende de gerar outra na conta do TinyMCE; a senha do MySQL continua no histórico do Git, e reescrever o histórico não resolve sozinho. Trocar as duas é ação manual do Jonas.
- **`trusted_proxies`**: depende de confirmar com o RunCloud os proxies reais; errar faria o portal enxergar o endereço e o esquema errados.
- **Limite de taxa no nginx**: a configuração do nginx fica no painel do RunCloud, fora do repositório.
- **`opcache.max_accelerated_files` (7963) e preload**: ajuste no `php.ini` do RunCloud, compartilhado com outros aplicativos.
- **Dividir o `StatisticsService` (2.000 linhas)**: refatoração grande, sem ganho de desempenho imediato.
- **Cache dos resultados de `/busca`**: decisão da spec `page-cache`: texto livre gera chaves sem fim e não vai para cache; o freio por robô cobre o abuso.
- **Shoelace por SVG próprio e fontes locais**: trocar mexe em todas as páginas e pede rodada própria de testes visuais; as versões fixas e o SRI já cobrem o risco principal.
- **Banner de consentimento do Google Analytics**: decisão do Jonas.
- **`doctrine:schema:validate` zerado**: o mapeamento está OK, mas o banco aparece "not in sync" só pelas colunas geradas `work_key` e `keywords_text`. O Doctrine não compara bem um `columnDefinition` de coluna gerada e sugere recriá-las. Não é diferença real; fica registrado como pendência conhecida.
- **`symfony/mailer` sem uso aparente**: o pacote ficou; a decisão de remover depende de confirmar que nenhum fluxo de e-mail é planejado.
- **`serverVersion=8.0` na `DATABASE_URL` dos `.env.local`** (desenvolvimento e servidor): o parâmetro da URL tem precedência sobre o `server_version` do `doctrine.yaml`; trocar por `8.0.40` é edição manual de arquivos fora do Git.
- **Parser HTML e XML normalizam dentro do parser em `app:import:lattes`**: por isso a fila de nomes de periódicos ainda é reconstruída por docente nesse comando; separar a normalização do parser é mudança maior.
- **Trocar o servidor**: decisão de custo, fora do código.

## Decisions

### D1. Colunas geradas STORED para a chave da obra e as palavras-chave

`production_items` ganha `work_key` (CHAR(32) ASCII, MD5 da chave da obra) e `keywords_text` (TEXT utf8mb4, `extra_data.keywords` como texto), as duas `GENERATED ALWAYS ... STORED`, com a mesma fórmula que `ResearchSql::workKey()` e `ResearchSql::keywords()` montavam em cada consulta. `ResearchSql` passa a devolver o nome da coluna. No Doctrine, os campos são mapeados com `insertable: false` e `updatable: false` e `columnDefinition` igual à migration, para o `schema:validate` não acusar diferença.

Por quê:

- **Regra do Lattes**: as colunas brutas (`doi`, `title`, `item_type`, `year`, `extra_data`) não mudam; o valor calculado fica em coluna nova.
- **Nenhuma mudança nos importadores**: o MySQL mantém as colunas em todo INSERT e UPDATE (XML, HTML, Repositório, `app:sync:keywords`, restauração, correção manual). Não há como esquecer um caminho de gravação.
- **STORED em vez de VIRTUAL**: as consultas varrem e agrupam muitas linhas por `work_key`; com VIRTUAL o MD5 e a extração do JSON voltariam a ser calculados na leitura, fora do índice. O custo é espaço: cerca de 32 bytes por linha mais as palavras-chave.
- **MySQL 8.0 e 8.4** suportam colunas geradas STORED e índices secundários nelas.

Alternativas: coluna comum preenchida pelo importador mais um backfill (quatro caminhos de gravação para mudar e risco de divergência); índice funcional sobre a expressão (as consultas teriam de repetir a expressão exata, e qualquer diferença perde o índice); tabela de obras únicas (mudança de modelo grande demais para uma correção); só cachear resultados (não resolve a primeira visita).

Os índices `(production_item_id, matched_researcher_id)` e `(matched_researcher_id, production_item_id)` em `production_authors` cobrem a autojunção que monta os pares de coautoria. As consultas de pares passam a exigir `matched_researcher_id IS NOT NULL` dos dois lados, o que já era implícito no `<` e deixa o otimizador usar o índice.

Para garantir resultados iguais, os testes comparam as contagens de obras únicas, as séries e os pares antes e depois da troca, com os mesmos dados.

### D2. Gzip em arquivo e teto com descarte probabilístico, em vez de cron

O HTML compacta muito: o perfil mais produtivo cai de 6,2 MB para 246 KB. Gravar já em gzip reduz o disco na mesma proporção e permite entregar o arquivo como está a quem aceita gzip, sem compactar de novo a cada visita.

O teto (`PAGE_CACHE_MAX_MB`, padrão 2048) é verificado por um descarte que roda dentro da requisição que grava, com probabilidade 1/200. Ele apaga as vencidas e, se o total passar do teto, as mais antigas pela data de gravação até sobrar 80%. A folga de 20% evita que o descarte rode a cada gravação quando o cache está perto do teto. `app:cache:clear --prune` roda o mesmo descarte à mão.

Por quê: não há cron no servidor, e o RunCloud não agenda tarefas do portal hoje. O custo do descarte (listar e consultar a data de alguns milhares de arquivos) cai numa requisição a cada 200 gravações, não em todas.

Página com mais de 16 MB de HTML não é gravada: uma só não pode ocupar o espaço de milhares.

Alternativas: teto por número de arquivos (os tamanhos vão de poucos KB a 6 MB); descarte pelo último acesso (o disco pode estar montado com `noatime`); Redis ou Memcached (não há no servidor, e 2 GB de RAM não comportam); `fastcgi_cache` do nginx (configuração fora do repositório e sem limpeza pela aplicação); `HttpCache` do Symfony (continua dando partida no kernel).

### D3. Servir antes do kernel, com o marcador `.enabled`

`public/index.php` carrega `App\Support\PageCacheFastPath` com `require_once`, sem autoload, e tenta servir a página antes de `autoload_runtime.php`. A classe não depende de nada e concentra as regras que o `PageCacheService` também usa (endereço canônico, nome do arquivo, cookie de sessão), para que os dois caminhos nunca divirjam.

O atalho não reavalia a elegibilidade: só existe arquivo para endereço elegível, e um pedido inelegível cai num nome de arquivo que não existe. A exceção é o perfil do docente, cuja chave descarta os parâmetros fora de `aba=busca`; nele, qualquer endereço recebe a cópia do perfil, o que é correto porque o servidor não lê esses parâmetros (o filtro é preenchido pelo JavaScript).

O atalho roda antes do Dotenv e não sabe se `PAGE_CACHE_ENABLED` está ligado. O marcador `.enabled` na pasta do cache é esse sinal: o `PageCacheService` o cria ao gravar com o cache ligado; o `PageCacheSubscriber` o remove quando o cache está desligado; `app:cache:clear` apaga a pasta inteira, com o marcador.

A resposta leva `ETag` (nome do arquivo + data de gravação), `Last-Modified`, `Cache-Control: no-cache` e `Vary: Accept-Encoding`. `no-cache` faz o navegador revalidar a cada visita, e a revalidação custa um `stat` e um 304 sem corpo.

Alternativas: `try_files` do nginx apontando para os arquivos (configuração fora do repositório, sem tratar o cookie de sessão nem a escolha entre gzip e texto); manter só o subscriber (cada HIT continua dando partida no kernel).

### D4. Freio só para robôs e só fora do cache

O custo está em gerar página, não em servir o arquivo. Por isso o freio roda depois da tentativa de servir do cache e só para quem se identifica como robô: limitar visitantes por IP arriscaria bloquear a rede da UFSCar, onde muitas pessoas saem pelo mesmo endereço.

Cada robô é identificado pela palavra do User-Agent que contém `bot`, `crawler`, `spider`, `slurp` ou `scrapy` (`perplexitybot`, `amazonbot`, `googlebot`). O contador fica em arquivo, um por robô e por minuto, em `var/cache/bot_throttle`: cada pedido acrescenta um byte com `LOCK_EX`, e o tamanho do arquivo é a contagem. O primeiro pedido do minuto apaga os contadores antigos daquele robô. Isso funciona entre os processos do FPM sem APCu nem Redis.

30 páginas por minuto (uma a cada 2 s) atende um robô que respeite o `Crawl-delay: 5` (12 por minuto) com folga e corta varreduras como a de 08/11.

Alternativas: `limit_req` do nginx (pendência, configuração fora do repositório); `symfony/rate-limiter` dentro do kernel (o freio chegaria depois da parte cara, a partida do kernel); bloquear os robôs de IA no `robots.txt` (não é obrigatório para eles e tiraria o portal desses índices).

### D5. `robots.txt` como primeira barreira

O padrão passa a proibir a busca, as APIs, os fragmentos e as exportações do perfil e as variações por parâmetro (`Disallow: /*?tema=` e `Disallow: /*&tema=`, e o mesmo para cada parâmetro). As páginas do sitemap continuam liberadas. `Crawl-delay: 5` é respeitado por parte dos robôs (Bing, Yandex) e ignorado por outros (Google). O curinga `*` é entendido pelos buscadores principais; os demais ficam com o freio de D4. O texto personalizado em `/admin/seo` continua com prioridade.

### D6. Verificação de origem em vez de token CSRF em 21 formulários

Um ouvinte de `kernel.request` recusa com 403 os `POST`, `PUT`, `PATCH` e `DELETE` em `/admin`, e o `GET /admin/database/backup/stream` (que gera um backup), quando `Origin` é de outro host, é `null` ou quando `Sec-Fetch-Site` é `cross-site`. Navegadores atuais mandam esses cabeçalhos em todo envio de formulário, e um formulário forjado em outro site chega com a origem dele.

Por quê: pôr token em 21 formulários e conferir em cada controlador é trabalho espalhado, fácil de esquecer no próximo formulário. A verificação de origem cobre o painel inteiro num lugar só, incluindo ações futuras. Os tokens que já existem continuam.

Limite conhecido: pedido sem `Origin` e sem `Sec-Fetch-Site` passa (navegadores antigos, `curl`). Isso não abre CSRF por navegador atual, e o cookie de sessão com `SameSite=Lax` (padrão do Symfony) já impede o envio em `POST` vindo de outro site na maioria dos navegadores.

Alternativas: token em todos os formulários (pode vir depois, aos poucos); `SameSite=Strict` (quebraria a volta ao painel por links de e-mail e do próprio portal).

### D7. Login, senha e cabeçalhos

- `login_throttling` do Symfony (via `symfony/rate-limiter`) com `max_attempts: 5` e `interval: 15 minutes`, por usuário e endereço.
- Senha nova com no mínimo 12 caracteres, conferida no `AdminUserController` (criação e edição).
- Um ouvinte de `kernel.response` acrescenta `Referrer-Policy`, `X-Content-Type-Options`, `X-Frame-Options` e, quando a requisição é HTTPS, `Strict-Transport-Security`. O `PageCacheFastPath` envia os mesmos cabeçalhos no HIT, no 304 e no 429, que não passam pelo kernel.
- CORS de `/api/` só com `GET, POST, OPTIONS`: nenhuma rota da API aceita `PUT` ou `DELETE`.
- Download de backup sem a rota coringa `/admin/database/{filename}` e com 404 para arquivo inválido ou inexistente: entregar "o mais recente" no lugar de um nome errado escondia erros e, com nome forjado, entregava um backup sem que o administrador pedisse aquele arquivo.
- Telas de teste do gerador de CRUD removidas com controladores, entidades, formulários e templates; a migration `Version20261112160000` apaga `super_test_fields` e `test_database`, nessa ordem por causa da chave estrangeira.

### D8. Trava dos comandos de lote com `symfony/lock`

Os oito comandos de escrita em lote usam uma trava com o nome do comando (`LockableTrait` ou `LockFactory`), guardada por `flock` (`LOCK_DSN=flock`). Sem esperar: se a trava está ocupada, o comando avisa que já está em execução e sai. O `flock` é liberado pelo sistema quando o processo termina, mesmo num erro fatal ou num Ctrl+C.

Alternativa: trava no MySQL (`GET_LOCK`), que prende uma conexão durante o comando inteiro e se perde se a conexão cair no meio. Com um único servidor, o `flock` basta.

### D9. Importação por HTML em transação e tesauro com `finally`

O `LattesHtmlParserService` passa a apagar e regravar os itens do docente dentro de `Connection::transactional()`, como o parser de XML. A sincronização do tesauro, a normalização e o relatório rodam depois do commit, também como no XML. Se a gravação falha, a exceção sobe e quem chama limpa ou reabre o EntityManager, que o Doctrine fecha na falha do flush.

`AuthorThesaurusService::truncateAuthorThesaurus()` religa `FOREIGN_KEY_CHECKS` num `finally`.

### D10. Consultas agregadas e cálculos reaproveitados

- `/departamento/{codigo}`: `ProductionItemRepository::countPerResearcher()` conta as produções de todos os docentes da página numa consulta; o template deixa de usar `r.productions|length`, que carregava todas as produções de cada docente (110 consultas e 94 MB).
- Home: `findFeaturedResearchers()` soma artigos A1 e o total por docente numa passada em `production_items`, ordena por A1, total e id, e carrega só os 8 docentes. A versão anterior juntava as produções duas vezes (cerca de 800 mil linhas intermediárias) e carregava todos os docentes.
- Indicadores: `getGlobalSummary()` guarda o resumo no pool `cache.research` (um dia), o mesmo do panorama e da rede, que já é limpo junto com o cache de páginas. `getFig13CoauthorshipNetwork()` calcula a lista completa uma vez por requisição e recorta os 8 primeiros em memória, com desempate por id para a ordem ser estável.
- `/api/temas/*`: o `CorsListener` marca as respostas 200 a GET como públicas por uma hora.
- `/periodicos` (opcional): as listas por base e área no `cache.research`. Só entra se a medição mostrar ganho; se não entrar, `specs/journal-catalog` sai do change antes do arquivamento.

### D11. Dependências e logs

- Symfony 7.2 saiu de manutenção; 7.4 é LTS. Twig vai para 3.2x e PhpSpreadsheet para a versão sem alertas. `composer audit` sem alertas nos pacotes de produção é o critério de aceite.
- Um canal de avisos do Monolog grava `warning` ou mais grave em `var/log/prod-avisos.log`, com rotação diária e 14 arquivos, para que avisos não se percam no log principal.
- `bin/publish-journals.sh` roda `app:cache:clear` depois do pipeline, para não servir páginas montadas antes dos dados novos (o `cache:clear` do Symfony não toca no cache de páginas).
- Leitura de XML (`simplexml_load_file`, `simplexml_load_string`) com `LIBXML_NONET` no parser do Lattes, em `app:sync:keywords`, em `app:projects:backfill-members` e na importação de arquivos do tesauro.

### D12. Segunda fase: aquecimento, CORS, hosts e configuração

**Aquecimento (`app:cache:warm`).** Sem cron, quem limpa o cache deixa a primeira visita real pagar a geração (13 s a frio na home). O comando pede as páginas principais uma de cada vez, por HTTP no endereço do site (`--url` ou `DEFAULT_URI`), com pausa de 1 s (`--pausa`), para ficar abaixo do freio de robôs e do que a CPU única aguenta. Usa a trava dos comandos de lote (D8), relata status e tempo, não para em erro e sai com falha se alguma página falhar. `--apenas` filtra por trecho do caminho. `--em-processo` chama o kernel sem rede, para ambientes sem URL. A página só entra no cache se a resposta é lida até o fim, por isso o comando lê o corpo inteiro. `app:cache:clear --aquecer` encadeia os dois, e a falha do aquecimento não muda o resultado da limpeza. As páginas de assunto e de perfil não entram na lista: são milhares, e o teto do cache não comportaria aquecê-las todas.

**CORS com lista de origens.** O `CorsListener` devolvia a origem de qualquer um em `/api/`, inclusive nas rotas de escrita. Agora, `GET` em `/api/v1/*` e `/api/temas/*` continua com `*` (API pública de leitura, sem cookie). As demais rotas só recebem `Allow-Origin` para `buscatextual.cnpq.br`, `lattes.cnpq.br`, `cech.wab.com.br`, o próprio host e, em dev e teste, `localhost` e `127.0.0.1`. Quem não está na lista não recebe o cabeçalho, e o navegador bloqueia; o token de sincronização continua exigido. Chamadas sem `Origin` (curl, servidor a servidor) não são afetadas.

**`trusted_hosts` e `trusted_proxies`.** `trusted_hosts` vem da variável `TRUSTED_HOSTS` (regex; padrão com `localhost`, `127.0.0.1`, `cech.wab.com.br` e `www.cech.wab.com.br`). Um `Host` forjado recebe 400, o que protege links gerados a partir do `Host`. `trusted_proxies` não foi configurado: sem confirmar os proxies do RunCloud, o risco de errar o esquema e o IP do cliente é maior que o ganho.

**TLS do crawler.** O crawler de fotos do CNPq deixou de usar `VERIFYPEER=false`: verifica o certificado, aceita só HTTPS (inclusive em redirecionamentos) e segue no máximo 3 redirecionamentos.

**Credenciais e senha.** `.env` e `.env.test` trazem só um exemplo de `DATABASE_URL`; as credenciais reais ficam em `.env.test.local`, ignorado pelo Git. `app:admin-user` não imprime senha nem hash. `default_locale: pt_BR` traduz as mensagens de login (`Credenciais inválidas.`). `server_version: '8.0.40'` no Doctrine evita consultar o servidor para descobrir a versão.

### D13. Segunda fase: parser HTML, perfil, sitemap e métricas

- **Parser HTML**: depois da transação, o `LattesHtmlParserService` chama `em->clear()` e recarrega o docente com `find()`, como o parser XML. Antes, a coleção de produções do docente ainda guardava os itens apagados na memória, e a normalização e `productionsCount` viam itens velhos e novos juntos.
- **Perfil** (sem comportamento novo, por isso sem requisito): os fragmentos `orientations` e `activities` chamam `findWithAllDetails($slug, withAuthors)` sem os autores, que eles não mostram, e a consulta `MAX(year)` redundante saiu. O HTML é idêntico.
- **Sitemap** (sem requisito): lê só `slug`, `id_lattes`, `last_lattes_update` e `updated_at` por DBAL, sem hidratar entidades. O XML é idêntico.
- **`ResearchMetricsService`** (sem requisito): o núcleo (`metrics_core`) e as pontes (`metrics_bridges`) ficam em chaves de cache separadas; quem pede só o núcleo não paga as pontes. O resultado é o mesmo.
- **Normalização**: `normalizeResearcher(Researcher, bool $rebuildQueue = true)` e `rebuildNameQueue()` públicos. O comando `app:curriculum:normalize` passa `false` e reconstrói a fila uma vez ao fim do lote, em vez de uma por docente. Chamadas isoladas (painel e importação) mantêm o comportamento anterior.

### D14. Segunda fase: dependências, higiene, esquema e front-end

- **composer**: `php ^8.3` e `config.platform.php` 8.3.0 (o local roda 8.3; produção usa 8.4). Saem `symfony/doctrine-messenger` e `symfony/notifier`, sem uso; o `messenger.yaml` e o `notifier.yaml` saem junto. O `symfony/mailer` fica, pendente de decisão. `composer audit` sem alertas em produção.
- **Git**: saem `composer.phar`, `node_modules/` e `scripts/node_modules/` (5.189 arquivos; continuam no disco e entram no `.gitignore`). Nada em produção os usa. Saem também o gerador de CRUD (`src/Maker`, `GenerateIndexCommand`, `CrudPolisher`, `TemplateService`, `EnumExtension`).
- **Esquema**: `Version20261112180000` remove `FK_QJ_JOURNAL` e `FK_QJ_DATABASE` de `qualis_journal_academic_database`, duplicadas das chaves do Doctrine, e a tabela `messenger_messages` (vazia). Fica a chave do Doctrine com `ON DELETE CASCADE`, declarado agora na entidade `QualisJournal`.
- **Índices extras em `production_items` (decisão: não criar)**: `(item_type, qualis_journal_id, year)` e `(researcher_id, year)` foram medidos e não trouxeram ganho relevante nas consultas lentas; cada índice a mais custa espaço e tempo de gravação nas importações. Se uma consulta nova mostrar o contrário, medir de novo antes de criar.
- **Front-end**: versões fixas e SRI (SHA-384) em Shoelace 2.19.1 (autoloader, `light.css`, `dark.css`), Chart.js 4.4.7, D3 7.9.0, d3-sankey 0.12.3, ECharts 5.5.1, jQuery 3.7.1 e DataTables 1.13.7. Os módulos que o autoloader do Shoelace importa em tempo de execução ficam sem SRI, porque o navegador os busca por `import()` e o autoloader não aceita o atributo. `graph.js` sai do layout e entra pelo bloco `graph_script`, só no perfil, no departamento, em `/coautorias` e no escopo do Explorar. Imagens abaixo da dobra com `loading="lazy"` e dimensões; Outfit sem o peso 500 e um `preconnect` a menos.

## Risks / Trade-offs

- [ETag e compressão do nginx] Se o nginx compactar ou alterar a resposta, ele enfraquece o `ETag` (`W/"..."`), e a comparação exata com `If-None-Match` deixa de dar 304. → O atalho já manda gzip pronto com `Content-Encoding`, e o nginx não recompacta. Conferir em produção com `curl -I` e `If-None-Match`; se o `W/` aparecer, aceitar a comparação fraca. O pior caso é uma resposta 200 completa, nunca conteúdo errado.
- [Robô que não se identifica] Quem usa User-Agent de navegador não é freado. → O `robots.txt` não o segura, mas o teto do cache limita o dano ao disco. O limite no nginx fica como pendência. Acompanhar o log de acesso.
- [Restauração de backup antigo] Um backup feito antes da migration recria `production_items` sem `work_key` e `keywords_text`, e a pesquisa pública dá erro de coluna desconhecida. → Como o backup antigo também traz a tabela de versões das migrations sem a `Version20261112150000`, basta rodar `doctrine:migrations:migrate` depois de restaurar. Registrado no guia de deploy.
- [Marcador `.enabled` depois de desligar o cache] Com o cache desligado, o atalho continua servindo enquanto nenhuma requisição chega ao kernel. → Depois de desligar `PAGE_CACHE_ENABLED`, rodar `app:cache:clear`.
- [Descarte dentro da requisição] A requisição que sorteia o descarte lista a pasta inteira: com 2 GB de páginas de cerca de 100 KB, uns 20 mil arquivos, perto de 100 ms. → Aceitável a cada 200 gravações. `--prune` está disponível para rodar fora do horário de uso.
- [Migration das colunas geradas] O `ALTER TABLE` com coluna STORED reconstrói `production_items` e bloqueia gravações por alguns minutos. → Rodar no deploy, fora de importação. Fazer backup antes.
- [Verificação de origem] Clientes sem `Origin` e sem `Sec-Fetch-Site` passam; um proxy que reescreva `Origin` faria o painel recusar ações legítimas. → Teste funcional com e sem os cabeçalhos; conferir uma ação do painel logo depois do deploy.
- [Cabeçalhos no atalho] HIT, 304 e 429 não passam pelo ouvinte de resposta do Symfony. → O `PageCacheFastPath` envia os mesmos cabeçalhos; teste cobre os três casos.
- [HSTS] Depois do primeiro acesso, o navegador só aceita HTTPS no domínio pelo `max-age`. → O portal já funciona só em HTTPS; começar com um `max-age` moderado (ver Open Questions).
- [Symfony 7.4] Avisos de depreciação e mudanças de comportamento. → Suíte completa, `lint:twig`, `lint:yaml` e navegação manual pelo painel; rollback pelo `composer.lock` anterior.
- [Segunda fase: `git pull` remove arquivos] `composer.phar`, `node_modules/` e `scripts/node_modules/` saem do Git e o `git pull` os apaga no servidor. → Nada em produção os usa; avisar antes do pull.
- [Segunda fase: `TRUSTED_HOSTS`] Um acesso por outro nome (IP do servidor, domínio de teste) passa a receber 400. → Conferir que a variável cobre todos os acessos, inclusive o do `app:cache:warm --url`, antes de publicar.
- [Segunda fase: `serverVersion` na URL] Um `.env.local` com `serverVersion=8.0` na `DATABASE_URL` vence o `server_version` do `doctrine.yaml`. → Trocar por `8.0.40` onde existir; sem a troca nada quebra, só fica a versão menos precisa.
- [Segunda fase: SRI] Hash errado faz o navegador recusar o recurso e a página perder o gráfico. → Conferir no navegador, com o console aberto, uma página de cada tipo depois do deploy.
- [Segunda fase: CORS] Uma origem de uso legítimo que não esteja na lista perde o acesso às rotas de escrita. → Lista cobre o marcador, o script do Lattes e o portal; acrescentar a origem em `CorsListener` se surgir outra.
- [Segunda fase: aquecimento] O comando ocupa a CPU única por alguns minutos. → Rodar fora do horário de pico; pausa de 1 s entre as páginas.

## Migration Plan

Antes do deploy:

1. Conferir o espaço: `df -h /` e `du -sh var/cache/public_pages var/backups var/log`.
2. Backup: `bin/console app:database:dump --filename=antes_estabiliza_<data> --env=prod`.

Deploy (em `/home/runcloud/webapps/ufscar-cech`, com `/RunCloud/Packages/php84rc/bin/php`):

1. `git pull --ff-only`
2. `composer install --no-dev --optimize-autoloader`
3. `bin/console doctrine:migrations:migrate --no-interaction --env=prod` (aplica `Version20261112150000` e `Version20261112160000`; pode levar alguns minutos)
4. `./build.sh`
5. `bin/console cache:clear --env=prod`
6. `bin/console app:cache:clear --all --env=prod` (obrigatório: as páginas antigas não estão em gzip e não seriam lidas)

Conferência:

- `curl -sI` numa página duas vezes: a segunda com `X-Page-Cache: HIT`, `ETag` e `Content-Encoding: gzip`; com `If-None-Match`, 304.
- `curl -s /robots.txt` com as linhas novas; `curl -sI /sitemap.xml` com `Cache-Control: public, max-age=3600`.
- 31 pedidos seguidos com User-Agent de robô a páginas fora do cache: o 31º com 429.
- Cabeçalhos de segurança numa página gerada e numa do cache.
- `/api/v1/metricas`, `/busca?q=educacao`, uma página de assunto e um departamento: tempos a frio comparados com os da proposta.
- Uma ação do painel (por exemplo, limpar o cache) funcionando; `/admin/test/database` com 404.
- `du -sh var/cache/public_pages` no dia seguinte e `var/log/prod-avisos.log`.

Rollback:

1. Com o código novo ainda no lugar, voltar o banco: `bin/console doctrine:migrations:migrate 'DoctrineMigrations\Version20261108030454' --env=prod`. Isso desfaz `Version20261112160000` (recria as tabelas de teste vazias) e `Version20261112150000` (tira as colunas geradas e os índices). Tem de ser antes do checkout, porque o código antigo não tem esses arquivos de migration.
2. `git checkout <commit anterior>`
3. `composer install --no-dev --optimize-autoloader`
4. `./build.sh`, `bin/console cache:clear --env=prod` e `bin/console app:cache:clear --all --env=prod` (o código antigo ignora os `.html.gz`, que só ocupariam espaço).

## Plano da segunda fase

A segunda fase ainda não foi publicada. Antes: `df -h /` e backup (`app:database:dump --filename=antes_segunda_fase_<data> --env=prod`).

Em `/home/runcloud/webapps/ufscar-cech`, com `/RunCloud/Packages/php84rc/bin/php`:

1. Avisar que o `git pull` remove no servidor `composer.phar` e `node_modules` (não usados).
2. `git pull --ff-only`
3. `composer install --no-dev --optimize-autoloader`
4. `bin/console doctrine:migrations:migrate --no-interaction --env=prod` (aplica `Version20261112180000`)
5. `./build.sh`
6. `bin/console cache:clear --env=prod`
7. `bin/console app:cache:clear --all --env=prod`
8. Se o `.env.local` do servidor tiver `serverVersion=8.0` na `DATABASE_URL`, trocar por `serverVersion=8.0.40`.
9. Conferir que `TRUSTED_HOSTS` cobre todos os acessos ao servidor.
10. `bin/console app:cache:warm --url=https://cech.wab.com.br --env=prod`

Conferência: o `app:cache:warm` termina sem falha; uma página mostra `X-Page-Cache: HIT` na segunda visita; `curl -sI -H 'Host: outro.example' <ip>` responde 400; uma página com gráfico e outra com rede de coautoria abrem sem erro de integridade no console; `doctrine:schema:validate` só aponta `work_key` e `keywords_text`.

Rollback: antes do checkout, `doctrine:migrations:migrate 'DoctrineMigrations\Version20261112160000' --env=prod` (recria `messenger_messages` vazia e as duas chaves duplicadas); depois `git checkout <commit anterior>`, `composer install --no-dev --optimize-autoloader`, `./build.sh`, `cache:clear` e `app:cache:clear --all`.

## Open Questions

- Valor do `max-age` do HSTS (decidido em 12/11/2026): `max-age=15552000` (6 meses), sem `includeSubDomains` nem `preload`, porque outros sistemas da UFSCar podem estar em subdomínios. Pode ser ajustado sem mudar a spec.
- Limite de 30 páginas por minuto: confirmar no log de acesso, uma semana depois do deploy, se algum robô útil (Googlebot, Bingbot) está recebendo 429 com frequência.
