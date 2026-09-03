# Sistema de Palavras-Chave e Descoberta Temática — CECH / UFSCar

Este documento descreve detalhadamente o funcionamento, arquitetura de dados, fluxos de indexação, interface de usuário e os mecanismos de resolução inteligente do **Sistema de Palavras-Chave e Descoberta Temática** do portal do Centro de Educação e Ciências Humanas (CECH) da UFSCar.

---

## 1. 🎯 Visão Geral e Objetivos

O acervo do CECH agrega milhares de registros científicos produzidos ao longo de décadas pelos docentes de seus departamentos acadêmicos. O Sistema de Palavras-Chave cumpre três funções estratégicas:

1. **Unificação Multi-Fonte**: Cruzar palavras-chave atribuídas pelos próprios pesquisadores no **Currículo Lattes** com os termos, resumos e assuntos catalogados no **Repositório Institucional da UFSCar (TeD-UFSCar)** para teses e dissertações.
2. **Descoberta Ontológica e Temática (`/temas`)**: Disponibilizar uma interface centralizada onde alunos, gestores, empresas e outros acadêmicos encontrem quem pesquisa determinado assunto, quais departamentos concentram a produção e quais trabalhos exemplificam a temática.
3. **Navegação Contínua e Deep-Linking**: Permitir que uma busca por palavra-chave direcione o usuário ao perfil exato do professor com filtro ativado e abertura automática na aba correta (artigos, orientações ou projetos).

---

## 2. 🗄️ Origem e Ingestão dos Dados

Em conformidade estrita com a **Regra de Preservação de Dados Lattes** do projeto, os dados brutos recebidos nunca são modificados, formatados destrutivamente ou apagados.

```
┌──────────────────────────────────────────────┐     ┌──────────────────────────────────────────────┐
│          Plataforma Lattes (CNPq)            │     │    Repositório Institucional (TeD-UFSCar)    │
│  - Produções bibliográficas e técnicas       │     │  - Teses e Dissertações defendidas           │
│  - Palavras-chave declaradas                 │     │  - Assuntos controlados e livres (pt / en)   │
│  - Títulos de orientações e bancas           │     │  - Títulos alternativos em inglês            │
└──────────────────────┬───────────────────────┘     └──────────────────────┬───────────────────────┘
                       │                                                    │
                       ▼                                                    ▼
         ┌───────────────────────────┐                        ┌───────────────────────────┐
         │      ProductionItem       │                        │        Orientation        │
         │ - keywords (JSON/Array)   │                        │ - keywords (string/CSV)   │
         │ - title                   │                        │ - alternative_title       │
         └─────────────┬─────────────┘                        └─────────────┬─────────────┘
                       │                                                    │
                       └──────────────────────┬─────────────────────────────┘
                                              ▼
                             ┌──────────────────────────────────┐
                             │    ThematicTermIndexService      │
                             │ (Mineração, Ponderação e Vínculo)│
                             └────────────────┬─────────────────┘
                                              ▼
                      ┌────────────────────────────────────────────────┐
                      │ - thematic_terms (Superbase de Conceitos)      │
                      │ - thematic_term_researchers (Matriz Ponderada) │
                      └────────────────────────────────────────────────┘
```

### 2.1 Campos nas Entidades Principais
- **`ProductionItem` (`production_items`)**:
  - `keywords`: Array/JSON com as palavras-chave declaradas no Lattes (ex: `["Educação Especial", "Inclusão Escolar"]`).
  - `title`: Título original da produção bibliográfica ou técnica.
- **`Orientation` (`orientations`)**:
  - `keywords`: Texto separado por ponto-e-vírgula contendo os termos de catalogação da tese/dissertação/TCC (ex: `Abordagem das competências; Institutos Públicos de Pesquisa; Public Research Institutes`).
  - `title`: Título em português da orientação.
  - `alternativeTitle`: Título alternativo (frequentemente em inglês), capturado do Repositório Institucional.

---

## 3. ⚙️ Mineração e Indexação: `ThematicTermIndexService`

O serviço [`ThematicTermIndexService`](file:///Users/jonaspoli/work/html/ufscar-cech/src/Service/Indexing/ThematicTermIndexService.php) é responsável por ler todo o acervo e construir a camada ontológica:

### 3.1 Etapas de Processamento
1. **Extração de Termos Explícitos**:
   - Varrimento de todas as palavras-chave de `ProductionItem` e `Orientation`.
   - Limpeza de pontuação periférica, aspas e quebras de linha.
2. **Mineração de Sintagmas e Bigramas a partir de Títulos**:
   - Identificação de termos compostos de relevância científica (ex: *"Gestão Pública"*, *"Inteligência Artificial"*, *"Evasão Escolar"*).
   - Filtro de *stopwords* da língua portuguesa e inglesa (artigos, preposições, pronomes e verbos transitórios).
3. **Normalização Semântica**:
   - Gravação do termo canônico (`term`) preservando a capitalização correta.
   - Geração de `normalized_term` (minúsculas, sem acentos e sem cedilha) para consultas ultra-rápidas.
   - Criação de `slug` único para URLs amigáveis (ex.: `public-research-institutes`, `evasao-escolar`).
4. **Ponderação e Associação com Pesquisadores**:
   - Para cada termo e cada docente, contabiliza o número de ocorrências (`occurrences`).
   - Seleciona até 3 títulos de produções/orientações mais representativas (`sample_titles`) para visualização rápida no card do docente.
   - Atualiza `total_occurrences` e `researcher_count` em `thematic_terms`.

### 3.2 Execução Via Console
Para reindexar a base temáticas após importações do Lattes ou do Repositório:
```bash
# Execução padrão em desenvolvimento
php bin/console app:index:thematic-terms

# Com limite mínimo de ocorrências (ex: termos que aparecem pelo menos 2 vezes)
php bin/console app:index:thematic-terms --min-occurrences=2

# Execução no servidor de produção (RunCloud)
/RunCloud/Packages/php84rc/bin/php bin/console app:index:thematic-terms --env=prod
```

---

## 4. 🌐 Interface de Descoberta Temática (`/temas`)

A rota [`/temas`](https://cech.wab.com.br/temas) (controlada por `ThematicSearchController`) provê uma experiência de exploração moderna:

1. **Nuvem de Tags Semântica**:
   - Os 50 termos mais frequentes do centro são renderizados com pesos cromáticos calculados dinamicamente:
     - `weight >= 75`: Destaque máximo em roxo/índigo (`bg-indigo-600 text-white font-bold`).
     - `weight >= 45`: Destaque forte em azul celeste (`bg-sky-500 text-white font-semibold`).
     - `weight >= 20`: Destaque intermediário (`bg-sky-100 text-sky-900 dark:bg-sky-950 dark:text-sky-200`).
     - `weight < 20`: Termos complementares em cinza neutro.
2. **Busca Instantânea com Autocomplete**:
   - O campo de pesquisa conta com *debounce* de 250ms conectado ao endpoint `/api/temas/autocomplete?q=termo`.
   - Busca simultaneamente no termo original e na versão normalizada.
3. **Lista de Docentes e Exemplos de Trabalhos**:
   - Ao selecionar um tema, o grid carrega os 10 primeiros docentes mais produtivos naquele tópico.
   - Cada card exibe:
     - Foto oficial do docente e departamento de lotação.
     - Contagem de produções no tema: *"X trabalho(s) neste tema"*.
     - Amostra com citação de uma produção ou orientação representativa.
     - Botão assíncrono *"Carregar mais docentes"* para paginação de 10 em 10 via `/api/temas/docentes`.
4. **Distribuição Departamental**:
   - Painel lateral que ilustra o ranking dos departamentos do CECH que mais produzem no tema selecionado.
5. **Gráfico de Linhas da Evolução Temporal (Linha do Tempo Anual)**:
   - Apresenta a evolução cronológica contínua de trabalhos que possuem este tema, **desde o primeiro ano registrado até o ano atual** (preenchendo lacunas com 0).
   - Indicadores-chave rápidos (badges):
     - **1º Registro**: primeiro ano identificado no acervo (ex.: 1982 para *Educação Especial*).
     - **Ano de Pico**: ano com maior volume de publicações e respectiva contagem (ex.: 2013 com 176 trabalhos).
     - **Total Mapeado**: somatório de trabalhos identificados no período.
   - Alternância interativa de séries:
     - **Total de Trabalhos**: linha contínua suavizada com área preenchida em degradê celeste (`sky-500`).
     - **Produções vs. Orientações**: desdobramento em duas séries independentes (Produções Científicas/Técnicas em azul e Orientações/Teses em verde esmeralda).
    - Tooltips detalhados com informações sobre artigos/livros e teses/dissertações em cada ponto.
    - Atualização dinâmica instantânea via AJAX ao selecionar qualquer termo sem recarregar a página.
6. **Conceitos e Palavras-Chave Relacionadas (Análise de Co-ocorrência / *Co-word*)**:
   - Identifica os termos e palavras-chave que mais aparecem em conjunto com o tema selecionado nas produções e orientações do acervo.
   - Apresenta badges interativas com contagem de ocorrências conjuntas (ex.: para *Educação Especial* &rarr; *Inclusão Escolar* [349], *Special Education* [302], *Formação de Professores* [169], *Educação Inclusiva* [160]).
   - Clicar em qualquer conceito relacionado seleciona e transiciona imediatamente para o novo tema sem recarregar a página.
7. **Qualidade Editorial (Qualis CAPES) & Top Periódicos**:
   - **Distribuição por Estrato Qualis (Gráfico Donut)**: visualização percentual dos artigos em estratos A1, A2, A3, A4, B1 a B4 e C.
   - **Indicador de Excelência**: badge em destaque com a porcentagem de artigos concentrados nos estratos de topo (A1 e A2).
   - **Ranking dos Principais Periódicos**: mini-cards com as revistas científicas mais frequentes no tema, seus respectivos selos Qualis coloridos e contagem de artigos.

---

## 5. ⚡ Resolução Inteligente de Abas e Deep-Linking no Perfil Docente

Ao clicar no card de um docente em [`/temas`](https://cech.wab.com.br/temas), a URL é gerada de forma limpa:
```html
<a href="/professor/roniberto-morato-do-amaral?tema=Public%20Research%20Institutes">
```

### 5.1 Pré-Carregamento Unificado do DOM
Diferente do modelo tradicional onde abas secundárias eram vazias (*lazy loading* assíncrono), o perfil do docente agora renderiza no primeiro carregamento:
- `pane-productions`: Produções bibliográficas, técnicas e artísticas.
- `pane-orientations`: Orientações concluídas, orientações em andamento e formação acadêmica.
- `pane-activities`: Projetos de pesquisa/extensão, bancas examinadoras e atuações profissionais.

Isso viabiliza que o motor JavaScript filtre **todas as abas simultaneamente em menos de 5 milissegundos**, sem gerar requisições de rede adicionais.

### 5.2 Exibição e Atributos de Metadados nas Orientações
Tanto em orientações concluídas quanto em andamento ([`_tab_orientations.html.twig`](file:///Users/jonaspoli/work/html/ufscar-cech/templates/pub/professor/_tab_orientations.html.twig)):
- As palavras-chave (`o.keywords`) são quebradas em badges interativas:
  ```html
  <button type="button" onclick="filterByKeyword('Public Research Institutes')" class="rounded-md bg-emerald-50 text-emerald-800 ...">
      #Public Research Institutes
  </button>
  ```
- O título alternativo em inglês (`o.alternativeTitle`) é exibido abaixo do título principal.
- O card `.orientation-item` recebe os atributos:
  ```html
  <div class="orientation-item ..." 
       data-keywords="Abordagem das competências; Public Research Institutes; ..." 
       data-alt-title="Contribution of indicators for the identification...">
  ```

### 5.3 Algoritmo de Seleção Automática de Aba (`DOMContentLoaded`)
Ao carregar a página com o parâmetro `?tema={termo}`:
1. O termo é inserido no campo de busca `#prodSearchInput`.
2. O filtro cienciométrico `applyGlobalFilter()` é executado:
   - Avalia `.prod-item`: computa `lastFilterCounts.productions`.
   - Avalia `.orientation-item` (incluindo título, palavras-chave e título alternativo): computa `lastFilterCounts.orientations`.
   - Avalia `.project-item`, `.board-item`, `.event-item`: computa `lastFilterCounts.activities`.
3. **Decisão Automática da Aba**:
   - **Caso 1**: Se `lastFilterCounts.productions > 0` &rarr; Ativa a aba **Produção Científica & Técnica**.
   - **Caso 2**: Se `lastFilterCounts.productions === 0` e `lastFilterCounts.orientations > 0` (ex: Prof. Roniberto com a tese de Marcela Torres sobre *Public Research Institutes*, ou Prof. Eduardo Risk sobre *Evasão Escolar*) &rarr; O sistema **ativa automaticamente a aba Orientações & Formação** (`switchProfessorTab('orientations')`).
   - **Caso 3**: Se houver resultados apenas em projetos ou bancas &rarr; Ativa automaticamente **Projetos, Bancas & Atuação**.
4. **Atualização dos Contadores das Abas**:
   - As pílulas de contagem indicam com clareza onde os itens estão (ex.: `Produção (0/183)`, `Orientações (1/107)`).
5. **Banner de Detalhamento Ativo**:
   - Exibe o resumo: `Filtrando por: "Public Research Institutes" • 1 registro(s) encontrado(s) (1 em Orientações)`.
6. **Sugestões Cruzadas em Estados Vazios**:
   - Caso o usuário mude manualmente para uma aba que não possui registros para aquele filtro, o card de aviso exibe botões de atalho:
     ```
     Nenhuma produção encontrada para o filtro selecionado.
     Foram encontrados resultados deste tema em outra seção:
     [ Ver 1 em Orientações & Formação → ]
     ```
7. **Cliques em Badges de Tags**:
   - Clicar em qualquer tag `#tag` no currículo invoca `filterByKeyword(keyword)`. A função preenche a busca, roda o filtro global e alterna imediatamente para a aba onde a tag está presente.

---

## 6. 📊 Resumo das Rotas e Serviços

| Rota / Serviço | Tipo | Finalidade |
| :--- | :--- | :--- |
| `/temas` | Rota Pública (Twig) | Página principal de exploração temática, nuvem de conceitos e evolução temporal |
| `/api/temas/autocomplete` | API Pública (JSON) | Busca instantânea de termos para o autocomplete |
| `/api/temas/docentes` | API Pública (JSON) | Retorna docentes paginados (10 por página), distribuição departamental e linha do tempo |
| `/api/temas/evolucao` | API Pública (JSON) | Linha do tempo anual (do primeiro ano ao ano atual) com total de trabalhos no tema |
| `/professor/{slugOrId}?tema={termo}` | Rota Pública (Twig) | Perfil docente com filtro ativado e redirecionamento inteligente de abas |
| `ThematicTermIndexService` | Serviço PHP | Serviço de mineração, contagem e ponderação de termos |
| `app:index:thematic-terms` | Comando Console | CLI para indexação periódica da base temática |

---

## 7. 🛡️ Testes Automatizados

A funcionalidade é coberta por testes automatizados em [`ThematicSearchControllerTest`](file:///Users/jonaspoli/work/html/ufscar-cech/tests/Controller/pub/ThematicSearchControllerTest.php) e [`PublicRoutesTest`](file:///Users/jonaspoli/work/html/ufscar-cech/tests/Controller/pub/PublicRoutesTest.php):
- Carregamento da rota `/temas` e seleção de temas.
- Autocomplete com termos parciais e termos acentuados.
- Paginação da API de docentes.
- Geração de links com deep-linking (`?tema=...`).
- Pré-carregamento e integridade dos nós DOM nas abas do professor.
- Execução:
  ```bash
  APP_ENV=test ./vendor/bin/phpunit tests/Controller/pub/ThematicSearchControllerTest.php
  ```
