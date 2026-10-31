# Gestão de Tesauros e Vocabulários Controlados — CECH / UFSCar

Este documento descreve o funcionamento do sistema de tesauros controlados, normalização de cadeias de texto, formatos de intercâmbio de dados e o mecanismo de fusão (*merge*) de entidades.

---

## 🎯 Objetivo dos Tesauros

Em bases bibliométricas e curriculares (como a Plataforma Lattes), os nomes de **instituições**, **países**, **autores** e **periódicos**, e as **categorias de revistas** (as áreas que cada base dá a um periódico), são frequentemente inseridos pelos próprios usuários de forma livre ou variam de uma fonte para outra, gerando centenas de grafias variantes para uma mesma entidade:

- **Exemplo de Instituição**: `Universidade Federal de São Carlos`, `UFSCar`, `Univ. Fed. de São Carlos`, `U.F.S.CARLOS`, `Federal University of Sao Carlos`.
- **Exemplo de País**: `Brasil`, `Brazil`, `Republica Federativa do Brasil`, `BRA`.
- **Exemplo de Autor**: `Silva, Carlos Alberto`, `SILVA, C. A.`, `SILVA, Carlos A.`, `SILVA, CARLOS ALBERTO DA`.
- **Exemplo de Periódico**: `Revista Brasileira de Educação`, `Rev. Bras. Educ.`, `REVISTA BRASILEIRA DE EDUCACAO`.
- **Exemplo de Categoria de revista**: `Educação` (Lattes), `Education & Educational Research` (Web of Science), `Educación` (Latindex) e o código `3304` (ASJC, do Scopus e do OpenAlex) são a mesma área CAPES, Educação.

O subsistema de tesauros padroniza essas variações sob um **Termo Preferido (Preferred / Canonical Name)**, mantendo uma lista vinculada de **Variantes (Variants / Synonyms)**.

---

## ⚙️ Algoritmo de Normalização de Strings (`StringNormalizer`)

A classe `App\Service\Thesaurus\StringNormalizer` fornece métodos para padronização determinística:

```php
// Remove acentuação, caracteres especiais e converte para caixa alta
$normalized = StringNormalizer::normalizeString("Universidade Federal de São Carlos");
// Resultado: "UNIVERSIDADE FEDERAL DE SAO CARLOS"

// Geração de slugs para URLs amigáveis
$slug = StringNormalizer::slugify("João da Silva Santos");
// Resultado: "joao-da-silva-santos"
```

---

## 📄 Formatos de Intercâmbio Suportados

O serviço `ThesaurusFileService` permite importar e exportar tesauros nos seguintes formatos:

### 1. Formato VantagePoint (`.the`)
Formato padrão amplamente utilizado no software de inteligência bibliométrica VantagePoint:

```text
**#brasil
100 1 ^brasil$
100 1 ^brazil$
100 1 ^republica federativa do brasil$
100 1 ^bra$

**#estados unidos
100 1 ^estados unidos da america$
100 1 ^united states$
100 1 ^usa$
```

- Cada bloco começa com `**#nome_preferido`.
- Cada variante é representada na linha `100 1 ^variante$`.

---

### 2. Formato CSV (`.csv`)
Tabela simples delimitada por ponto e vírgula com suporte a variantes agrupadas por barra vertical `|`:

```csv
preferred_name;variant_name
Brasil;Brazil|Republica Federativa do Brasil|BRA
Estados Unidos;United States|USA|EUA
Universidade Federal de São Carlos;UFSCar|Univ Fed Sao Carlos
```

---

### 3. Formato JSON (`.json`)
Estrutura em árvore JSON de alta interoperabilidade:

```json
[
  {
    "header": "Universidade Federal de São Carlos",
    "variations": [
      "UFSCar",
      "Univ Fed Sao Carlos",
      "Federal University of Sao Carlos"
    ]
  },
  {
    "header": "Brasil",
    "variations": [
      "Brazil",
      "BRA"
    ]
  }
]
```

---

### 4. Formato XML (`.xml`)
Estrutura hierárquica XML para intercâmbio de tesauros:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<thesaurus>
  <term>
    <header>Universidade Federal de São Carlos</header>
    <variations>
      <variation>UFSCar</variation>
      <variation>Univ Fed Sao Carlos</variation>
    </variations>
  </term>
</thesaurus>
```

---

## 🔀 Mecanismo de Fusão de Entidades (`EntityMergeService`)

Quando o administrador identifica registros duplicados na base (por exemplo, dois registros para a mesma universidade cadastrados com nomes ligeiramente diferentes), o sistema executa uma **fusão atômica** via `EntityMergeService`:

1. O usuário seleciona 2 ou mais registros na tabela e clica em **"Mesclar Selecionados"**.
2. Uma caixa de diálogo modal HTML (`<sl-dialog>`) solicita a escolha de qual será o **Registro Principal (Master)**.
3. O serviço `EntityMergeService`:
   - Adiciona os nomes dos registros secundários como **novas variantes** do registro principal (evitando perdas de sinônimos).
   - Transfere todas as variações existentes dos registros secundários para o registro principal.
   - Remove duplicatas de variantes idênticas.
   - Deleta os registros secundários do banco de dados com segurança transacional.

---

## Tesauro de revistas

### O que é

A mesma revista é escrita de muitos jeitos: "Paidéia (Ribeirão Preto)", "Paideia", "Paidéia. Ribeirão Preto". O tesauro liga cada grafia conhecida a uma revista, sem alterar o que veio do Lattes.

### Tabelas

| Tabela | Para que serve |
|---|---|
| `qualis_journals` | o cadastro. Revistas iguais ficam em linhas ligadas por `canonical_journal_id`, a família. Para contar por revista, use `COALESCE(canonical_journal_id, id)` |
| `journal_name_variants` | as grafias. `normalized_name` é único: um nome pertence a uma só família |
| `journal_name_candidates` | a fila de nomes sem vínculo seguro, em `/admin/journal-names` |

### De onde vêm as grafias (`variation_type`)

| Tipo | Origem | Como entra |
|---|---|---|
| `alternative` | listas de bases, arquivos de tesauro e fusão de revistas | importação |
| `external` | títulos de Scopus, Web of Science, DOAJ, Latindex, PubMed, SciELO e OpenAlex (inclusive títulos alternativos), confirmados pelo ISSN | `app:journals:names:learn-external`, passo `learn-external` do pipeline |
| `lattes` | grafias dos artigos do Lattes confirmadas pelo ISSN em pelo menos dois artigos | `app:journals:names:learn`, passo `learn` |
| `curated` | aceitas na fila de nomes ou por `overrides/names.jsonl` | tela ou passo `overrides-names` |
| `manual` | digitadas no formulário da revista | tela |

**Regra do `external`.** O registro da lista precisa apontar pelo ISSN para uma única família. O nome entra se ainda não for título nem variante, se não tiver sido rejeitado na fila, se só aparecer em uma família e se for coerente com um título dela: nota de pelo menos 0,6, ou contido nele com uma palavra rara, ou confirmado por duas listas. O resto fica de fora, sem ir para a fila.

### Como um nome vira revista (`JournalResolverService`)

1. ISSN, nas quatro colunas normalizadas.
2. Nome exato: título normalizado, título sem parênteses ou variante ativa.
3. Nome sem parênteses.
4. Chave compacta, sem conectivos, com pelo menos 2 palavras.

Um nome que casa com mais de uma família é ambíguo: não resolve sozinho e vai para a fila. Só artigos são resolvidos: capítulo, texto em jornal e evento também usam `journal_name`, mas ali é outra coisa, como o título do livro. `qualis_journals.normalized_title` precisa estar preenchido: `app:journals:normalize-titles` cuida disso.

### A trava (`JournalVariantGuard`)

Todo criador de grafia passa por ela. Ela recusa um nome que já é título ou variante de outra família e, dentro da mesma família, não o grava de novo. Ela também recusa texto que é código, como HTML, CSS ou pilha de erro, depois que uma página de erro entrou no tesauro linha a linha. Conferida contra os 155.696 nomes do cadastro: nenhum recusado por engano.

### Onde o tesauro é aplicado

| Onde | Como |
|---|---|
| Indexação dos artigos (`app:index:journals` e importação de currículo) | resolve o artigo e grava `qualis_journal_id`, `qualis` e `indexed_databases` |
| `/periodicos` | conta por família e mostra `display_title` |
| `/busca` | além do nome escrito, acha artigos da família cujo título, nome exibido ou variante contém o termo |
| Pesquisa temática, "Top revistas do tema" | agrupa por família, só artigos, com o melhor estrato |
| Página do professor | mostra o nome como o docente escreveu; o nome usual aparece no tooltip e entra no filtro |
| Selo de Qualis e bases | usa o que está gravado no item, sem adivinhar pelo texto na hora de exibir |
| `/admin/journal-names` | busca a revista por qualquer nome, em vez de digitar o ID |

### Como corrigir

- **Nome com vínculo errado ou sem vínculo:** `/admin/journal-names`, ou uma linha em `docs/banco/derived/overrides/names.jsonl`.
- **Grafia errada:** no formulário da revista, em `/admin/journals`.
- **Revistas iguais em linhas separadas:** agrupamento manual no formulário, que vale como ponte (`canonical_method = 'manual'`).

---

## Tesauro de categorias de revistas

### O que é

Cada base dá às revistas categorias na sua própria linguagem: códigos ASJC (Scopus e OpenAlex), categorias do Web of Science, assuntos do DOAJ, temas do Latindex, áreas e descritores do SciELO. O Qualis e o Lattes trazem a área em texto. O tesauro de categorias é a tabela que diz **qual área CAPES cada um desses rótulos significa**. A classificação das revistas (`app:journals:classify`) usa essa tabela, e uma correção feita no admin vale sobre o que veio dos arquivos de configuração.

### Tabela `capes_area_variants`

Entidade `App\Entity\CapesAreaVariant`, repositório `App\Repository\CapesAreaVariantRepository`. Uma linha por rótulo e área (um rótulo que vale para duas áreas tem duas linhas):

| Coluna | Conteúdo |
|---|---|
| `area_id` | Área CAPES (FK para `capes_areas`, `ON DELETE CASCADE`); nulo nas linhas `pending` e `ignored` |
| `vocabulary` | `capes`, `cnpq`, `asjc`, `wos`, `doaj`, `latindex`, `scielo_area`, `scielo_desc` ou `livre` |
| `variation_name` | O rótulo como veio da fonte |
| `normalized_name` | `StringNormalizer::normalizeString($rotulo, true)`, a mesma forma de `journal_name_variants`; é por ela que o rótulo é encontrado |
| `origin` | `seed` (semeada pelo comando, a partir dos arquivos) ou `manual` (correção do admin) |
| `status` | `active` (tem área), `ignored` (decisão de que o rótulo não diz nada sobre o assunto) ou `pending` (usado e ainda sem decisão) |
| `occurrences` | Quantas revistas usam o rótulo (no `cnpq`, quantos registros de área dos currículos); calculado pelo comando |

Índices: único em (`vocabulary`, `normalized_name`, `area_id`); `normalized_name`; (`status`, `occurrences`). A migration cria a tabela vazia: quem a semeia é o comando.

### Vocabulários e prioridade

O mesmo rótulo pode existir em mais de um vocabulário (`Lingüística` é do CNPq e do Latindex). A busca (`JournalAreaThesaurus::lookup()`) devolve os slugs de **um só** vocabulário, o de maior prioridade, sem misturar com os outros:

1. linhas `manual` (a correção do admin, de qualquer vocabulário);
2. `capes`: o nome e o rótulo canônico de cada área da taxonomia (`config/journal_areas/taxonomy.json`); o slug, o nome e o rótulo canônico são sempre reconhecidos, mesmo com a tabela vazia;
3. `cnpq`, 4. `wos`, 5. `latindex`, 6. `doaj`, 7. `scielo_desc`, 8. `scielo_area`, 9. `asjc`, 10. `livre`.

Só as linhas `active` e `ignored` entram na busca. Uma linha `ignored` responde "sem área" (lista vazia); um rótulo que não está na tabela responde `null`. Rótulo só com dígitos é código ASJC e não entra na busca por texto. Com `trustedOnly`, a busca considera só `capes`, `cnpq` e as linhas `manual`: é o que vale como texto de `area` original na evidência `legacy`.

### De onde veio cada semente

- `capes`: a taxonomia de 9 grandes áreas e 50 áreas de avaliação em `config/journal_areas/taxonomy.json` (a mesma da tabela `capes_areas`).
- `cnpq`: `config/journal_areas/crosswalk_cnpq.json`, as áreas do conhecimento do CNPq que o Lattes grava em `knowledge_areas`, ligadas às áreas de avaliação da CAPES pela Tabela de Áreas de Avaliação da CAPES (https://www.periodicos.capes.gov.br/pagina/tabela-areas-avaliacao.html, consultada em 2026-10-31; a página não traz data). `Medicina` aponta para as três áreas de Medicina; `Lingüística` é a grafia antiga que o Lattes ainda usa; `Outros` é decisão explícita de "sem área".
- `asjc`, `wos`, `doaj`, `latindex`, `scielo_area` e `scielo_desc`: os arquivos `config/journal_areas/crosswalk_<fonte>.json`, cada um com a sua origem e as suas dúvidas nas notas de `_meta`. Um valor `null` no arquivo vira uma linha `ignored`.
- `livre`: não tem arquivo. São os sinônimos digitados no admin (`manual`) e os trechos de texto livre de `qualis_journals.area_raw` que ninguém reconhece (`pending`).

### Texto composto

`JournalAreaThesaurus::resolve($texto)` desmonta um texto livre que pode juntar vários rótulos, na ordem: o texto inteiro; senão as partes separadas por `|` e `;`; e, de cada parte, ela inteira ou, senão, as subpartes separadas por `,`. A ordem importa porque `Mathematics, Applied` é um rótulo do WoS inteiro, com vírgula:

- `Mathematics, Applied | Mathematics` resolve para Matemática / Probabilidade e Estatística (dois rótulos do WoS);
- `Medicina, Salud pública` não é um rótulo, então vira `Medicina` (CNPq: as três áreas de Medicina) e `Salud pública` (Latindex: Saúde Coletiva);
- o que ninguém reconhece volta em `unknown`, com o trecho como está no texto.

`JournalAreaRepository::resolveSlugs()` (coluna `areas_capes` do CSV de periódicos e arquivos de overrides) consulta o tesauro, só nas linhas `active`, para o trecho que não é slug, nome nem rótulo canônico.

### Corrigir pelo admin

A tela **Tesauro de categorias** (`/admin/journal-categories`, no menu Tesauros & Autoridades) lista os rótulos em três abas (Ativos, Pendentes e Ignorados), com busca e filtros por vocabulário e por área, e exporta o tesauro inteiro em CSV (`;`, UTF-8 com BOM). Toda ação é POST com CSRF:

- **Definir área**: o rótulo vira uma linha `manual` e `active` com a área escolhida, no lugar das outras linhas dele (as da semente e as pendentes);
- **Ignorar**: o rótulo vira `manual` e `ignored`, sem área;
- **Novo sinônimo**: rótulo e área, vocabulário `livre`, `manual`. Um rótulo pode ter vários sinônimos, um por área;
- **Excluir correção**: só linhas `manual`. A próxima execução do comando recria a semente do rótulo, se houver.

As correções valem sobre a semente: o comando nunca apaga nem recria uma linha `manual`, e o rótulo que tem qualquer linha `manual` num vocabulário não é tocado nesse vocabulário. As correções dos vocabulários que viram evidência (`asjc`, `wos`, `doaj`, `latindex`, `scielo_area`, `scielo_desc` e `cnpq`) também entram no `AreaCrosswalk`, que o classificador usa. Um rótulo corrigido vale para toda grafia do arquivo da mesma fonte que tenha a mesma forma do tesauro (`HISTÓRIA` e `HISTORIA`).

### Comando e pipeline

```bash
php bin/console app:journals:areas:thesaurus            # semeia, conta os usos e abre os pendentes
php bin/console app:journals:areas:thesaurus --dry-run  # mostra o relatório sem gravar
```

O comando (`JournalAreaThesaurus::sync()`) é idempotente: rodar de novo não muda nada. Em uma transação e em lotes, ele:

1. semeia o nome e o rótulo canônico de cada área (`capes`) e cada entrada dos arquivos de crosswalk, uma linha por slug, e apaga as linhas `seed` que saíram do arquivo;
2. recalcula `occurrences`: revistas distintas que usam o rótulo na evidência (`openalex` e `scopus` contam como `asjc`, `scielo_wos` como `wos`; `ai`, `title_rule` e `legacy` ficam fora), registros de `knowledge_areas` (`area` e `major_area`) no `cnpq`, e revistas cujo `area_raw` cita o rótulo no `capes` e no `livre`;
3. abre uma linha `pending` para o rótulo usado e não reconhecido (partes de `area_raw` que `resolve()` não reconhece, áreas de `knowledge_areas`, rótulos de evidência sem mapa no crosswalk; trechos com menos de 3 letras ou mais de 255 caracteres ficam de fora) e apaga a que passou a ser reconhecida ou que ninguém usa mais.

No pipeline (`app:journals:pipeline`) ele roda duas vezes: `area-thesaurus-first`, antes de `evidence-load`, para a evidência `legacy` já usar o tesauro; e `area-thesaurus`, depois de `audit-after`, para contar os usos e abrir os pendentes com as listas novas.

### Evidência `legacy`

A evidência `legacy` (o texto de `area` original que é o nome de uma área CAPES) aceita também o que o tesauro confiável dá a exatamente uma área, com a regra de grafia de `JournalAreaThesaurus::legacySlug()`: o texto está todo em maiúsculas (como o Qualis grafa) ou é, sem diferença de caixa, uma grafia que o `capes`, o `cnpq` ou uma correção manual conhecem. Assim `Lingüística` e os sinônimos manuais entram, enquanto `Sociología` (Latindex, que já chega pela própria fonte) e `Social Sciences` (lixo da importação antiga) não. A evidência `legacy` é gravada no passo `evidence-load`, que vem antes do `area-thesaurus` no pipeline: ela enxerga as sementes e as correções da execução anterior.

### Limite: a classificação só muda ao rodar de novo

Corrigir um rótulo, no admin ou pelo comando, **não reclassifica nenhuma revista**. A área de cada revista só muda na próxima execução de `app:journals:classify` ou do pipeline; a evidência `legacy`, na próxima carga (`app:journals:evidence:load --legacy`). A tela avisa disso a cada correção.
