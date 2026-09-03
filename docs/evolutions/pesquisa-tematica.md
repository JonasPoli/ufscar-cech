# Evolução: Pesquisa Temática por Palavras-Chave (`/temas`)

Este documento registra os requisitos, modelagem de dados, serviços de indexação, endpoints da API, arquitetura de interface e integrações da funcionalidade de **Pesquisa Temática por Palavras-Chave e Sintagmas Conceituais** do CECH UFSCar.

---

## 1. 🎯 Objetivos e Justificativa

O portal reunia milhares de produções científicas (artigos, livros, capítulos, eventos, patentes, etc.) e teses/dissertações orientadas, porém a busca era predominantemente nominal (por pesquisador) ou textual simples.

A **Pesquisa Temática** introduz uma camada ontológica e semântica de descoberta científica que permite:
1. Consolidar uma superbase unificada de termos, conceitos e sintagmas mais recorrentes nos títulos e palavras-chave informadas pelos pesquisadores.
2. Cruzar as fontes de dados oficiais:
   - **Currículo Lattes**: Títulos de produções bibliográficas, técnicas, artísticas e palavras-chave declaradas.
   - **Repositório Institucional da UFSCar (TeD-UFSCar)**: Títulos, resumos e assuntos/palavras-chave de teses e dissertações orientadas.
3. Quantificar o volume de ocorrências de cada termo no centro e o número de docentes que pesquisam na temática.
4. Fornecer uma interface visual de exploração com busca instantânea (autocomplete debounce), nuvem de tags ponderada por cores e detalhamento de departamento.
5. Permitir a transição direta para o perfil do docente, carregando o termo pesquisado e aplicando o filtro de produções automaticamente.

---

## 2. 🗄️ Modelagem do Banco de Dados

A funcionalidade é suportada por duas entidades Doctrine versionadas exclusivamente via migrations (`migrations/Version20260903152327.php`):

### 2.1 `ThematicTerm` (Tabela: `thematic_terms`)
Armazena o vocabulário canônico dos termos indexados.

| Campo | Tipo | Descrição |
| :--- | :--- | :--- |
| `id` | INT (PK) | Identificador primário autoincrementado |
| `term` | VARCHAR(200) | Nome oficial com pontuação/acentuação original (ex: *Educação Especial*, *Inteligência Artificial*) |
| `normalized_term` | VARCHAR(200) | Termo em minúsculas e sem acentuação para busca indexada rápida |
| `slug` | VARCHAR(200) (UNIQUE) | Identificador amigável para URLs permalink (ex: `/temas?t=educacao-especial`) |
| `total_occurrences` | INT | Soma total de ocorrências em títulos e palavras-chave de todo o acervo |
| `researcher_count` | INT | Número total de docentes distintos associados a este tema |
| `created_at` | DATETIME | Data e hora da primeira indexação do termo |
| `updated_at` | DATETIME | Data e hora da última reindexação |

### 2.2 `ThematicTermResearcher` (Tabela: `thematic_term_researchers`)
Tabela de junção ponderada entre o termo e cada docente.

| Campo | Tipo | Descrição |
| :--- | :--- | :--- |
| `id` | INT (PK) | Identificador primário |
| `term_id` | INT (FK) | Chave estrangeira para `thematic_terms` |
| `researcher_id` | INT (FK) | Chave estrangeira para `researchers` |
| `occurrences` | INT | Quantidade de trabalhos/orientações do pesquisador vinculadas a este termo |
| `sample_titles` | JSON | Amostra (até 3 títulos) de produções representativas do docente no tema |

---

## 3. ⚙️ Serviço de Indexação: `ThematicTermIndexService`

O serviço [`ThematicTermIndexService`](file:///Users/jonaspoli/work/html/ufscar-cech/src/Service/Indexing/ThematicTermIndexService.php) executa a mineração de dados:

1. **Extração das Palavras-Chave**:
   - Varrimento de todas as palavras-chave declaradas nas produções científicas Lattes.
   - Extração de sintagmas nominais e bigramas significativos a partir dos títulos das obras.
   - Cruzamento com as palavras-chave e assuntos do Repositório Institucional TeD-UFSCar.
   - Filtragem rigorosa através de stop-words acadêmicas e do português (termos genéricos como "estudo", "análise", "brasil", preposições e artigos são ignorados).
2. **Normalização e Deduplicação**:
   - Tratamento de singular/plural e padronização semântica.
   - Associação de docentes e cálculo da matriz de frequência (`occurrences`).
3. **Execução via Console CLI**:
   ```bash
   # Rodar indexação temátia localmente:
   php bin/console app:index-thematic-terms

   # Execução no servidor de produção:
   /RunCloud/Packages/php84rc/bin/php bin/console app:index-thematic-terms --env=prod
   ```

---

## 4. 🌐 Controladores e Rotas da Aplicação

Gerenciado pelo [`ThematicSearchController`](file:///Users/jonaspoli/work/html/ufscar-cech/src/Controller/pub/ThematicSearchController.php):

### 4.1 Rota Pública: `/temas` (`app_pub_thematic_search`)
- **Template**: [`templates/pub/thematic_search/index.html.twig`](file:///Users/jonaspoli/work/html/ufscar-cech/templates/pub/thematic_search/index.html.twig).
- **Parâmetro de Consulta**: `?t={slug}` para carregar um tema diretamente com permalink permanente.
- **Estatísticas no Header**: Contadores em tempo real de temas indexados, docentes analisados e produções/teses cobertas.
- **Design Adaptativo**: Cartão em `glass-card` com adaptação visual completa ao **Modo Claro** e **Modo Escuro**, sem caixas escuras fixas.
- **Eyebrow sem Blurb**: Identificador *"Descoberta Acadêmica • CECH UFSCar"* estilizado de forma elegante com `<sl-icon name="stars">` sem balão/pílula de fundo.

### 4.2 API Autocomplete: `/api/temas/autocomplete` (`app_api_thematic_autocomplete`)
- **Entrada**: `GET /api/temas/autocomplete?q={termo}` (mínimo de 3 caracteres).
- **Resposta JSON**:
  ```json
  {
    "count": 50,
    "terms": [
      {
        "id": 142,
        "term": "Educação Especial",
        "slug": "educacao-especial",
        "totalOccurrences": 1420,
        "researcherCount": 38,
        "weight": 100
      }
    ]
  }
  ```
- **Pesos e Cores na Nuvem de Tags**:
  - `weight >= 75`: Destaque máximo em roxo/índigo (`bg-indigo-600 text-white font-bold`).
  - `weight >= 45`: Destaque forte em azul celeste (`bg-sky-500 text-white font-semibold`).
  - `weight >= 20`: Destaque intermediário (`bg-sky-100 dark:bg-sky-950/70 text-sky-900 dark:text-sky-200`).
  - `weight < 20`: Termos suaves (`bg-slate-100 dark:bg-slate-900/60`).

### 4.3 API de Docentes: `/api/temas/docentes` (`app_api_thematic_researchers`)
- **Entrada**: `GET /api/temas/docentes?term_id={id}&offset={offset}&limit=10`
- **Paginação Dinâmica**: Carrega 10 docentes por vez.
- **Botão "Mais"**: Permite carregar blocos subsequentes de 10 em 10 de forma assíncrona.
- **Distribuição Departamental**: Retorna o ranking de departamentos onde o tema é mais investigado.

---

## 5. 🔗 Integração e Deep-Linking com o Perfil do Docente

Ao clicar em um docente no resultado da pesquisa temática:
1. O link do card é gerado incluindo o parâmetro `?tema={nome_do_tema}#productions`:
   ```html
   <a href="/professor/joao-silva?tema=Educa%C3%A7%C3%A3o%20Especial#productions">
   ```
2. Ao abrir o perfil do docente:
   - O campo de busca rápida (`#prodSearchInput`) já é inicializado com o tema preenchido.
   - A aba de **Produção Científica & Técnica** é ativada.
   - O filtro cienciométrico `applyGlobalFilter()` é disparado imediatamente.
   - Um banner de status visualiza o filtro ativo:
     `Filtrando por: "Educação Especial"` com botão para limpar busca.
   - A normalização de acentuação (`normalizeFilterStr`) assegura correspondência precisa mesmo que haja divergências de acentos ortográficos.
   - A página efetua uma rolagem suave (*smooth scroll*) até a barra de filtros para exibir os resultados pertinentes de imediato.
