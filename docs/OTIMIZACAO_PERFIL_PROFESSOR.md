# Plano de Otimização e Modularização do Perfil Docente (`/professor/{slug}`)

Este documento estabelece o diagnóstico, arquitetura, design e estratégia de execução para a otimização de performance e usabilidade mobile da página de perfil dos pesquisadores do CECH UFSCar ([`ProfessorController::show`](file:///Users/jonaspoli/work/html/ufscar-cech/src/Controller/pub/ProfessorController.php#L46) e [`templates/pub/professor/show.html.twig`](file:///Users/jonaspoli/work/html/ufscar-cech/templates/pub/professor/show.html.twig)).

---

## 1. 🔍 Diagnóstico do Cenário Atual

Atualmente, para docentes altamente produtivos (com centenas de publicações, livros, bancas e orientações ao longo de 20 a 40 anos de carreira acadêmica), a página apresenta os seguintes gargalos:

1. **Payload HTML Monolítico**:
   - Todas as 10 seções do currículo (Artigos, Livros, Capítulos, Eventos, Produções Técnicas, Softwares, Patentes, Orientações Concluídas, Orientações em Andamento, Projetos, Atuação Profissional, Formação Acadêmica, Formação Complementar, Bancas Examinadoras, Eventos Participados, Prêmios e Áreas de Atuação) são geradas de forma síncrona no primeiro request.
   - O documento HTML pode ultrapassar 300 KB a 800 KB e mais de 2.500 elementos DOM.
2. **"Parede" de Gráficos e Blocos de Inteligência no Topo**:
   - Quatro blocos densos (Painel de 3 Gráficos Chart.js + Painel de Bases de Indexação Internacional + Rede de Coautoria + Nuvens de Palavras-Chave e Mineração de Termos) ficam empilhados verticalmente antes do conteúdo das produções, exigindo rolagem excessiva no celular.
3. **Muitas Abas no Mobile**:
   - As 10 abas em `<sl-tab-group>` sobrecarregam a navegação horizontal em telas pequenas.

---

## 2. 🏛️ Arquitetura Proposta: 4 Abas Consolidadas com Lazy Loading

Reorganização de todas as 10 seções em **4 Abas Temáticas Principais**, com carregamento assíncrono sob demanda (Lazy Loading) e cache em memória no cliente:

```
┌─────────────────────────────────────────────────────────────────────────────────────────────────┐
│                                   PERFIL DO DOCENTE / PESQUISADOR                               │
├─────────────────────────────────────────────────────────────────────────────────────────────────┤
│ [Foto] Nome do Docente, Departamento, Nomes em Citação, Resumo Lattes, Links & Botões de Ação   │
│ KPIs Rápidos: [Artigos Periódicos] [Livros/Capítulos] [Orientações] [Projetos] [Total Acervo]   │
├───────────────────────┬───────────────────────┬─────────────────────────┬───────────────────────┤
│     1. PRODUÇÃO       │    2. ORIENTAÇÕES &   │   3. PROJETOS, BANCAS   │     4. MÉTRICAS,      │
│  CIENTÍFICA & TÉCNICA │       FORMAÇÃO        │        & ATUAÇÃO        │     REDES & BASES     │
│   (Artigos, Livros,   │ (Concluídas, Andamento│  (Projetos, Bancas,     │ (Evolução Anual, WoS, │
│  Capítulos, Eventos,  │  Formação Acadêmica & │   Atuação Profissional, │ Scopus, Coautores,    │
│    Técnicos, etc.)    │     Complementar)     │   Eventos, Prêmios)     │   Nuvem de Termos)    │
│   `_tab_productions`  │ `_tab_orientations`   │     `_tab_activities`   │    `_tab_analytics`   │
└───────────────────────┴───────────────────────┴─────────────────────────┴───────────────────────┘
```

---

## 3. 🧩 Detalhamento dos Componentes e Abas

### 🏷️ Cabeçalho & KPIs (Fixo no Topo)
* **Identificação**: Foto/Avatar, Nome Completo, Departamento, Resumo Biográfico Lattes, Nomes em Citação e Links (Lattes, ORCID, Google Scholar, E-mail).
* **Ações**: Botões de exportação (BibTeX, JSON, CSV) e link para Repositório UFSCar.
* **Barra de KPIs**: 5 cards compactos e responsivos.
* **Navegação de Abas Touch-Friendly**: Pills horizontais com contador de itens e suporte a URL Hash (`#productions`, `#orientations`, `#activities`, `#analytics`).

---

### 📄 Aba 1: Produção Científica & Técnica (`_tab_productions.html.twig`)
* **Toolbar de Filtros**:
  - Filtro por Categoria: Todas, Artigos em Periódicos (com estratos Qualis Sucupira e selos de bases de indexação), Livros, Capítulos, Textos em Jornais/Revistas, Trabalhos em Eventos, Produções Técnicas, Softwares, Patentes/Marcas e Produções Artísticas.
  - Filtro Rápido: *"Apenas com DOI"*, busca instantânea por título/ano/periódico.
* **Botão "Repositório UFSCar"**: Link direto para teses e dissertações orientadas no repositório institucional.

---

### 🎓 Aba 2: Orientações & Formação Acadêmica (`_tab_orientations.html.twig`)
* **Orientações Concluídas e em Andamento**:
  - Organizadas por nível: Doutorado, Mestrado, Pós-Doutorado, TCC de Graduação, Iniciação Científica (PIBIC/PIBITI) e Especialização.
  - Exibição de Orientando, Título do Trabalho, Ano, Instituição e Agência de Fomento (FAPESP, CNPq, CAPES).
  - Tags de **"Coorientação"** e botão de acesso direto ao **Repositório UFSCar** com badge de tipo (Tese / Dissertação).
* **Formação Acadêmica & Complementar**:
  - Graduação, Mestrado, Doutorado e Pós-Doutorados com períodos, orientadores e títulos das teses.
  - Cursos de curta duração, aperfeiçoamento e extensão.

---

### 🔬 Aba 3: Projetos, Bancas & Atuação Profissional (`_tab_activities.html.twig`)
* **Projetos de Pesquisa & Extensão**: Título, período, coordenador/integrantes, agência financiadora e descrição.
* **Atuação Profissional**: Vínculos empregatícios, cargos ocupados, linhas de pesquisa e atividades docentes.
* **Bancas Examinadoras**: Mestrado, Doutorado, Qualificações, Comissões Julgadoras e Concursos Públicos.
* **Eventos & Prêmios**: Participações em congressos, conferências e títulos honoríficos.

---

### 📊 Aba 4: Métricas, Inteligência & Redes (`_tab_analytics.html.twig`)
* **Evolução da Produção Anual (Timeline Chart.js)**: Gráfico de barras empilhadas cronológicas.
* **Distribuição por Categoria**: Gráfico Donut de tipos de trabalhos.
* **Bases de Indexação Internacional (Scopus, Web of Science, Latindex, SciELO, DOAJ, PubMed, OpenAlex)**:
  - Cards de ranking por base e percentual indexado.
  - Gráfico de barras comparativo de bases + Gráfico de linha de evolução temporal.
* **Rede de Coautoria & Colaboradores do CECH**: Cards com fotos dos principais parceiros de publicação e links diretos para seus perfis.
* **Nuvem de Palavras-Chave**: Termos cadastrados no Lattes + Mineração de sintagmas em títulos com filtragem interativa.

---

## 4. ⚡ Otimizações Técnicas e Estratégia de Fragmentos

1. **Fragmentos com Cache HTTP**:
   - Rota: `#[Route('/professor/{slugOrId}/fragment/{tab}', name: 'app_pub_professor_fragment')]`
   - Resposta com cabeçalhos `Cache-Control: public, max-age=3600`.
2. **Carga Inicial Leve**:
   - O request inicial renderiza o cabeçalho e a Aba 1 (`productions`).
   - As demais abas são carregadas em segundo plano quando o usuário clica nelas.
3. **Cache no DOM do Cliente**:
   - Abas baixadas são mantidas em memória (`Set` de abas carregadas), garantindo alternância em **0ms**.
4. **Sincronização de Estado via URL**:
   - Ao clicar em uma aba ou ao compartilhar o link com `#analytics` ou `#orientations`, o navegador abre direto na seção desejada.

---

## 5. 📋 Etapas de Implementação

1. [ ] **Criar os 4 Templates Parciais**:
   - `templates/pub/professor/_tab_productions.html.twig`
   - `templates/pub/professor/_tab_orientations.html.twig`
   - `templates/pub/professor/_tab_activities.html.twig`
   - `templates/pub/professor/_tab_analytics.html.twig`
2. [ ] **Atualizar o Controller [`ProfessorController.php`](file:///Users/jonaspoli/work/html/ufscar-cech/src/Controller/pub/ProfessorController.php)**:
   - Adicionar método `professorFragment(string $slugOrId, string $tab)` com validação e cache.
   - Modularizar agregação de dados no método `show()` por aba.
3. [ ] **Refatorar o Template Principal [`templates/pub/professor/show.html.twig`](file:///Users/jonaspoli/work/html/ufscar-cech/templates/pub/professor/show.html.twig)**:
   - Implementar pills de abas e container dinâmico com skeleton loader.
4. [ ] **Executar Testes e Validação**:
   - `php bin/console lint:twig templates`
   - `APP_ENV=test ./vendor/bin/phpunit --testdox`
   - `./build.sh`
