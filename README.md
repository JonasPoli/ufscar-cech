# Portal de Produção Científica & Acadêmica — CECH / UFSCar

Sistema de Mapeamento, Desambiguação de Autoridades e Catálogo da Produção Intelectual do **Centro de Educação e Ciências Humanas (CECH)** da **Universidade Federal de São Carlos (UFSCar)**.

Inspirado na arquitetura e design do sistema *Somos UFMG* e integrado ao ecossistema de tesauros controlados *BiblioMap*.

---

## 🚀 Visão Geral das Funcionalidades

### 🌐 Portal Público (Sem necessidade de login)
- **Home Interativa**: Métricas institucionais, busca instantânea, departamentos acadêmicos, publicações recentes com links DOI (abertura em nova aba) e destaques docentes.
- **Painel de Indicadores Globais**: Gráficos dinâmicos (Chart.js) com evolução temporal de produções, distribuição por tipo de produção, estratos Qualis (CAPES) e corpo docente por departamento.
- **Busca Avançada no Catálogo**: Pesquisa por pesquisador, artigo, DOI, ISSN, ISBN, evento ou periódico, com filtros por departamento e tipologia.
- **Perfil Completo do Pesquisador**:
  - Dados biográficos, foto/avatar, vínculos e afiliações.
  - Links externos diretos: **Currículo Lattes**, **ORCID** (com badge e link oficial) e e-mail.
  - Formação acadêmica e titulações com linha do tempo.
  - Produção científica categorizada por abas: Artigos (com DOI em nova aba, Qualis e ISSN), Livros, Capítulos, Trabalhos em Eventos, Produção Técnica e Softwares.
  - Orientações concluídas e em andamento (Mestrado, Doutorado, Pós-Doutorado, TCC, Iniciação Científica).
  - Prêmios, títulos honoríficos e áreas de atuação (CNPq).
  - Gráfico de histórico de produção anual do docente.
- **Diretório de Departamentos**: Catálogo dos 8 departamentos do CECH com listagem de docentes e métricas agregadas.
- **Suporte a Modo Claro e Modo Escuro (Dark/Light Mode)**: Alternância persistente no navegador.

### 🛡️ Painel Administrativo (`/admin`)
- **Dashboard Institucional**: Contadores em tempo real de pesquisadores, produções, instituições, países e autores.
- **Gestão de Currículos Lattes**:
  - Tabela interativa com DataTables, pesquisa instantânea e paginação.
  - Filtro avançado por departamento acadêmico.
  - **Exportação de Dados Filtrados**: Formatos CSV, JSON e XML.
  - **Exportação Individual em PDF**: Geração de currículo formatado para impressão/arquivo via Dompdf.
  - **Importador Web de XMLs**: Upload em lote de múltiplos arquivos XML da Plataforma Lattes.
- **Tesauros e Vocabulários Controlados (BiblioMap)**:
  - **Tesauro de Países**: Cadastro de termos padronizados, códigos ISO Alpha-2/Alpha-3, sinônimos/variantes, fusão (*merge*) de duplicatas e importação/exportação em VantagePoint (`.the`), CSV, JSON e XML.
  - **Tesauro de Instituições**: Cadastro de universidades/institutos, siglas, naturezas jurídicas, variantes de grafia, fusão de entidades e importação/exportação multi-formato.
  - **Tesauro de Autores**: Normalização de nomes de autores, variantes de citação bibliográfica, identificadores externos (ORCID, Lattes ID), desambiguação e fusão de autoridades.
- **Relatórios Institucionais**:
  - Relatório de Docentes e Produções por Departamento (com exportação CSV e JSON).
  - Relatório de Distribuição de Artigos por Estrato Qualis (com exportação CSV e JSON).
- **Gestão de Usuários**: CRUD de administradores e operadores do sistema com controle de permissões (RBAC).
- **Experiência do Usuário**: Diálogos de confirmação e modais em HTML/Shoelace (`<sl-dialog>`), **sem uso de `alert()` ou `confirm()` nativos**.

---

## 🛠️ Tecnologias Utilizadas

- **Backend**: PHP 8.2+, Symfony 7.2, Doctrine ORM 3, Doctrine DBAL 4.
- **Banco de Dados**: MySQL 8.0 (charset `utf8mb4_unicode_ci`).
- **Frontend**: Twig, Tailwind CSS, Shoelace Web Components, Chart.js, jQuery DataTables.
- **Processamento e Exportação**: League CSV, Dompdf, SimpleXML, ZipArchive.
- **Padronização**: Padrão PSR-12, nomenclatura 100% em inglês no banco de dados e entidades.

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
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate --no-interaction
```

### 4. Compilar os assets (CSS / JS)
```bash
./build.sh
# ou execute manualmente:
# php bin/console tailwind:build --minify
# php bin/console asset-map:compile
```

### 5. Criar usuário administrador
```bash
php bin/console app:admin-user admin wab12345678
```

---

## 📥 Importação de Dados e Carga Inicial

O sistema possui comandos de console de alta performance preparados para processamento em lote:

### 1. Importar Tesauros Controlados do BiblioMap
Importa países, estados, cidades, instituições e autores do banco BiblioMap com migração direta otimizada:
```bash
php bin/console app:import:bibliomap
```
*Resultado: ~167 países (+833 variantes), ~9.228 instituições (+26.278 variantes) e ~150.239 autores.*

### 2. Importar Currículos Lattes em Lote (XML)
Processa todos os arquivos XML dos docentes contidos em `docs/banco/CECH`:
```bash
php bin/console app:import:lattes --dir=docs/banco/CECH --no-debug
```
*Resultado: 414 pesquisadores, ~73.938 produções científicas, ~26.885 orientações, ~2.053 titulações.*

### 3. Enriquecer com Metadados Institucionais e Qualis (Excel)
Cruza as planilhas `docs/banco/2026-08-23 - Info docentes do CECH.xlsx` e `docs/banco/2026-08-23 - Producao cientifica-tecnologica-cultura.xlsx`:
```bash
php bin/console app:import:excel
```
*Resultado: Associação de departamentos (DEn, DFis, DFil, DGeo, DCSo, DPsi, DL, DCI) e classificação Qualis (A1-C).*

### 4. Importar Fotos de Docentes em Lote
Importa arquivos de imagem nomeados por ID Lattes (`1012351287140134.jpg`), slug ou nome do docente:
```bash
php bin/console app:import:photos --dir=caminho/para/fotos
# ou com arquivo ZIP:
php bin/console app:import:photos --zip=caminho/fotos.zip
```

### 5. Crawler Automático de Fotos Lattes
Executa o crawler para buscar fotos diretamente das servlets do Lattes/CNPq:
```bash
php bin/console app:crawl:lattes-photos --limit=50
```

---

## 🧪 Testes Automatizados

Para rodar a suíte completa de testes com detalhamento:
```bash
APP_ENV=test ./vendor/bin/phpunit --testdox
```

Para validar a sintaxe dos templates Twig e configurações YAML:
```bash
php bin/console lint:twig templates
php bin/console lint:yaml config
```

---

## 📁 Estrutura de Documentação Adicional

Para entender detalhadamente as regras de negócio, modelagem de dados e arquitetura:
- 📖 [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) — Arquitetura do sistema, diagrama de entidades e dicionário de dados.
- 📖 [docs/THESAURUS.md](docs/THESAURUS.md) — Normalização de termos, formatos de intercâmbio VantagePoint `.the`, CSV, JSON e XML, e algoritmo de fusão (*merge*).
- 📖 [docs/LATTES_IMPORT.md](docs/LATTES_IMPORT.md) — Mapeamento do XML do CNPq/Lattes, extração de dados e otimização de memória.
- 📖 [docs/ADMIN_GUIDE.md](docs/ADMIN_GUIDE.md) — Manual de operações e rotinas administrativas.