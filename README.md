# Portal de Produção Científica & Acadêmica — CECH / UFSCar

Sistema de Mapeamento, Desambiguação de Autoridades, Inteligência Cienciométrica e Catálogo da Produção Intelectual do **Centro de Educação e Ciências Humanas (CECH)** da **Universidade Federal de São Carlos (UFSCar)**.

Inspirado na arquitetura e design do sistema *Somos UFMG* e integrado ao ecossistema de tesauros controlados *BiblioMap*.

---

## 🚀 Visão Geral das Funcionalidades

### 🌐 Portal Público (Sem necessidade de login)
- **Home Interativa**: Métricas institucionais em tempo real, busca instantânea, departamentos acadêmicos, publicações recentes com links DOI (abertura em nova aba) e destaques docentes.
- **Painel de Indicadores Cienciométricos (`/indicadores`)**:
  - **19 Figuras Analíticas e Gráficos Interativos (Chart.js)** divididos em 4 blocos temáticos:
    1. *Panorama Geral & Produção Científica* (Evolução temporal, distribuição tipológica, produção por departamento, tipologia por departamento, taxa de docentes produtivos e média anual).
    2. *Formação de Recursos Humanos & Orientações* (Evolução anual, orientações concluídas vs. em andamento, distribuição por nível acadêmico e orientações por departamento).
    3. *Qualidade, Periódicos & Bases Internacionais* (Estratos Qualis CAPES, periódicos mais frequentes, evolução temporal Qualis, **Figura 15: Produção por Base de Indexação Científica** e **Figura 16: Séries Históricas de Indexação 2010–2026**).
    4. *Redes de Colaboração & Parcerias Institucionais* (Figura 17: Rede de Coautoria Docente com deduplicação de obras conjuntas, Figura 18: Top Parcerias Nacionais e Figura 19: Parcerias Internacionais).
- **Busca Avançada no Catálogo (`/busca`)**: Pesquisa por pesquisador, artigo, DOI, ISSN, ISBN, evento ou periódico, com filtros por departamento e tipologia.
- **Perfil Completo do Pesquisador (`/professor/{slug}`)**:
  - Dados biográficos, foto/avatar, vínculos e afiliações ativas e históricas.
  - Links externos diretos: **Currículo Lattes**, **ORCID** (com badge e link oficial) e e-mail.
  - **Painel de Gráficos e Inteligência de Produção**:
    - Histórico anual por categoria e distribuição donut.
    - Histórico de orientações concluídas por nível.
  - **Painel de Bases Científicas Internacionais (Scopus, WoS, Latindex, etc.)**:
    - Cartões de destaque com o volume de artigos indexados e % sobre o total.
    - Ranking de bases (Scopus `#1`, Web of Science `#2`, Latindex `#3`, etc.) com logotipos oficiais.
    - Gráfico de barras horizontais dos trabalhos por base internacional.
    - Gráfico multi-linhas da evolução temporal da indexação por base.
  - **Rede de Coautoria & Principais Colaboradores**: Mapeamento dos parceiros mais frequentes com avatares e links de perfil.
  - **Temas e Termos Frequentes**: Extração estatística de palavras-chave dos títulos (bigramas com remoção de stopwords acadêmicas).
  - **Busca Direta de Trabalhos e Orientações**: Botão de pesquisa no Google e Google Scholar com ícone estilizado.
  - Formação acadêmica, titulações, prêmios, áreas de atuação e produções em abas interativas com badges Qualis e bases indexadoras.
  - **Exportação Acadêmica Individual**: Download imediato em formatos BibTeX, JSON e CSV.
- **Diretório de Departamentos (`/departamentos`)**: Catálogo dos 8 departamentos do CECH (`DEn`, `DFis`, `DFil`, `DGeo`, `DCSo`, `DPsi`, `DL`, `DCI`) com listagem de docentes e métricas agregadas.
- **Suporte a Modo Claro e Modo Escuro (Dark/Light Mode)**: Alternância persistente no navegador.

---

### 🛡️ Painel Administrativo (`/admin`)
- **Dashboard Institucional**: Contadores em tempo real de pesquisadores, produções, instituições, países, autores e bases.
- **Gestão de Currículos Lattes (`/admin/curriculum`)**:
  - Tabela interativa com DataTables, pesquisa instantânea e paginação.
  - Filtro avançado por departamento acadêmico.
  - **Exportação de Dados Filtrados**: Formatos CSV, JSON e XML.
  - **Exportação Individual em PDF**: Geração de currículo formatado para impressão/arquivo via Dompdf.
  - **Importador Web de XMLs**: Upload em lote de múltiplos arquivos XML da Plataforma Lattes.
- **Indexação & Normatização de Dados (`/admin/indexing`)**:
  - Painel de controle de indexação massiva com processamento assíncrono sequencial, barra de progresso, percentual, contagem de tempo decorrido/restante e botão de pausa/cancelamento.
  - Botão dedicado **"Indexar Periódicos & Bases Internacionais"** para resolução em lote dos 13.469 artigos.
  - Vinculação de coautores CECH (`is_cech_researcher`), identidades de autores (`author_identity_id`), periódicos Qualis canônicos (`qualis_journal_id`) e bases indexadoras (`indexed_databases`).
- **Gestão de Bases de Indexação Internacional (`/admin/academic-databases`)**:
  - Cadastro de bases científicas (Scopus, Web of Science, PubMed, SciELO, DOAJ, Latindex, OpenAlex, Crossref, etc.) com logotipos, siglas e URLs.
  - Importação de listas de periódicos nos formatos CSV, Excel, TXT e VantagePoint.
- **Backup & Superdump Completo do Sistema (`/admin/database/backup`)**:
  - Painel de geração de superdump completo do MySQL em tempo real com streaming Server-Sent Events (SSE).
  - Download imediato de pacote `.sql.zip` com compactação otimizada.
  - Histórico de backups em disco com opções de download e exclusão.
- **Tesauros e Vocabulários Controlados (BiblioMap)**:
  - **Tesauro de Países (`/admin/countries`)**: Cadastro de termos padronizados, códigos ISO Alpha-2/Alpha-3, sinônimos/variantes, fusão (*merge*) de duplicatas e importação/exportação em VantagePoint (`.the`), CSV, JSON e XML.
  - **Tesauro de Instituições (`/admin/institutions`)**: Cadastro de universidades/institutos, siglas, naturezas jurídicas, variantes de grafia, fusão de entidades e importação/exportação multi-formato.
  - **Tesauro de Autores (`/admin/authors`)**: Normalização de nomes de autores, variantes de citação bibliográfica, identificadores externos (ORCID, Lattes ID), desambiguação e fusão de autoridades.
  - **Tesauro de Periódicos (`/admin/journals`)**: Catálogo de 63.122 periódicos com estratos Qualis, ISSNs normalizados (`issn_e`, `issn_l`, `issn_imp`) e vínculos com bases internacionais.
- **Relatórios Institucionais (`/admin/reports`)**:
  - Relatório de Docentes e Produções por Departamento (com exportação CSV e JSON).
  - Relatório de Distribuição de Artigos por Estrato Qualis (com exportação CSV e JSON).
- **Gestão de Usuários (`/admin/users`)**: CRUD de administradores e operadores do sistema com controle de permissões (RBAC).

---

## 🔒 Diretrizes e Regras Fixas do Projeto

1. **Regra Fixa de Preservação de Dados Lattes**:
   - **Nunca alterar, formatar, apagar ou adulterar o dado bruto recebido do Lattes**. Todos os dados recebidos (nomes, citações, títulos, periódicos, instituições) permanecem estritamente intactos.
   - Todo processo de normatização, enriquecimento ou indexação é gravado exclusivamente em **colunas novas/adicionais** no banco de dados (`matched_researcher_id`, `author_identity_id`, `qualis_journal_id`, `institution_id`, `is_cech_researcher`, `indexed_databases`).

2. **Regra Estrita de Banco de Dados: Uso Exclusivo de Migrations**:
   - **Nunca use `doctrine:schema:update`**. Todas as alterações de estrutura do banco de dados são feitas através de Migrations versionadas (`php bin/console make:migration` ou `php bin/console doctrine:migrations:diff`).
   - A aplicação é sempre executada via `php bin/console doctrine:migrations:migrate`.

---

## 🛠️ Tecnologias Utilizadas

- **Backend**: PHP 8.2+, Symfony 7.2, Doctrine ORM 3, Doctrine DBAL 4.
- **Banco de Dados**: MySQL 8.0 (charset `utf8mb4_unicode_ci`).
- **Frontend**: Twig, Tailwind CSS, Shoelace Web Components, Chart.js, jQuery DataTables.
- **Processamento e Exportação**: League CSV, Dompdf, SimpleXML, ZipArchive.
- **Padronização**: Padrão PSR-12, arquitetura em camadas e boas práticas cienciométricas.

---

## ⚙️ Instalação e Configuração

### 1. Clonar o repositório e instalar dependências
```bash
git clone <url-do-repositorio>
cd cech
composer install
```

### 2. Configurar variáveis de ambiente
Crie ou edite o arquivo `.env.local`:
```env
DATABASE_URL="mysql://root:sua_senha@127.0.0.1:3306/cech?serverVersion=8.0&charset=utf8mb4"
```

### 3. Criar o banco de dados e executar as migrações
```bash
php bin/console doctrine:database:create --if-not-exists
php bin/console doctrine:migrations:migrate --no-interaction
```

### 4. Compilar os assets (CSS / JS)
```bash
./build.sh
# ou execute:
# php bin/console tailwind:build --minify && php bin/console asset-map:compile
```

### 5. Criar usuário administrador
```bash
php bin/console app:admin-user admin 12345678
```

---

## 📥 Rotinas de Console (CLI Commands)

### 1. Indexação em Lote de Periódicos e Bases Internacionais
Indexa todos os 13.469 artigos científicos em ~6 segundos, vinculando o periódico canônico, Qualis e JSON de bases indexadoras:
```bash
php bin/console app:index:journals
```

### 2. Indexação e Normatização Completa de Currículos
Executa o cruzamento de coautoria docente do CECH, identidades de autores e instituições:
```bash
php bin/console app:index:curriculums
```

### 3. Superdump Completo do Banco de Dados
Exporta todo o banco de dados MySQL com DDL completo e dados compactados em ZIP:
```bash
php bin/console app:database:dump
# Opções: --no-zip (gera apenas .sql) ou --filename=meu_backup
```

### 4. Importar Tesauros Controlados do BiblioMap
```bash
php bin/console app:import:bibliomap
```

### 5. Importar Currículos Lattes em Lote (XML)
```bash
php bin/console app:import:lattes --dir=docs/banco/CECH --no-debug
```

### 6. Enriquecer com Metadados Institucionais e Qualis (Excel)
```bash
php bin/console app:import:excel
```

### 7. Importar Fotos de Docentes em Lote
```bash
php bin/console app:import:photos --dir=caminho/para/fotos
# ou com arquivo ZIP:
php bin/console app:import:photos --zip=caminho/fotos.zip
```

### 8. Crawler Automático de Fotos Lattes
```bash
php bin/console app:crawl:lattes-photos --limit=50
```

---

## 🧪 Testes Automatizados

Para rodar a suíte completa de testes com detalhamento (52 testes / 350+ asserções):
```bash
APP_ENV=test ./vendor/bin/phpunit --testdox
```

Para validar a sintaxe dos templates Twig e configurações YAML:
```bash
php bin/console lint:twig templates
php bin/console lint:yaml config
```

---

## 📁 Guias e Documentação Técnica

Para detalhes aprofundados sobre cada módulo, consulte a pasta [`docs/`](file:///Volumes/Dados/work/cech/docs):
- [docs/ADMIN_GUIDE.md](file:///Volumes/Dados/work/cech/docs/ADMIN_GUIDE.md) — Manual de operações do painel administrativo.
- [docs/graficos.md](file:///Volumes/Dados/work/cech/docs/graficos.md) — Catálogo detalhado das 19 Figuras de Inteligência Cienciométrica.
- [docs/ARCHITECTURE.md](file:///Volumes/Dados/work/cech/docs/ARCHITECTURE.md) — Arquitetura de software, fluxo de dados e serviços.
- [docs/ARQUITETURA_E_PROCESSOS.md](file:///Volumes/Dados/work/cech/docs/ARQUITETURA_E_PROCESSOS.md) — Processos de ingestão, parsing e enriquecimento.
- [docs/DICIONARIO_DE_DADOS_E_VARIAVEIS.md](file:///Volumes/Dados/work/cech/docs/DICIONARIO_DE_DADOS_E_VARIAVEIS.md) — Dicionário de entidades e colunas do banco de dados.
- [docs/THESAURUS.md](file:///Volumes/Dados/work/cech/docs/THESAURUS.md) — Modelagem e funcionamento dos tesauros de desambiguação.
- [docs/LATTES_IMPORT.md](file:///Volumes/Dados/work/cech/docs/LATTES_IMPORT.md) — Especificação dos parsers XML e extração de metadados Lattes.