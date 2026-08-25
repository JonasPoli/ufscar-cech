# Dicionário de Dados, Entidades e Variáveis — CECH UFSCar

Este documento cataloga de forma minuciosa todas as entidades, tabelas do banco de dados, colunas (destacando a distinção entre dados brutos Lattes e colunas de índice), tipos de dados, variáveis de serviço, DTOs e variáveis de templates Twig do sistema.

---

## 1. Regra Fundamental de Dados do Lattes

> [!IMPORTANT]
> **Preservação de Dados Brutos Lattes**:
> Todas as colunas que recebem dados diretamente dos arquivos XML/HTML do Lattes permanecem estritamente intactas e inalteradas.
> Processos de enriquecimento, indexação e desambiguação são persistidos **exclusivamente em colunas de índice** (ex: `matched_researcher_id`, `author_identity_id`, `qualis_journal_id`, `is_indexed`).

---

## 2. Dicionário de Entidades e Tabelas

### 2.1 `Researcher` (Tabela: `researchers`)
Representa o docente ou pesquisador acadêmico do CECH/UFSCar.

| Coluna / Propriedade | Tipo PHP / DB | Origem | Descrição e Finalidade |
|---|---|---|---|
| `$id` (`id`) | `int` (PK) | Sistema | Identificador primário autoincrementado |
| `$idLattes` (`id_lattes`) | `string(16)` | Lattes (Raw) | Código de 16 dígitos identificador no CNPq |
| `$fullName` (`full_name`) | `string(255)` | Lattes (Raw) | Nome completo do pesquisador como cadastrado no Lattes |
| `$citationNames` (`citation_names`) | `string|null` (`text`) | Lattes (Raw) | Lista de nomes em citações bibliográficas (separados por `;`) |
| `$slug` (`slug`) | `string(255)` | Índice | Slug para compor URLs amigáveis (ex: `/professor/joao-silva`) |
| `$orcid` (`orcid`) | `string(50)|null` | Lattes / Manual | Identificador ORCID (ex: `0000-0002-1825-0097`) |
| `$email` (`email`) | `string(255)|null` | Planilha / Manual | E-mail de contato institucional |
| `$abstractResume` (`abstract_resume`) | `string|null` (`text`) | Lattes (Raw) | Texto de resumo do currículo Lattes |
| `$department` (`department`) | `string(150)|null` | Planilha / Lattes | Nome por extenso do Departamento (ex: `Departamento de Educação`) |
| `$departmentCode` (`department_code`) | `string(50)|null` | Planilha | Sigla do departamento (ex: `Ded`, `DCSo`, `DFil`) |
| `$unit` (`unit`) | `string(150)|null` | Sistema | Unidade acadêmica (`CECH - Centro de Educação e Ciências Humanas`) |
| `$admissionYear` (`admission_year`) | `int|null` | Planilha / Manual | Ano de ingresso como docente no CECH |
| `$leaveYear` (`leave_year`) | `int|null` | Planilha / Manual | Ano de desligamento/aposentadoria (quando aplicável) |
| `$nationality` (`nationality`) | `string(50)|null` | Lattes (Raw) | Nacionalidade (ex: `Brasileira`) |
| `$birthCountry` (`birth_country`) | `string(100)|null` | Lattes (Raw) | País de nascimento |
| `$birthState` (`birth_state`) | `string(50)|null` | Lattes (Raw) | Unidade federativa / estado de nascimento |
| `$birthCity` (`birth_city`) | `string(100)|null` | Lattes (Raw) | Cidade de nascimento |
| `$photoUrl` (`photo_url`) | `string(255)|null` | Crawler / Upload | Caminho relativo ou URL da foto de perfil |
| `$lastLattesUpdate` (`last_lattes_update`) | `DateTimeImmutable|null` | Lattes (Raw) | Data da última atualização informada no Lattes |
| `$status` (`status`) | `bool` | Sistema | Ativo/Inativo no portal público |
| `$workAgency` (`work_agency`) | `string(255)|null` | Lattes (Raw) | Instituição do endereço de trabalho |
| `$workPostalCode` (`work_postal_code`) | `string(20)|null` | Lattes (Raw) | CEP do endereço profissional |
| `$workPhone` (`work_phone`) | `string(50)|null` | Lattes (Raw) | Telefone profissional |
| `$workCity` (`work_city`) | `string(100)|null` | Lattes (Raw) | Cidade do endereço de trabalho |
| `$workState` (`work_state`) | `string(50)|null` | Lattes (Raw) | Estado do endereço de trabalho |
| `$workCountry` (`work_country`) | `string(100)|null` | Lattes (Raw) | País do endereço de trabalho |
| `$createdAt` (`created_at`) | `DateTimeImmutable` | Sistema | Carimbo de data/hora de criação do registro |
| `$updatedAt` (`updated_at`) | `DateTimeImmutable` | Sistema | Carimbo de data/hora de última modificação |

---

### 2.2 `ProductionItem` (Tabela: `production_items`)
Representa cada item bibliográfico, técnico ou artístico produzido pelo pesquisador.

| Coluna / Propriedade | Tipo PHP / DB | Origem | Descrição e Finalidade |
|---|---|---|---|
| `$id` (`id`) | `int` (PK) | Sistema | Identificador primário |
| `$researcher` (`researcher_id`) | `Researcher` (FK) | Sistema | Pesquisador proprietário da produção |
| `$itemType` (`item_type`) | `string(50)` | Lattes (Raw) | Tipo da produção (`ARTIGO`, `LIVRO`, `CAPITULO`, `EVENTO`, etc.) |
| `$title` (`title`) | `string(500)` | Lattes (Raw) | Título da obra/artigo/livro |
| `$year` (`year`) | `int|null` | Lattes (Raw) | Ano de publicação / apresentação |
| `$doi` (`doi`) | `string(255)|null` | Lattes (Raw) | Digital Object Identifier (DOI) |
| `$journalName` (`journal_name`) | `string(255)|null` | Lattes (Raw) | Nome do periódico como veio no Lattes |
| `$issn` (`issn`) | `string(20)|null` | Lattes (Raw) | ISSN do periódico |
| `$volume` (`volume`) | `string(50)|null` | Lattes (Raw) | Volume da revista/periódico |
| `$issue` (`issue`) | `string(50)|null` | Lattes (Raw) | Fascículo/número do periódico |
| `$pages` (`pages`) | `string(50)|null` | Lattes (Raw) | Intervalo de páginas (ex: `120-135`) |
| `$publisher` (`publisher`) | `string(255)|null` | Lattes (Raw) | Editora da publicação |
| `$qualis` (`qualis`) | `string(10)|null` | Índice | Estrato Qualis CAPES resolvido (ex: `A1`, `B2`) |
| `$qualisJournal` (`qualis_journal_id`) | `QualisJournal|null` (FK) | Índice | Chave estrangeira para o periódico canônico no tesauro Qualis |
| `$eventName` (`event_name`) | `string(255)|null` | Lattes (Raw) | Nome do evento/congresso |
| `$eventCity` (`event_city`) | `string(100)|null` | Lattes (Raw) | Cidade onde ocorreu o evento |
| `$isIndexed` (`is_indexed`) | `bool` | Índice | Flag indicando se o item passou pelo pipeline de normalização |
| `$indexedAt` (`indexed_at`) | `DateTimeImmutable|null` | Índice | Data em que ocorreu a última indexação |

---

### 2.3 `ProductionAuthor` (Tabela: `production_authors`)
Representa cada coautor listado em uma produção científica.

| Coluna / Propriedade | Tipo PHP / DB | Origem | Descrição e Finalidade |
|---|---|---|---|
| `$id` (`id`) | `int` (PK) | Sistema | Identificador primário |
| `$production` (`production_id`) | `ProductionItem` (FK) | Sistema | Produção à qual o autor pertence |
| `$authorName` (`author_name`) | `string(255)` | Lattes (Raw) | Nome completo do coautor como veio no Lattes |
| `$citationName` (`citation_name`) | `string(255)|null` | Lattes (Raw) | Nome em citação do coautor no Lattes |
| `$authorOrder` (`author_order`) | `int` | Lattes (Raw) | Posição de autoria (1º autor, 2º autor...) |
| `$isSelf` (`is_self`) | `bool` | Índice | `true` se este coautor for o próprio pesquisador dono do currículo |
| `$isCechResearcher` (`is_cech_researcher`) | `bool` | Índice | `true` se este coautor for um docente cadastrado no CECH |
| `$matchedResearcherId` (`matched_researcher_id`)| `int|null` | Índice | ID da entidade `Researcher` se for docente CECH |
| `$authorIdentity` (`author_identity_id`) | `AuthorIdentity|null` (FK) | Índice | Chave estrangeira para a identidade canônica do Tesauro |

---

### 2.4 Entidades do Tesauro de Autores

#### `AuthorIdentity` (Tabela: `author_identities`)
- `$id`: Identificador primário.
- `$preferredName`: Nome canônico de exibição do autor.
- `$normalizedName`: Nome normalizado em maiúsculas sem acentos/pontuação.
- `$notes`: Observações de curadoria manual.
- `$status`: Status da identidade (ativo/inativo).

#### `AuthorNameVariant` (Tabela: `author_name_variants`)
- `$id`: Identificador primário.
- `$authorIdentity`: FK para `AuthorIdentity`.
- `$originalName`: Texto original da variante de citação.
- `$normalizedName`: Texto normalizado da variante.
- `$source`: Origem da variante (`LATTES`, `MANUAL`, `SCOPUS`).

#### `AuthorExternalIdentifier` (Tabela: `author_external_identifiers`)
- `$id`: Identificador primário.
- `$authorIdentity`: FK para `AuthorIdentity`.
- `$scheme`: Tipo do identificador (`ORCID`, `LATTES_ID`, `SCOPUS_ID`, `RESEARCHER_ID`).
- `$identifierValue`: Valor do identificador (ex: `0000-0001-2345-6789`).

---

### 2.5 `Education` (Tabela: `educations`)
Formações acadêmicas do pesquisador.
- `$level`: Nível (`GRADUACAO`, `ESPECIALIZACAO`, `MESTRADO`, `DOUTORADO`, `POS_DOUTORADO`, `LIVRE_DOCENCIA`).
- `$course`: Nome do curso / titulação.
- `$institutionName`: Nome bruto da instituição.
- `$institution`: FK de índice para `Institution` canônica.
- `$startYear`, `$endYear`: Anos de início e conclusão.
- `$thesisTitle`: Título da tese / dissertação / monografia.
- `$advisorName`: Nome do orientador.

---

### 2.6 `Orientation` (Tabela: `orientations`)
Orientações acadêmicas concluídas ou em andamento.
- `$orientationType`: Tipo (`CONCLUIDA`, `EM_ANDAMENTO`).
- `$nature`: Nível (`DOUTORADO`, `MESTRADO`, `POS_DOUTORADO`, `TCC`, `INICIACAO_CIENTIFICA`, `ESPECIALIZACAO`, `OUTRA`).
- `$studentName`: Nome do orientando.
- `$title`: Título do trabalho acadêmico.
- `$year`: Ano de conclusão ou início.
- `$institutionName`: Nome da instituição de ensino.
- `$sponsoringAgency`: Agência de fomento / bolsa (CAPES, CNPq, FAPESP...).

---

## 3. Variáveis Globais e Contratos de Templates Twig

### 3.1 Variáveis Injetadas Automaticamente nos Templates
- `site_settings`: Entidade `SiteSetting` contendo configurações do portal (título do site, URL base, descrição SEO padrão, metatags).
- `app.user`: Usuário autenticado da sessão (`App\Entity\User`).
- `app.request`: Objeto `Request` atual do Symfony com rota e query parameters.

### 3.2 Variáveis do Perfil do Pesquisador (`templates/pub/professor/show.html.twig`)
- `researcher` (`\App\Entity\Researcher`): Objeto do pesquisador com todos os relacionamentos carregados.
- `articles` (`\App\Entity\ProductionItem[]`): Array de artigos em periódicos ordenados por ano decrescente.
- `books` (`\App\Entity\ProductionItem[]`): Array de livros publicados/organizados.
- `chapters` (`\App\Entity\ProductionItem[]`): Array de capítulos de livros.
- `events` (`\App\Entity\ProductionItem[]`): Trabalhos completos em congressos e anais.
- `techWorks` (`\App\Entity\ProductionItem[]`): Trabalhos técnicos e relatórios.
- `software` (`\App\Entity\ProductionItem[]`): Programas de computador e softwares.
- `patents` (`\App\Entity\ProductionItem[]`): Patentes e registros de marcas.
- `artistic` (`\App\Entity\ProductionItem[]`): Produções artísticas e culturais.
- `completedOrientations` (`\App\Entity\Orientation[]`): Orientações já concluídas.
- `ongoingOrientations` (`\App\Entity\Orientation[]`): Orientações atualmente em andamento.
- `orientationsCount` (`array<string, int>`): Mapa de contagem de orientações por categoria.
- `productionTimeline` (`array<int, int>`): Array associativo `[ano => total_producoes]` para renderização de gráficos.
- `qualisDistribution` (`array<string, int>`): Mapa `[estrato => contagem]` para gráfico de pizza/barras de Qualis.
