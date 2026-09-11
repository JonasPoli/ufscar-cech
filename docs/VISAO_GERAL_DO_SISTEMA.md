# Visão Geral do Sistema — Para que serve o Portal CECH/UFSCar

> Documento de entrada para quem chega ao projeto. Explica **o problema que o sistema resolve**,
> **quem o utiliza**, **o que ele faz** e **como os dados circulam**. Os detalhes técnicos de
> modelagem estão em [`ARCHITECTURE.md`](ARCHITECTURE.md) e [`ARQUITETURA_E_PROCESSOS.md`](ARQUITETURA_E_PROCESSOS.md).

---

## 1. O problema

A produção científica do **Centro de Educação e Ciências Humanas (CECH)** da **UFSCar** existe,
mas está espalhada: cada docente mantém seu **Currículo Lattes** no CNPq, as teses e dissertações
ficam no **Repositório Institucional (TeD-UFSCar)**, e os periódicos têm estratos **Qualis** e
indexações internacionais (Scopus, Web of Science, SciELO…) registrados em outras bases.

Consultar essa produção, diretamente, é inviável para três perguntas que o Centro precisa responder
o tempo todo:

1. **Quem pesquisa o quê?** — encontrar docentes por tema, palavra-chave ou área.
2. **Quanto e onde publicamos?** — indicadores agregados por departamento, ano, estrato Qualis e base internacional.
3. **Quem é quem?** — o mesmo autor aparece como "Silva, J.", "SILVA, João P." e "Joao Paulo da Silva";
   a mesma instituição, como "UFSCar", "Univ. Federal de São Carlos" e "Federal University of São Carlos".

O Lattes é a fonte da verdade, mas **não é uma base consultável**: é um currículo por pessoa, sem
padronização de nomes, sem agregação e sem cruzamento entre currículos.

## 2. O que o sistema faz

O Portal CECH ingere os currículos, **preserva o dado bruto**, e constrói ao lado dele uma camada
de **normalização, desambiguação e indexação** que torna o acervo pesquisável e mensurável.
Sobre essa camada, entrega dois produtos:

| Produto | Público | Acesso |
| :--- | :--- | :--- |
| **Portal público** | Comunidade acadêmica, candidatos a pós-graduação, imprensa, sociedade | Sem login |
| **Painel administrativo** | Equipe de curadoria do CECH / biblioteca | `/admin`, exige `ROLE_ADMIN` |

### Portal público (sem login)

- **Home** (`/`) — métricas institucionais, publicações recentes com link DOI, docentes em destaque.
- **Perfil do docente** (`/professor/{slug}`) — biografia, vínculos, Lattes/ORCID, produções por
  categoria, orientações, nuvens de palavras-chave, rede de coautoria, bases internacionais e
  exportação individual em **BibTeX, JSON e CSV**.
- **Pesquisa temática** (`/temas`) — descoberta por conceito, cruzando palavras-chave do Lattes e do
  repositório institucional, com autocomplete e distribuição por departamento.
- **Indicadores** (`/indicadores`) — 18 figuras cienciométricas (corpo docente, formação de recursos
  humanos, produção/Qualis, redes de colaboração), carregadas por aba sob demanda.
- **Busca** (`/busca`) e **Departamentos** (`/departamentos`).
- **SEO** — `sitemap.xml` e `robots.txt` gerados dinamicamente.

### Painel administrativo (`/admin`)

- **Currículos** — importação de XML do Lattes (individual ou em lote), colagem de HTML, bookmarklet
  que sincroniza o currículo aberto no navegador, upload/coleta de fotos, exportação em PDF/CSV/JSON/XML.
- **Indexação** — processamento em lote que vincula coautores do CECH, identidades de autores,
  periódicos Qualis canônicos e bases indexadoras.
- **Tesauros (BiblioMap)** — curadoria de autores, instituições, países/cidades e periódicos:
  cadastro de variantes, fusão (*merge*) de duplicatas e importação/exportação em VantagePoint (`.the`), CSV, JSON e XML.
- **Bases acadêmicas** — cadastro de Scopus, WoS, SciELO, DOAJ etc. e importação das listas de periódicos.
- **Backup** — superdump completo do MySQL com progresso em tempo real (SSE), download `.sql.zip` e restauração.
- **Relatórios**, **SEO**, **cache de páginas** e **usuários**.

## 3. Volume de dados

Números do banco de desenvolvimento em 11/09/2026 (cópia da produção), úteis como ordem de grandeza:

| Conteúdo | Registros |
| :--- | ---: |
| Pesquisadores (currículos) | 415 |
| Itens de produção | 76.652 |
| — dos quais artigos | 13.469 |
| Orientações | 26.963 |
| Periódicos com Qualis | 83.528 |
| Instituições (tesauro) | 9.228 |
| Identidades de autores (tesauro) | 20.987 |
| Termos temáticos indexados | 0 (exige `app:index:topics`) |

Os docentes estão distribuídos por códigos de departamento (`PS`, `LE`, `AC`, `CS`, `IFD`, `ED`,
`TPP`, `FI`, `CI`, `SO`, `CA`) gravados no próprio currículo ou na planilha institucional.

## 4. Como o dado circula

```
   Lattes (XML)          Lattes (HTML, via navegador)        Planilha CECH        TeD-UFSCar (CSV)
        │                          │                              │                      │
        ▼                          ▼                              ▼                      ▼
 LattesXmlParser          LattesHtmlParser +            ExcelCechImporter        RepositoryImport
        │                 POST /api/curriculum/import-html         │                      │
        └──────────────┬───────────┴──────────────────────────────┴──────────────────────┘
                       ▼
            Dados BRUTOS preservados
        (researchers, production_items, orientations…)
                       │
                       ▼
      Normalização e indexação (colunas NOVAS, nunca sobrescrevendo o bruto)
   AuthorResolver · JournalResolver · InstitutionResolver · CountryResolver · ThematicTermIndex
   → matched_researcher_id, author_identity_id, qualis_journal_id, institution_id,
     is_cech_researcher, indexed_databases, last_indexed_at
                       │
                       ▼
        StatisticsService + Repositories (agregações SQL)
                       │
                       ▼
        Portal público (Twig + Chart.js)  ·  cache de páginas em disco
```

**Ponto central:** a ingestão nunca edita o dado original. Todo enriquecimento vai para colunas
adicionais. É isso que permite reimportar um currículo do Lattes a qualquer momento sem perder a
curadoria já feita — e é a razão de a regra estar repetida em `README.md`, `AGENTS.md` e `GEMINI.md`.

## 5. Regras fixas do projeto

1. **Preservação do dado Lattes** — nunca alterar, formatar ou apagar o que veio do Lattes;
   normalização apenas em colunas novas.
2. **Somente migrations** — nunca `doctrine:schema:update`; toda mudança de esquema é uma migration
   versionada, aplicada com `doctrine:migrations:migrate` (inclusive em `APP_ENV=test`).
3. **Tesauros como autoridade** — a desambiguação de autores, instituições, países e periódicos é
   curada no painel, não no código.

## 6. Rotinas de console mais usadas

```bash
php bin/console app:import:lattes --dir=docs/banco/CECH   # importa currículos XML em lote
php bin/console app:curriculum:normalize                  # coautoria CECH, autores, instituições
php bin/console app:index:journals                        # periódicos canônicos, Qualis e bases
php bin/console app:index:topics                          # vocabulário da pesquisa temática
php bin/console app:database:dump                         # superdump .sql.zip
php bin/console app:database:restore --latest             # restaura o backup mais recente
php bin/console app:cache:clear                           # limpa o cache HTML de páginas públicas
php bin/console app:admin-user <usuario> <senha>          # cria administrador
```

## 7. Pontos de atenção operacionais

- **Cache de páginas públicas** — respostas HTML são gravadas em `var/cache/public_pages` por até
  30 dias, controlado por `PAGE_CACHE_ENABLED`. Após importar ou reindexar dados, limpe o cache
  (`app:cache:clear` ou `/admin/cache`), senão o portal continua exibindo a versão antiga.
  Requisições com cookie de sessão e as rotas `/admin`, `/login`, `/api` e `/busca` nunca são cacheadas.
- **Token de sincronização** — o bookmarklet e o script de console usam `POST /api/curriculum/import-html`,
  que exige o cabeçalho `X-Cech-Sync-Token`. O token é derivado do `APP_SECRET` e aparece pronto,
  dentro do script, em `/admin/curriculum/new`. Trocar o `APP_SECRET` invalida os bookmarklets salvos.
- **`docs/banco/`** — fora do versionamento (arquivos grandes). Alguns testes são pulados sem ele.
- **Fotos** — ficam em `public/uploads/photos/{idLattes}.jpg|png`, com variantes `.webp` e `-256.webp`
  geradas automaticamente e consumidas pelas tags `<picture>` do portal.

## 8. Stack

PHP 8.2+ · Symfony 7.2 · Doctrine ORM 3 · MySQL 8.0 (`utf8mb4_unicode_ci`) · Twig · Tailwind CSS ·
Shoelace · Chart.js · DataTables · Dompdf · PhpSpreadsheet · League CSV · PHPUnit.

## 9. Para onde ir depois

| Preciso de… | Documento |
| :--- | :--- |
| Modelagem, entidades e serviços | [`ARCHITECTURE.md`](ARCHITECTURE.md) |
| Fluxos de ingestão e enriquecimento | [`ARQUITETURA_E_PROCESSOS.md`](ARQUITETURA_E_PROCESSOS.md) |
| Campos e colunas do banco | [`DICIONARIO_DE_DADOS_E_VARIAVEIS.md`](DICIONARIO_DE_DADOS_E_VARIAVEIS.md) |
| Operação do painel | [`ADMIN_GUIDE.md`](ADMIN_GUIDE.md) |
| Deploy e produção | [`DEPLOY_E_PRODUCAO.md`](DEPLOY_E_PRODUCAO.md) |
| Tesauros e desambiguação | [`THESAURUS.md`](THESAURUS.md) |
| Parsers do Lattes | [`LATTES_IMPORT.md`](LATTES_IMPORT.md) |
| Pesquisa temática | [`SISTEMA_DE_PALAVRAS_CHAVE.md`](SISTEMA_DE_PALAVRAS_CHAVE.md) |
| Catálogo das 18 figuras | [`graficos.md`](graficos.md) |
| Achados da revisão de código | [`CODE_REVIEW.md`](CODE_REVIEW.md) |
