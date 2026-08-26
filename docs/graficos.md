# Catálogo de Indicadores & Figuras Cienciométricas — CECH / UFSCar

Este documento cataloga a metodologia, agrupamentos, equações e interpretação de todos os **19 Indicadores Globais** disponíveis em [`/indicadores`](file:///Volumes/Dados/work/cech/templates/pub/main/indicadores.html.twig) e dos **Gráficos de Perfil Docente** em [`/professor/{slug}`](file:///Volumes/Dados/work/cech/templates/pub/professor/show.html.twig).

---

## 🧭 Visão Geral dos 4 Blocos Temáticos

```
┌────────────────────────────────────────────────────────────────────────────┐
│                    PORTAL DE INDICADORES CIENCIOMÉTRICOS                   │
├───────────────────┬───────────────────┬───────────────────┬────────────────┤
│  1. CORPO DOCENTE │   2. FORMAÇÃO &   │  3. PRODUÇÃO &    │   4. REDES &   │
│   & VÍNCULOS      │    ORIENTAÇÕES    │   QUALIS / BASES  │   PARCERIAS    │
│  (Figuras 1 a 8)  │ (Figuras 9 a 11)  │ (Figuras 12 a 16) │(Figuras 17 a 19│
└───────────────────┴───────────────────┴───────────────────┴────────────────┘
```

---

## 🏛️ Bloco 1: Corpo Docente, Formações e Vínculos

### **Figura 1 — Vínculos Institucionais e Docentes por Departamento**
* **Tipo**: Gráfico de Barras Horizontais com Badges.
* **Objetivo**: Apresentar a força de trabalho docente alocada nos 8 departamentos acadêmicos do CECH.
* **Departamentos**:
  - `DEn` — Departamento de Educação
  - `DFis` — Departamento de Educação Física
  - `DFil` — Departamento de Filosofia
  - `DGeo` — Departamento de Geografia
  - `DCSo` — Departamento de Ciências Sociais
  - `DPsi` — Departamento de Psicologia
  - `DL` — Departamento de Letras
  - `DCI` — Departamento de Ciência da Informação
* **Métrica**: Contagem de docentes com vínculo ativo por departamento.

---

### **Figura 2 — Formação Inicial: Cursos de Graduação Mais Frequentes**
* **Tipo**: Gráfico de Barras Horizontais.
* **Objetivo**: Identificar as principais áreas de formação na graduação dos docentes do centro.
* **Metodologia**: Extraído da seção de Formação Acadêmica (`GRADUACAO`) dos currículos Lattes com normatização de cursos via `StatisticsService`.

---

### **Figura 3 — Áreas do Conhecimento no Doutorado**
* **Tipo**: Gráfico de Barras Horizontais.
* **Objetivo**: Detalhar as especialidades e campos específicos em que os docentes obtiveram o título de Doutor.
* **Metodologia**: Extraído dos cursos de `DOUTORADO` cadastrados no Lattes.

---

### **Figura 4 — Grandes Áreas do Conhecimento (CNPq)**
* **Tipo**: Gráfico Donut (Rosca).
* **Objetivo**: Mapear a distribuição macro do corpo docente segundo a taxonomia oficial do CNPq:
  - Ciências Humanas
  - Linguística, Letras e Artes
  - Ciências Sociais Aplicadas
  - Ciências da Saúde / Biológicas / Exatas
* **Metodologia**: Associação das áreas primárias de atuação declaradas pelos pesquisadores.

---

### **Figura 5 — Origem dos Doutorados: Top 10 Instituições Nacionais**
* **Tipo**: Gráfico de Barras Horizontais.
* **Objetivo**: Mapear as universidades brasileiras que mais titularam o corpo docente do CECH (ex: USP, UNICAMP, UFSCar, UNESP, UFRJ).
* **Metodologia**: Resolução institucional via `InstitutionResolverService` sobre os registros de doutorado.

---

### **Figura 6 — Doutorados no Exterior: Formação Internacional por País**
* **Tipo**: Gráfico de Barras Horizontais com bandeiras oficiais.
* **Objetivo**: Identificar o grau de internacionalização da formação doutoral dos docentes (França, EUA, Reino Unido, Espanha, Alemanha, Portugal, etc.).
* **Metodologia**: Resolução de países de titulação via `CountryResolverService`.

---

### **Figura 7 — Distribuição Geográfica dos Doutorados Nacionais**
* **Tipo**: Gráfico de Barras Horizontais com siglas estaduais.
* **Objetivo**: Mapear as unidades federativas (SP, RJ, MG, RS, etc.) onde foram obtidos os títulos de doutorado no Brasil.

---

### **Figura 8 — Linha do Tempo de Titulação de Doutorado (1980–2026)**
* **Tipo**: Gráfico de Linha com gradiente de área preenchida.
* **Objetivo**: Mostrar a curva histórica de titulação do corpo docente ao longo das décadas, evidenciando o rejuvenescimento e maturidade acadêmica do centro.

---

## 🎓 Bloco 2: Formação de Recursos Humanos & Orientações

### **Figura 9 — Formação de Recursos Humanos: Concluídas vs. Em Andamento**
* **Tipo**: Gráfico de Barras Empilhadas.
* **Objetivo**: Comparar o volume total de recursos humanos já formados pelo CECH versus o estoque atual de alunos sob orientação ativa.

---

### **Figura 10 — Histórico Anual de Orientações Concluídas**
* **Tipo**: Gráfico de Linha Temporal Multi-Série.
* **Objetivo**: Demonstrar a evolução ano a ano do número de defesas e conclusões de trabalhos orientados pelos docentes.

---

### **Figura 11 — Orientações Concluídas por Nível Acadêmico**
* **Tipo**: Gráfico Donut (Rosca) detalhado com legenda personalizada.
* **Níveis Analisados**:
  1. **Doutorado** (Teses defendidas)
  2. **Mestrado** (Dissertações defendidas)
  3. **Pós-Doutorado** (Supervisões concluídas)
  4. **TCC / Graduação** (Trabalhos de conclusão)
  5. **Iniciação Científica (PIBIC/PIBITI)**
  6. **Especialização** (Monografias de pós-graduação *lato sensu*)
  7. **Outras Orientações**

---

## 📚 Bloco 3: Produção Intelectual, Qualis & Bases Internacionais

### **Figura 12 — Produção Intelectual Acumulada por Tipo**
* **Tipo**: Gráfico Donut (Rosca) com contadores em destaque.
* **Tipologias Mapeadas**:
  - **Artigos em Periódicos Científicos**
  - **Livros Publicados/Organizados**
  - **Capítulos de Livros**
  - **Trabalhos em Anais de Eventos**
  - **Produção Técnica / Relatórios**
  - **Softwares e Patentes**
  - **Textos em Jornais e Revistas**
  - **Outras Produções Bibliográficas e Culturais**

---

### **Figura 13 — Evolução Temporal da Produção Científica Anual**
* **Tipo**: Gráfico de Linha Multi-Camada (Artigos, Livros/Capítulos e Eventos ao longo dos anos).
* **Objetivo**: Analisar o ritmo de publicação do centro e a estabilidade da produção intelectual anual.

---

### **Figura 14 — Distribuição de Artigos por Estrato Qualis (CAPES)**
* **Tipo**: Gráfico de Barras Verticais coloridas por estrato.
* **Estratos Oficiais**:
  - `A1`, `A2`, `A3`, `A4` (Excelência Internacional / Nacional)
  - `B1`, `B2`, `B3`, `B4` (Qualidade Intermediária)
  - `C` (Outros periódicos avaliados)
  - *Não Classificados / Em Avaliação*
* **Metodologia**: Cruzamento canônico de periódicos e ISSNs com a tabela oficial de Qualis Periódicos da CAPES.

---

### **Figura 15 — Bases Científicas Internacionais: Trabalhos por Base de Indexação**
* **Tipo**: Gráfico de Barras Horizontais com a paleta oficial de cores de cada indexador.
* **Objetivo**: Mostrar a visibilidade global dos artigos do CECH, indicando a quantidade de trabalhos publicados em periódicos indexados nas maiores bases mundiais:
  - **Scopus** (`#ea580c`)
  - **Web of Science (WoS / Clarivate)** (`#7c3aed`)
  - **Latindex** (`#0d9488`)
  - **SciELO** (`#e11d48`)
  - **PubMed / MEDLINE** (`#2563eb`)
  - **DOAJ (Directory of Open Access Journals)** (`#d97706`)
  - **OpenAlex** (`#6366f1`)
* **Metodologia**: Resolução em lote via `JournalResolverService` cruzando ISSN impresso, eletrônico e de ligação com os catálogos oficiais das bases.

---

### **Figura 16 — Séries Históricas: Evolução Temporal da Indexação por Base (2010–2026)**
* **Tipo**: Gráfico Multi-Linhas Interativo com pontos e curvas de suavização.
* **Objetivo**: Acompanhar o crescimento da presença dos artigos do CECH nas bases científicas internacionais ano a ano.

---

## 🌐 Bloco 4: Redes de Colaboração & Parcerias Institucionais

### **Figura 17 — Rede de Coautoria Docente (Produção Compartilhada CECH)**
* **Tipo**: Ranking Top 10 e Matriz de Parcerias Internas com Deduplicação.
* **Regra Cienciométrica de Ouro**: Obras produzidas em coautoria entre 2 ou mais docentes do CECH são contabilizadas **uma única vez** no cômputo institucional para evitar contagem duplicada.
* **Objetivo**: Evidenciar os pares de pesquisadores com maior cooperação intra-departamental e inter-departamental.

---

### **Figura 18 — Top Parcerias Institucionais Nacionais**
* **Tipo**: Gráfico de Barras Horizontais.
* **Objetivo**: Mapear as universidades, institutos federais e centros de pesquisa nacionais que mais colaboram em coautoria com os docentes do CECH.

---

### **Figura 19 — Parcerias Internacionais: Coautorias por País**
* **Tipo**: Gráfico de Barras Horizontais com bandeiras e identificação dos países parceiros (América Latina, Europa, América do Norte, etc.).

---

## 👨‍🏫 Gráficos no Perfil Individual do Docente (`/professor/{slug}`)

Na página individual de cada professor, os seguintes componentes analíticos são renderizados sob medida:

1. **Painel de Gráficos e Inteligência de Produção**:
   - Gráfico de Linha da produção anual do docente dividida por tipologia.
   - Gráfico Donut da proporção de cada categoria de produção.
2. **Histórico de Orientações Concluídas por Nível**:
   - Gráfico de barras empilhadas mostrando a evolução anual das orientações concluídas (Doutorado, Mestrado, Pós-Doc, TCC, PIBIC).
3. **Painel de Bases Científicas Internacionais**:
   - **Cartão de Destaque**: Total de artigos indexados e percentual sobre toda a produção do professor.
   - **Ranking de Bases**: Cartões com badges `#1`, `#2`, etc., quantidade de produções e percentual.
   - **Figura 15 Individual**: Barras horizontais dos trabalhos do docente indexados em cada base.
   - **Figura 16 Individual**: Linha do tempo de indexação do docente ao longo dos anos.
4. **Rede de Coautoria e Principais Colaboradores**:
   - Cartões com avatar, nome, vínculo e contagem de coautorias com link direto para o perfil do parceiro.
5. **Nuvem e Termos Frequentes**:
   - Extração estatística dos termos e bigramas mais frequentes nos títulos das publicações.
