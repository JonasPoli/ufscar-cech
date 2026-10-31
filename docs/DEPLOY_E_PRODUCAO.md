# Guia de Deploy e Ambiente de Produção — CECH UFSCar

Este documento centraliza todos os parâmetros de conexão, caminhos absolutos, binários e procedimentos operacionais para execução de comandos no **servidor de produção online**.

---

## ⚙️ 1. Parâmetros do Servidor de Produção (Editável)

> [!NOTE]
> Se o servidor, usuário ou versão do PHP forem alterados no futuro, atualize esta tabela e o arquivo `AGENTS.md` / `GEMINI.md`.

| Parâmetro | Variável de Referência | Valor Atual em Produção | Descrição |
| :--- | :--- | :--- | :--- |
| **Painel / Gestão** | `SERVER_TYPE` | `RunCloud` | Painel de gerenciamento do servidor |
| **Usuário SSH** | `PROD_USER` | `runcloud` | Usuário do sistema para acesso SSH e cron jobs |
| **Host / IP do Servidor** | `PROD_HOST` | `104.236.71.49` | Endereço IP / Host da máquina remota |
| **Comando de Conexão SSH** | `PROD_SSH` | `ssh runcloud@104.236.71.49` | Comando para abrir sessão remota |
| **Diretório Raiz do Projeto** | `PROD_APP_PATH` | `/home/runcloud/webapps/ufscar-cech` | Caminho absoluto da aplicação |
| **Binário do PHP (CLI)** | `PROD_PHP_BIN` | `/RunCloud/Packages/php84rc/bin/php` | Caminho completo do executável PHP (v8.4) |
| **Ponto de Entrada do Console** | `PROD_CONSOLE` | `/RunCloud/Packages/php84rc/bin/php bin/console` | Comando padrão para rodar Symfony Console |

---

## 🚀 2. Procedimento Padrão de Deploy e Atualização

### Passo 1: Conectar ao Servidor e Atualizar Código
```bash
# Conectar via SSH
ssh runcloud@104.236.71.49

# Navegar até o diretório do projeto
cd /home/runcloud/webapps/ufscar-cech

# Atualizar o código do repositório
git pull origin main
```

### Passo 2: Instalar Dependências e Executar Migrations
```bash
# Atualizar dependências do Composer (se houver novas)
/RunCloud/Packages/php84rc/bin/php /usr/local/bin/composer install --no-dev --optimize-autoloader

# Aplicar migrations no banco de dados de produção
/RunCloud/Packages/php84rc/bin/php bin/console doctrine:migrations:migrate --no-interaction --env=prod
```

### Passo 3: Compilar Assets e Limpar Cache
```bash
# Recompilar CSS Tailwind minificado e AssetMapper
./build.sh

# Limpar e aquecer o cache do Symfony em produção
/RunCloud/Packages/php84rc/bin/php bin/console cache:clear --env=prod
```

---

## 📦 3. Envio de Arquivos Pesados do Banco (`docs/banco/`)

Como o diretório `docs/banco/` está no `.gitignore` para não inflar o repositório Git com arquivos grandes (ex: `TeD-UFSCar.csv` de ~98 MB), transfira os arquivos diretamente da sua máquina local para o servidor:

```bash
# Enviar o CSV do Repositório Institucional (TeD-UFSCar):
scp docs/banco/TeD-UFSCar.csv runcloud@104.236.71.49:/home/runcloud/webapps/ufscar-cech/docs/banco/

# Enviar arquivos de Tesauro (.the) ou planilhas (.xlsx):
scp docs/banco/*.the runcloud@104.236.71.49:/home/runcloud/webapps/ufscar-cech/docs/banco/
scp docs/banco/*.xlsx runcloud@104.236.71.49:/home/runcloud/webapps/ufscar-cech/docs/banco/
```

---

## 🛠️ 4. Execução de Rotinas e Commands no Servidor

Todos os comandos devem utilizar o binário oficial `/RunCloud/Packages/php84rc/bin/php`:

### 4.1. Importação do Repositório Institucional (TeD-UFSCar)
```bash
cd /home/runcloud/webapps/ufscar-cech

# Modo Simulação (Dry-Run):
/RunCloud/Packages/php84rc/bin/php bin/console app:import:repository --dry-run --env=prod

# Execução Real:
/RunCloud/Packages/php84rc/bin/php bin/console app:import:repository --env=prod
```

### 4.2. Indexação de Periódicos & Bases Internacionais
```bash
/RunCloud/Packages/php84rc/bin/php bin/console app:index:journals --env=prod
```

### 4.3. Normalização Geral de Currículos e Autores
```bash
/RunCloud/Packages/php84rc/bin/php bin/console app:curriculum:normalize --env=prod
/RunCloud/Packages/php84rc/bin/php bin/console app:thesaurus:sync-authors --env=prod
```

### 4.4. Indexação Temática e Palavras-Chave (Pesquisa Temática)
```bash
# Mineração e cálculo de ocorrências de temas nos acervos Lattes e TeD-UFSCar:
/RunCloud/Packages/php84rc/bin/php bin/console app:index-thematic-terms --env=prod

# Limpar cache de páginas públicas estáticas e pré-renderizadas:
/RunCloud/Packages/php84rc/bin/php bin/console app:cache:clear --env=prod
```

### 4.5. Sincronização Automática do Banco Online para o Local (Script Único)
Para exportar a base de dados online, baixar via SCP e restaurar automaticamente no seu ambiente de desenvolvimento com um único comando no seu Mac:

```bash
./sync-db.sh
```

Ou manualmente pelo terminal do servidor:
```bash
/RunCloud/Packages/php84rc/bin/php bin/console app:database:dump --env=prod
```

### 4.5. Gerenciamento de Administradores
```bash
/RunCloud/Packages/php84rc/bin/php bin/console app:admin-user <usuario> <senha> --env=prod
```

### 4.6. Periódicos por base, áreas CAPES e tesauro

Os dados de periódicos (a página `/periodicos`, as áreas CAPES e os vínculos dos artigos) são publicados pelo `bin/publish-journals.sh`. O script roda na sua máquina, envia os artefatos ao servidor e executa o pipeline lá.

#### Publicação

```bash
# Publicação completa: artefatos, código, migrations e pipeline
bin/publish-journals.sh

# Só artefatos e pipeline, para listas novas (não atualiza o código)
bin/publish-journals.sh --data-only

# Só confere as pré-condições e sai, sem enviar nada
bin/publish-journals.sh --check
```

O commit e o push vêm antes: a publicação completa só roda com a árvore Git limpa e o `HEAD` igual ao `origin/main`. Na ordem, o script faz:

1. **Pré-condições**: os dois artefatos obrigatórios existem (`journal_evidence.jsonl.gz` e `scielo_articlemeta.json`), o primeiro passa no `gzip -t` e nenhum nome está duplicado em `journal_name_variants` no banco de produção. Com duplicado, a migration de unicidade em `normalized_name` abortaria: mescle os periódicos no admin.
2. **Artefatos**: envia para `docs/banco/derived/` no servidor os arquivos listados na seção abaixo.
3. **Backup**: `app:database:dump` grava `var/backups/antes_periodicos_<data>.sql.zip` antes de qualquer alteração no banco.
4. **Código** (só na publicação completa): `git pull --ff-only`, `composer install` se o `composer.lock` mudou, migrations, `./build.sh` e `cache:clear`.
5. **Pipeline**: roda `app:journals:pipeline` e grava o log em `var/audit/pipeline-<data>.log`.
6. **Conferência**: mostra o código HTTP de `/periodicos`, `/periodicos/scopus`, `/periodicos?area=educacao`, `/indicadores` e `/`, em `https://cech.wab.com.br`. Uma URL que não responda 200 não para o script: confira a saída antes de dar a publicação por feita.

`--check` avisa sobre commit e push pendentes, mas não para por causa deles. Os valores padrão são os da seção 1; as variáveis `PROD_USER`, `PROD_HOST`, `PROD_APP_PATH`, `PROD_PHP_BIN` e `PROD_URL` os substituem.

#### Gerando os artefatos nesta máquina

Os artefatos ficam em `docs/banco/`, que está no `.gitignore`. Os comandos abaixo rodam aqui, não no servidor, nesta ordem:

```bash
# Snapshot de periódicos do OpenAlex (CC0, sem chave), em docs/banco/lists-to-import/openalex/snapshot
php bin/console app:journals:evidence:fetch-openalex

# Cadastro da coleção SciELO (ArticleMeta), em docs/banco/derived/scielo_articlemeta.json
php bin/console app:journals:evidence:fetch-scielo

# Evidências de área, em docs/banco/derived/journal_evidence.jsonl.gz
php bin/console app:journals:evidence:build
```

Os dois comandos de download aceitam `--limit=N` para um teste rápido. As listas de Scopus, Web of Science, DOAJ, Latindex e PubMed não têm comando de download: ficam à mão em `docs/banco/lists-to-import/`, uma pasta por base (`scopus/`, `wos/` ou `webofscience/`, `doaj/`, `latindex/` e `pubmed/`). O `build` aceita `--skip=` para deixar fontes de fora. Rode o `fetch-scielo` antes do `build`: sem o JSON, o build segue sem SciELO e mostra um aviso, mas o `publish-journals.sh` recusa a publicação.

Em `docs/banco/derived/`, o script envia e o pipeline lê:

| Arquivo | Gerado por | Passo do pipeline |
| :--- | :--- | :--- |
| `journal_evidence.jsonl.gz` | `app:journals:evidence:build` | `evidence-load`, `families-first`, `families`, `families-final` |
| `scielo_articlemeta.json` | `app:journals:evidence:fetch-scielo` | `scielo-enrich` |
| `ai/*.out.jsonl` | subagentes, a partir de `ai/batch_*.jsonl` | `ai-import` |
| `ai_titles/*.out.jsonl` | subagentes, a partir de `ai_titles/title_*.jsonl` | `ai-import` |
| `overrides/names.jsonl` | curadoria (JSONL): `{"type":"name","name":"...","journal":{"issn":"..."},"how":"..."}` aceita um nome; `{"type":"name_reject","name":"...","how":"..."}` rejeita. `how` aparece na tela da fila como autor da decisão | `overrides-names` |
| `overrides/areas.jsonl` | curadoria (JSONL) | `overrides-areas` |

Só os dois primeiros são obrigatórios. Sem um arquivo opcional, o passo correspondente é pulado.

#### O que `app:journals:pipeline` faz

O pipeline roda os passos abaixo em ordem e para no primeiro erro. Ele não aplica migrations: a publicação completa as aplica antes, mas num pipeline manual rode `doctrine:migrations:migrate` antes.

| # | Passo | O que faz |
| :--- | :--- | :--- |
| 1 | `normalize-titles` | Preenche as formas normalizadas dos títulos |
| 2 | `audit-before` | Grava o snapshot dos vínculos artigo→periódico em `var/audit/` |
| 3 | `scielo-enrich` | Completa os ISSNs dos periódicos SciELO que não têm um válido |
| 4 | `area-thesaurus-first` | Semeia o tesauro de categorias, para a evidência legada já usar o tesauro |
| 5 | `evidence-load` | Carrega as evidências de área no banco, com linhas faltantes e evidência legada |
| 6 | `families-first` | Agrupa as linhas de cada periódico em famílias, sem mesclar nada |
| 7 | `index` | Indexa os periódicos nas produções (`app:index:journals`) |
| 8 | `families` | Refaz as famílias depois da indexação |
| 9 | `learn` | Aprende grafias do Lattes confirmadas pelo ISSN |
| 10 | `overrides-names` | Aplica `overrides/names.jsonl`, com as decisões da fila de nomes |
| 11 | `learn-external` | Grava como variantes os títulos das listas externas confirmados pelo ISSN (`app:journals:names:learn-external`) |
| 12 | `families-final` | Refaz as famílias com os agrupamentos manuais e estende às linhas de cada família os vínculos com bases |
| 13 | `index-again` | Reindexa |
| 14 | `audit-after` | Compara os vínculos com o snapshot do passo 2 |
| 15 | `area-thesaurus` | Atualiza o tesauro de categorias (`app:journals:areas:thesaurus`): sementes, contagens e rótulos pendentes |
| 16 | `title-rules` | Cria evidência pelas regras de título |
| 17 | `classify` | Classifica as famílias nas áreas CAPES |
| 18 | `ai-import` | Importa `ai/*.out.jsonl` e `ai_titles/*.out.jsonl`, com o modelo `claude-haiku` |
| 19 | `overrides-areas` | Aplica `overrides/areas.jsonl` |
| 20 | `classify-again` | Classifica de novo, com as evidências novas |
| 21 | `report` | Imprime o relatório de periódicos |
| 22 | `cache-clear` | Limpa o cache das páginas públicas |

Opções, com os nomes de passo da tabela:

```bash
# Só estes passos
/RunCloud/Packages/php84rc/bin/php bin/console app:journals:pipeline --only=normalize-titles,audit-before --env=prod

# Todos, menos estes
/RunCloud/Packages/php84rc/bin/php bin/console app:journals:pipeline --skip=ai-import --env=prod

# Deste passo até o fim
/RunCloud/Packages/php84rc/bin/php bin/console app:journals:pipeline --from=classify --env=prod
```

- `--only` e `--skip` aceitam vários nomes, separados por vírgula ou repetindo a opção. `--from` é o ponto de partida: os passos antes dele não rodam, e os outros filtros valem a partir dele.
- Nome de passo desconhecido aborta antes de qualquer alteração no banco.
- Antes de começar, o pipeline confere os artefatos dos passos escolhidos e para se faltar algum.
- `audit-after` compara com o snapshot de `audit-before` da mesma execução. Sem ele (com `--from`, `--only` ou `--skip`), usa o snapshot mais recente em `var/audit/` e avisa.
- Se o pipeline parar, corrija a causa e rode de novo com `--from=<passo que falhou>`.

#### Conferência

```bash
/RunCloud/Packages/php84rc/bin/php bin/console app:journals:report --env=prod

# Vínculos: snapshot antes da reindexação e comparação depois
/RunCloud/Packages/php84rc/bin/php bin/console app:journals:links:audit --snapshot=var/audit/vinculos-antes.json --env=prod
/RunCloud/Packages/php84rc/bin/php bin/console app:journals:links:audit --compare=var/audit/vinculos-antes.json --env=prod
```

`app:journals:report` só lê: mostra famílias, áreas, bases, variantes e a fila de nomes.

#### Classificação por IA (passo offline)

A classificação por IA roda só nesta máquina, sem chave de API. O código não chama nenhum serviço de IA, e nenhuma chave vai para produção. Os lotes são gerados aqui, subagentes gravam as respostas (`.out.jsonl`) e a publicação envia só essas respostas, não os lotes. A IA dá evidência; quem decide a área é a classificação.

```bash
# 1. Gera os lotes e o PROMPT.md em docs/banco/derived/ai/
php bin/console app:journals:ai:export

# 2. Subagentes: cada um lê o PROMPT.md e um lote e grava o .out.jsonl de mesmo nome, na mesma pasta

# 3. Importa como evidência ai (para conferir aqui; na publicação, o passo ai-import faz isso)
php bin/console app:journals:ai:import docs/banco/derived/ai/*.out.jsonl

# 4. Aplica a classificação
php bin/console app:journals:classify
```

- `--scope=visible` (padrão) exporta lotes de 40 periódicos das famílias sem área ou com confiança `low`. `--include-medium` inclui a confiança `medium`.
- `--scope=unclassified` faz a segunda passada, só com título e país, em `docs/banco/derived/ai_titles/` (lotes de 250).
- O `ai:import` lista e pula as linhas inválidas. Reimportar um arquivo substitui a evidência que ele gravou antes, então é seguro. A opção `--model` grava o nome do modelo na evidência (padrão `claude-sonnet`; o pipeline usa `claude-haiku`).

### 4.7. Importação de currículos Lattes

Use `bin/import-curriculos.sh` a partir do Mac. Ele faz backup, envia os XML, importa e atualiza palavras-chave, revistas, temas e cache. O guia completo e o mapa do XML estão em [LATTES_IMPORT.md](LATTES_IMPORT.md).

```bash
bin/import-curriculos.sh --check pasta-com-xmls/
bin/import-curriculos.sh --dept="ED|Departamento de Educação" pasta-com-xmls/
```

---

## 🔄 5. Como Alterar as Configurações de Produção no Futuro

Caso o servidor seja migrado ou a versão do PHP seja atualizada:

1. **Troca de versão do PHP**: Se mudar para PHP 8.5 no RunCloud, substitua `/RunCloud/Packages/php84rc/bin/php` por `/RunCloud/Packages/php85rc/bin/php`.
2. **Troca de Servidor/Pasta**: Edite os valores na Seção 1 deste documento (`docs/DEPLOY_E_PRODUCAO.md`) e nos arquivos [`AGENTS.md`](file:///Users/jonaspoli/work/html/ufscar-cech/AGENTS.md) e [`GEMINI.md`](file:///Users/jonaspoli/work/html/ufscar-cech/GEMINI.md).
3. Toda a assistência da IA utilizará automaticamente os novos caminhos informados nesses arquivos.
