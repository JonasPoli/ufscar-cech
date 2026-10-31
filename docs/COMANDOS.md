# Comandos e scripts

Catálogo de tudo o que roda no terminal. Em produção, use `/RunCloud/Packages/php84rc/bin/php bin/console <comando> --env=prod`.

## Scripts

| Script | O que faz | Guia |
|---|---|---|
| `bin/import-curriculos.sh` | Importa XML do Lattes em produção: backup, importação, teses do Repositório, revistas, temas e cache | [LATTES_IMPORT.md](LATTES_IMPORT.md) |
| `bin/publish-journals.sh` | Publica código, migrations, artefatos de revistas e pipeline, com backup | [DEPLOY_E_PRODUCAO.md](DEPLOY_E_PRODUCAO.md) |
| `build.sh` | Compila Tailwind e o asset map | |
| `sync-db.sh` | Traz o banco de produção para o local. **Substitui o banco local** | [DEPLOY_E_PRODUCAO.md](DEPLOY_E_PRODUCAO.md) |
| `scripts/browser_lattes_crawler.js` | Marcador de navegador que envia o currículo aberto ao portal | [LATTES_IMPORT.md](LATTES_IMPORT.md) |
| `scripts/lattes_photo_downloader.py`, `scripts/crawl_lattes_stealth.js` | Fotos da plataforma Lattes | [LATTES_IMPORT.md](LATTES_IMPORT.md) |

## Currículos

| Comando | O que faz | Opções | Guia |
|---|---|---|---|
| `app:import:lattes` | Importa XML do Lattes. Substitui a produção de quem já existe; pula XML mais antigo que o banco. | `--dir`, `--file`, `--dept`, `--force` | [LATTES_IMPORT.md](LATTES_IMPORT.md) |
| `app:curriculum:normalize` | Liga autores a docentes e ao tesauro, revistas e instituições, para todos os docentes. | `--dept`, `--only-pending`, `--clear-cache` |  |
| `app:sync:keywords` | Só para dados antigos: grava palavras-chave dos XML em produções que não as têm. O importador já as grava. | `--dir`, `--file` | [SISTEMA_DE_PALAVRAS_CHAVE.md](SISTEMA_DE_PALAVRAS_CHAVE.md) |
| `app:import:excel` | Complementa departamento, ORCID e anos de entrada e saída a partir da planilha de docentes. | `--info-file` |  |
| `app:import:repository` | Importa e enriquece teses e dissertações do Repositório UFSCar. | `--file`, `--center`, `--researcher`, `--limit`, `--dry-run` | [REPOSITORY_IMPORT.md](REPOSITORY_IMPORT.md) |
| `app:thesaurus:sync-authors` | Completa o tesauro de autores. Só acrescenta; `--clean` apaga e refaz, com confirmação. | `--clean`, `--researchers-only` |  |
| `app:index:topics` | Reconstrói o índice da pesquisa temática. Troca as tabelas de uma vez: se falhar, o índice antigo fica. |  | [SISTEMA_DE_PALAVRAS_CHAVE.md](SISTEMA_DE_PALAVRAS_CHAVE.md) |

## Fotos

| Comando | O que faz | Opções | Guia |
|---|---|---|---|
| `app:import:photos` | Importa fotos em lote, de uma pasta ou ZIP. | `--dir`, `--zip` |  |
| `app:crawl:lattes-photos` | Tenta baixar fotos da plataforma Lattes. | `--id`, `--limit`, `--all` |  |
| `app:photos:generate-webp` | Gera as versões WebP de todas as fotos. | `--force` |  |

## Revistas: uso geral

| Comando | O que faz | Opções | Guia |
|---|---|---|---|
| `app:journals:pipeline` | Roda em ordem todos os passos de revistas, parando no primeiro erro. É o que a publicação usa. | `--derived`, `--only`, `--skip`, `--from` | [DEPLOY_E_PRODUCAO.md](DEPLOY_E_PRODUCAO.md) |
| `app:journals:report` | Relatório só de leitura: famílias, áreas, bases, variantes e fila. |  |  |
| `app:index:journals` | Liga os artigos às revistas e grava Qualis e bases. Limpa o vínculo de item que não é artigo. |  |  |
| `app:import-journal-database-list` | Importa a lista de uma base (CSV ou XLSX) e liga as revistas a ela. | `--base` |  |
| `app:export-journals-thesaurus` | Exporta revistas, Qualis, bases e tesauro em CSV ou JSON, por família. | `--format`, `--output`, `--qualis`, `--base`, `--thesaurus`, `--key` |  |

## Revistas: passos do pipeline

| Comando | O que faz | Opções | Guia |
|---|---|---|---|
| `app:journals:normalize-titles` | Preenche as formas normalizadas dos títulos e separa ISSNs colados. | `--only-missing` |  |
| `app:journals:links:audit` | Grava um retrato dos vínculos artigo-revista, ou compara com um retrato anterior. | `--snapshot`, `--compare`, `--json` |  |
| `app:journals:scielo:enrich` | Completa ISSNs de revistas do SciELO sem ISSN válido. | `--dry-run` |  |
| `app:journals:evidence:fetch-openalex` | Baixa o snapshot de periódicos do OpenAlex. Roda no Mac. | `--dir`, `--manifest`, `--limit` |  |
| `app:journals:evidence:fetch-scielo` | Baixa o cadastro do SciELO. Roda no Mac. | `--out`, `--limit` |  |
| `app:journals:evidence:build` | Junta todas as listas no artefato de evidências. Roda no Mac. | `--sources`, `--derived`, `--out`, `--vocab-dir`, `--skip` |  |
| `app:journals:evidence:load` | Carrega as evidências no banco. | `--create-missing-for-articles`, `--legacy`, `--title-rules`, `--dry-run` |  |
| `app:journals:families` | Agrupa as linhas da mesma revista e grava o nome exibido, sem apagar nada. | `--dry-run`, `--artifact` |  |
| `app:journals:names:learn` | Aprende grafias do Lattes confirmadas pelo ISSN. | `--dry-run`, `--min-articles`, `--only` | [THESAURUS.md](THESAURUS.md) |
| `app:journals:names:learn-external` | Grava como variantes os títulos das listas externas confirmados pelo ISSN. | `--file`, `--dry-run` | [THESAURUS.md](THESAURUS.md) |
| `app:journals:names:queue` | Reconstrói a fila de nomes e mostra as contagens. | `--rebuild`, `--stats` |  |
| `app:journals:overrides:import` | Aplica decisões curadas: área, nome, rejeição, família, nome exibido. | `--dry-run` |  |
| `app:journals:areas:thesaurus` | Semeia o tesauro de categorias, conta o uso e lista pendentes. | `--dry-run` | [THESAURUS.md](THESAURUS.md) |
| `app:journals:classify` | Classifica as famílias nas áreas CAPES. | `--run-id`, `--journal`, `--dry-run` |  |
| `app:journals:ai:export` | Exporta lotes para classificação por IA. Roda no Mac. | `--out`, `--scope`, `--batch-size`, `--include-medium` |  |
| `app:journals:ai:import` | Importa as respostas da IA como evidência. | `--model` |  |

## Carga inicial do Bibliomap

| Comando | O que faz | Opções | Guia |
|---|---|---|---|
| `app:import-bibliomap-journals` | **Apaga** revistas e variantes e copia do Bibliomap. Recusa se houver dados, salvo `--force` com confirmação. | `--force` |  |
| `app:import-bibliomap-databases` | **Apaga** as bases e os vínculos e copia do Bibliomap. Mesma proteção. | `--force` |  |
| `app:import:bibliomap` | **Apaga** países, estados, cidades, instituições e autores e copia do Bibliomap. Mesma proteção. | `--force`, `--source-db` |  |

## Banco, cache e manutenção

| Comando | O que faz | Opções | Guia |
|---|---|---|---|
| `app:database:dump` | Gera um backup do banco em `var/backups`. | `--no-zip`, `--filename` |  |
| `app:database:restore` | Restaura um backup. **Substitui o banco.** | `--latest`, `--force` |  |
| `app:cache:clear` | Limpa o cache das páginas públicas. | `--all` |  |
| `app:admin-user` | Cria um usuário administrador. | `--option1` |  |
| `app:geography:seed-historical-countries` | Carrega países históricos, com anos de fundação e extinção. |  |  |
| `app:generate-index` | Ferramenta de desenvolvimento: gera ou altera o `index.html.twig` de uma entidade. |  |  |
