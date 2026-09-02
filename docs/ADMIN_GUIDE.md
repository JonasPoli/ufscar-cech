# Guia do Painel Administrativo — CECH / UFSCar

Manual de operações, rotinas de gerenciamento, indexação cienciométrica e administração do Portal de Produção Científica do CECH.

---

## 🔐 1. Acesso e Autenticação

- **URL de Acesso**: `/admin` (redireciona para `/login` se não autenticado).
- **Credenciais Iniciais Padrão**:
  - Usuário: `admin`
  - Senha: `wab12345678`

### Criação e Gerenciamento de Administradores via Console
Para criar ou redefinir a senha de um administrador diretamente pelo terminal:
```bash
php bin/console app:admin-user <nome_usuario> <senha>
```

---

## 👥 2. Gestão de Currículos Docentes (`/admin/curriculum`)

Na seção **Currículos Docentes**, você pode:
- **Pesquisar & Filtrar**: Busca instantânea por nome, ID Lattes e filtro por departamento (`DEn`, `DFil`, `DPsi`, `DCSo`, `DL`, `DCI`, `DFis`, `DGeo`).
- **Gerenciamento de Fotos & Crawling**:
  - **Upload Individual**: Na página do currículo (`/admin/curriculum/{id}`), envie a foto oficial de qualquer pesquisador.
  - **Crawler Automático do Lattes**: Botão *Buscar no Lattes* para tentar resgatar a foto oficial via servlets do CNPq.
  - **Importação em Lote via CLI**:
    ```bash
    php bin/console app:import:photos --dir=/caminho/pasta_com_fotos
    # ou com arquivo comprimido ZIP:
    php bin/console app:import:photos --zip=/caminho/fotos.zip
    ```
  - **Crawler em Lote via CLI**:
    ```bash
    php bin/console app:crawl:lattes-photos --limit=50
    ```
- **Exportação Multi-Formato**:
  - **CSV**: Tabela completa com contagem de produções por docente.
  - **JSON**: Estrutura detalhada para interoperabilidade de dados.
  - **XML**: Formato compatível com bancos de dados acadêmicos.
  - **PDF Individual Formatado (Dompdf)**: Relatório curricular com layout oficial para impressão em formato PDF (A4).

---

## ⚡ 3. Indexação & Normatização de Dados (`/admin/indexing`)

O painel de indexação coordena a inteligência de dados e a resolução de entidades em lote.

### 3.1. Resolução e Vinculação de Entidades
- **Coautoria CECH**: Identifica quando um coautor citado em uma produção é também um docente cadastrado no CECH (`is_cech_researcher = true`, `matched_researcher_id`).
- **Identidade de Autores**: Vincula variantes de citação ao tesauro de autoridades (`author_identity_id`).
- **Instituições**: Normaliza afiliações institucionais de orientações e formações acadêmicas (`institution_id`).

### 3.2. Indexação de Periódicos & Bases Internacionais
- **Botão "Indexar Periódicos & Bases Internacionais"**:
  - Cruza todos os 13.469 artigos científicos com a tabela canônica de periódicos (`qualis_journal_id`).
  - Mapeia ISSNs eletrônicos (`issn_e`), impressos (`issn_imp`) e de ligação (`issn_l`).
  - Preenche a coluna `indexed_databases` de cada artigo com os metadados JSON das bases internacionais em que a revista está indexada (Scopus, Web of Science, PubMed, Latindex, SciELO, DOAJ, OpenAlex).
  - Execução ultra-rápida via CLI:
    ```bash
    php bin/console app:index:journals
    ```

### 3.3. Ingestão e Enriquecimento do Repositório Institucional (TeD-UFSCar)
- **Importação de Teses e Dissertações Oficiais da UFSCar**:
  - Processa o catálogo oficial (`docs/banco/TeD-UFSCar.csv`), cruza os docentes orientadores e coorientadores, enriquece as orientações com o link permanente (`Handle`), resumo e programa de pós-graduação, e adiciona trabalhos inéditos não cadastrados no Lattes.
  - Execução via CLI:
    ```bash
    # Execução real:
    php bin/console app:import:repository
    # ou usando o alias:
    php bin/console app:import:ted

    # Modo simulação (sem alterar o banco):
    php bin/console app:import:repository --dry-run

    # Opções com filtros:
    php bin/console app:import:repository --center=CECH --limit=1000
    ```
  - Documentação completa disponível em [`docs/REPOSITORY_IMPORT.md`](file:///Users/jonaspoli/work/html/ufscar-cech/docs/REPOSITORY_IMPORT.md).

---

## 📚 4. Gestão de Bases de Indexação Internacional (`/admin/academic-databases`)

Módulo para cadastrar e gerenciar bases de dados científicas e importar listas de periódicos indexados:
- **Cadastro de Bases**: Nome, sigla, logotipo oficial, cor temática e URL institucional.
- **Importação de Periódicos por Base**:
  - Permite importar arquivos CSV, TXT ou VantagePoint contendo as listas oficiais de periódicos indexados na base (como Scopus, Web of Science, DOAJ, Latindex).
  - O sistema associa automaticamente o periódico à base no banco de dados.

---

## 💾 5. Backup & Superdump do Sistema (`/admin/database/backup`)

Ferramenta para exportação completa, migração e segurança do banco de dados MySQL:
- **Superdump em Tempo Real**:
  - Executa o dump via streaming SSE (*Server-Sent Events*), exibindo terminal ao vivo e barra de progresso.
  - Gera arquivo `.sql` completo com esquema (`CREATE TABLE`), dados brutos Lattes e tabelas normalizadas.
  - Compacta automaticamente em arquivo `.sql.zip` de alta taxa de compressão.
- **Download Seguro**:
  - Botão de download direto do arquivo gerado.
  - Rota de download automático do backup mais recente: `/admin/database/backup/download`.
- **Execução via Console (CLI)**:
  ```bash
  php bin/console app:database:dump
  # Opções: --no-zip ou --filename=meu_backup
  ```
- **Restauração em Outro Servidor / Máquina Nova**:
  ```bash
  unzip backup_cech_*.sql.zip
  mysql -u root -p cech < backup_cech_*.sql
  php bin/console app:admin-user admin 12345678
  ./build.sh
  ```

---

## 🏛️ 6. Tesauros e Vocabulários Controlados (BiblioMap)

- **Tesauro de Países (`/admin/countries`)**: Padronização ISO Alpha-2/3, sinônimos, fusão de duplicatas e importação/exportação.
- **Tesauro de Instituições (`/admin/institutions`)**: Siglas, naturezas jurídicas, variantes de grafia e fusão.
- **Tesauro de Autores (`/admin/authors`)**: Autoridades de nomes de autores, identificadores externos (ORCID, Lattes ID) e variantes de citação.
- **Tesauro de Periódicos (`/admin/journals`)**: 63.122 periódicos com estratos Qualis e ISSNs triplos.

---

## 📊 7. Relatórios Institucionais (`/admin/reports`)

- **Produção Científica por Departamento**: Total de docentes, volume de produções e média por docente com exportação CSV/JSON.
- **Distribuição de Artigos por Estrato Qualis (CAPES)**: Estratos A1 a C com exportação CSV/JSON.

---

## 🔍 8. Gerenciamento de SEO & Analytics (`/admin/seo`)

- **Google Analytics 4 (GA4)**: Configuração do Measurement ID.
- **Google Search Console**: Verificação de propriedade do domínio.
- **Meta Tags & Open Graph**: Personalização de títulos, descrições e imagem de compartilhamento social.
- **Sitemap XML & Robots.txt**: Controle de indexação pelos motores de busca.
