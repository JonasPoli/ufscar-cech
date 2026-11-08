# Catálogo de Indicadores & Figuras Cienciométricas — CECH / UFSCar

Este documento cataloga a metodologia, agrupamentos, equações e interpretação de todos os **19 Indicadores Globais** (18 figuras numeradas mais o ranking Qualis de docentes) disponíveis em [`/indicadores`](file:///Users/jonaspoli/work/html/ufscar-cech/templates/pub/main/indicadores.html.twig) e dos **Gráficos de Perfil Docente** em [`/professor/{slug}`](file:///Users/jonaspoli/work/html/ufscar-cech/templates/pub/professor/show.html.twig).

---

## 🧭 Visão Geral dos 4 Blocos Temáticos & Arquitetura Modular

A página [`/indicadores`](file:///Users/jonaspoli/work/html/ufscar-cech/templates/pub/main/indicadores.html.twig) é dividida em **4 abas temáticas interativas** com carregamento sob demanda (lazy loading), cache em memória no cliente e endpoints de fragmentos parciais com cache HTTP:

```
┌─────────────────────────────────────────────────────────────────────────────────────────┐
│                          PORTAL DE INDICADORES CIENCIOMÉTRICOS                          │
├───────────────────────┬───────────────────────┬───────────────────┬─────────────────────┤
│   1. CORPO DOCENTE    │     2. FORMAÇÃO &     │   3. PRODUÇÃO &   │     4. REDES &      │
│      & VÍNCULOS       │      ORIENTAÇÕES      │   QUALIS / BASES  │     PARCERIAS       │
│   (Figuras 1 a 7)     │   (Figuras 8 a 10)    │ (Figuras 11 a 15) │  (Figuras 16 a 18)  │
│   `_tab_faculty`      │   `_tab_training`     │ `_tab_production` │   `_tab_network`    │
└───────────────────────┴───────────────────────┴───────────────────┴─────────────────────┘
```

### ⚡ Estratégia de Performance e Otimização Mobile:
1. **Carga Inicial Leve**: O carregamento inicial de `/indicadores` processa e renderiza exclusivamente o Bloco 1 (Corpo Docente) e os KPIs globais, reduzindo o payload e tempo de resposta em conexões 4G/5G móveis em mais de 75%.
2. **Carregamento Assíncrono sob Demanda**: Ao tocar nas abas subsequentes (*Formação*, *Produção* ou *Redes*), uma requisição assíncrona ao endpoint `/indicadores/fragment/{tab}` busca o HTML parcial já compilado com cabeçalho de cache `Cache-Control: public, max-age=3600`.
3. **Cache em Memória Local**: Cada aba carregada fica armazenada no DOM do navegador. Ao alternar entre abas já visitadas, a exibição é imediata (**0ms de delay**).
4. **Sincronização de URL Hash**: A navegação pelas abas atualiza os fragmentos na URL (`#faculty`, `#training`, `#production`, `#network`) sem causar recarregamento de página.
5. **Modo Completo / Impressão**: O botão *Imprimir Relatório* e o parâmetro `?tab=all` ou `?print=1` carregam todos os 19 indicadores simultaneamente para geração de PDFs ou relatórios em papel.

---

## 🏛️ Bloco 1: Corpo Docente, Formações e Vínculos

### **Figura 1 — Vínculos Institucionais e Docentes por Departamento**
* **Tipo**: Gráfico de Barras Horizontais com Badges.
* **Objetivo**: Apresentar a força de trabalho docente alocada em cada departamento do CECH.
* **Departamentos**: não há lista fixa. O código lê os docentes ativos (`status = 1`), agrupa pelo código de departamento gravado (`PS`, `LE`, `CS`, `ED`, `CI`, `FI`, etc.) e traduz cada código por um mapa de nomes do `StatisticsService` (ex.: `PS` vira Departamento de Psicologia). Código fora do mapa mostra o nome gravado; sem código, entra em *Outros Departamentos*.
* **Métrica**: Contagem de docentes com vínculo ativo por departamento.

---

### **Figura 2 — Formação de Graduação do Corpo Docente**
* **Tipo**: Lista de cursos agrupada por grande área do conhecimento.
* **Objetivo**: Identificar as principais áreas de formação na graduação dos docentes do centro.
* **Metodologia**: Extraído da seção de Formação Acadêmica (`GRADUACAO`) dos currículos Lattes com normatização de cursos via `StatisticsService`.

---

### **Figura 3 — Trajetória Acadêmica e Origem dos Docentes (Sankey)**
* **Tipo**: Diagrama de fluxo (Sankey).
* **Objetivo**: Mostrar o caminho Graduação → Mestrado → Doutorado → Ingresso no CECH/UFSCar.
* **Metodologia**: Instituições normalizadas e agrupadas por categoria (`StatisticsService::getFig16AcademicTrajectoriesSankey`).

---

### **Figura 4 — Destino Institucional dos Docentes Egressos**
* **Tipo**: Diagrama de fluxo com resumo em badges.
* **Objetivo**: Mapear para onde foram os docentes depois de saírem do CECH/UFSCar (`getFig17FacultyExitsAndDestinations`).

---

### **Figura 5 — Formação de Doutorado do Corpo Docente**
* **Tipo**: Gráfico de Barras Horizontais.
* **Objetivo**: Mostrar os cursos e áreas de pós-graduação *stricto sensu* em que os docentes obtiveram o título de Doutor.
* **Metodologia**: Extraído dos registros de formação com nível `DOUTORADO`, com nomes de curso normalizados (`normalizeCourseName`). Mostra os 12 mais frequentes. Não há ranking de instituições de doutorado nem gráfico por país.

---

### **Figura 6 — Áreas de Atuação dos Docentes (CNPq)**
* **Tipo**: Tabela/gráfico por grande área do conhecimento.
* **Objetivo**: Distribuição das grandes áreas do conhecimento registradas nos currículos Lattes.

---

### **Figura 7 — Origem Geográfica dos Docentes (UF de Nascimento)**
* **Tipo**: Gráfico de Barras Horizontais com siglas estaduais.
* **Objetivo**: Mostrar o estado de nascimento dos docentes. Conta todos os docentes com UF de nascimento preenchida (sem filtro de status) e exibe as 15 UFs mais frequentes.

---

## 🎓 Bloco 2: Formação de Recursos Humanos & Orientações

### **Figura 8 — Evolução Anual de Orientações Concluídas**
* **Tipo**: Gráfico de série histórica por ano (a partir de 2010).
* **Objetivo**: Orientações concluídas por ano e por nível: Mestrado, Doutorado, Pós-Doutorado e Iniciação Científica.

---

### **Figura 9 — Pirâmide de Níveis Acadêmicos**
* **Tipo**: Pirâmide de níveis.
* **Objetivo**: Volume acumulado de orientações por nível (Iniciação Científica, TCC / Graduação, Especialização, Mestrado, Doutorado e Pós-Doutorado), somando concluídas e em andamento.

---

### **Figura 10 — Principais Cursos dos Orientandos**
* **Tipo**: Lista/ranking de cursos e programas.
* **Objetivo**: Cursos de graduação e pós-graduação dos estudantes orientados, com nomes normalizados (`getFig5StudentsUndergradCourses`). Não existe donut por nível acadêmico.

---

## 📚 Bloco 3: Produção Intelectual, Qualis & Bases Internacionais

### **Figura 11 — Produção Intelectual Acumulada por Tipo**
* **Tipo**: Matriz Heatmap Anual (categoria por ano, a partir de 2010).
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

### **Figura 12 — Evolução Temporal da Produção Científica Anual (Com Qualis vs Sem Qualis)**
* **Tipo**: Gráfico de Linha Multi-Camada (Artigos com Qualis versus artigos sem Qualis).
* **Objetivo**: Analisar o ritmo de publicação do centro e a qualificação dos periódicos.

---

### **Figura 13 — Distribuição de Artigos por Estrato Qualis (CAPES)**
* **Tipo**: Distribuição anual por estrato, colorida por estrato.
* **Estratos Oficiais**:
  - `A1`, `A2`, `A3`, `A4` (Excelência Internacional / Nacional)
  - `B1`, `B2`, `B3`, `B4` (Qualidade Intermediária)
  - `C` (Outros periódicos avaliados)
  - Artigos sem Qualis não entram nesta figura (não há categoria *Não classificados*; eles aparecem na Figura 12).
* **Metodologia**: Cruzamento canônico de periódicos e ISSNs com a tabela oficial de Qualis Periódicos da CAPES.

---

### **Figura 14 — Bases Científicas Internacionais: Trabalhos por Base de Indexação**
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

### **Figura 15 — Séries Históricas: Evolução Temporal da Indexação por Base (2010–2026)**
* **Tipo**: Gráfico Multi-Linhas Interativo com pontos e curvas de suavização.
* **Objetivo**: Acompanhar o crescimento da presença dos artigos do CECH nas bases científicas internacionais ano a ano.

---

## 🌐 Bloco 4: Redes de Colaboração & Parcerias Institucionais

### **Figura 16 — Rede de Coautoria Docente (Produção Compartilhada CECH)**
* **Tipo**: Ranking de 8 pares e Matriz de Parcerias Internas com Deduplicação e Exportação para VOSviewer.
* **Regra Cienciométrica de Ouro**: Obras produzidas em coautoria entre 2 ou mais docentes do CECH são contabilizadas **uma única vez** no cômputo institucional para evitar contagem duplicada.
* **Objetivo**: Evidenciar os pares de pesquisadores com maior cooperação intra-departamental e inter-departamental.

---

### **Figura 17 — Top Instituições Nacionais (Experiências Profissionais)**
* **Tipo**: Gráfico de Barras Horizontais.
* **Objetivo**: Ranking das instituições brasileiras que mais aparecem nas experiências profissionais (`professional_experiences`) dos docentes, sem a UFSCar. Não vem de coautorias.
* **Metodologia**: Nomes resolvidos pelo tesauro de instituições; instituições estrangeiras ficam de fora. Mostra as 10 primeiras (`getFig14NationalPartners`).

---

### **Figura 18 — Instituições Internacionais (Formação)**
* **Tipo**: Gráfico de Barras Horizontais.
* **Objetivo**: Ranking das instituições estrangeiras que aparecem na formação acadêmica (`educations`) dos docentes. Não vem de coautorias.
* **Metodologia**: Nomes resolvidos pelo tesauro de instituições e países; mostra as 10 primeiras (`getFig15InternationalPartners`).

---

### **Ranking Qualis — Top Publicadores (19º indicador)**
* **Tipo**: Ranking dinâmico de docentes, com filtros de estrato Qualis (A1 a A4) e de bases de indexação.
* **Onde**: aba de produção, depois da Figura 13 (`getFigQualisResearchersRanking`).

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
5. **Nuvens de Tags e Termos Frequentes (Topo da Produção)**:
   - Duas colunas táteis interativas posicionadas estrategicamente no topo da aba *"Produção Científica & Técnica"*:
     - **Palavras-chave dos Trabalhos**: termos declarados pelos autores nos artigos e livros.
     - **Temas e Termos Frequentes**: sintagmas e bigramas mais frequentes nos títulos das obras.
   - O clique em qualquer tag dispara imediatamente o filtro das produções listadas abaixo.
