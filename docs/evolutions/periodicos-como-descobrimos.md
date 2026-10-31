# Como descobrimos o que sabemos sobre as revistas

Este documento conta, na ordem em que aconteceu, como cada fato sobre as revistas foi descoberto: de onde veio o dado, o que foi medido, qual era a causa e o que foi feito. Serve para quem precisar conferir um número, repetir o trabalho ou discordar de uma decisão.

O desenho técnico completo está em [periodicos-por-base.md](periodicos-por-base.md). O registro de cada nome da fila está em [periodicos-fila-de-nomes-decisoes.csv](periodicos-fila-de-nomes-decisoes.csv). Os números abaixo são de produção em 31/10/2026, depois do commit `b108c09`, salvo quando o texto diz outra coisa.

## 1. Situação de hoje

| Medida | Valor |
|---|---|
| Linhas no cadastro (`qualis_journals`) | 84.047 |
| Famílias (linhas da mesma revista agrupadas) | 76.988 |
| Linhas com área preenchida | 83.819 (99,7%) |
| Linhas com alguma evidência externa de área | 72.425 (86,2%) |
| Famílias com artigo dos docentes (as que aparecem em `/periodicos`) | 2.436, todas classificadas |
| Artigos no Lattes | 13.487 |
| Artigos ligados a uma revista | 12.077 (89,5%) |

A confiança da área principal nas 2.436 famílias visíveis é alta em 1.754 (72,0%), média em 475 (19,5%), baixa em 193 (7,9%) e manual em 14 (0,6%).

| Base | Famílias | Artigos |
|---|---:|---:|
| Scopus | 739 | 4.054 |
| Web of Science | 576 | 2.419 |
| SciELO | 197 | 2.402 |
| Latindex | 332 | 1.797 |
| PubMed | 400 | 1.267 |

## 2. De onde vieram os dados

O cadastro nasceu com as listas Qualis e as importações do Bibliomap. Para saber a área de cada revista, cruzamos o ISSN com listas externas. Título só foi usado para linhas sem ISSN.

| Fonte | Como foi obtida | Tamanho | O que dá |
|---|---|---:|---|
| Scopus | `docs/banco/lists-to-import/scopus/ext_list_Jul_2026.xlsx`, lista oficial de julho de 2026 | 48.888 registros | códigos ASJC |
| Web of Science | `docs/banco/lists-to-import/webofscience/*.csv`, 20 arquivos usados | 25.442 registros | categorias WoS |
| DOAJ | `docs/banco/lists-to-import/DOAJ/doaj_journalcsv_20260726_2320_utf8.csv` | 23.262 registros | assuntos LCC |
| Latindex | `Indice_indice.csv` | 4.049 registros | subtemas em espanhol |
| PubMed | `J_Medline.txt` | 38.003 registros | só confirma a base e a grande área de saúde |
| OpenAlex | snapshot público, CC0, sem chave (`https://openalex.s3.amazonaws.com/data/jsonl/sources/manifest.json`, edição de 14/10/2026, 136 arquivos) | 168.995 de 268.629 registros usados | subcampos, títulos alternativos |
| SciELO | API ArticleMeta (`https://articlemeta.scielo.org/api/v1/journal/`), coleção `scl` | 425 revistas | área, categorias WoS, descritores em português |
| CAPES | taxonomia de [áreas de avaliação](https://www.gov.br/capes/pt-br/acesso-a-informacao/acoes-e-programas/avaliacao/sobre-a-avaliacao/areas-avaliacao/sobre-as-areas-de-avaliacao/sobre-as-areas-de-avaliacao) e [documento da Medicina II](https://www.gov.br/capes/pt-br/centrais-de-conteudo/16_MED_2_docarea_2016.pdf) | 9 grandes áreas, 50 áreas | o vocabulário final |

O arquivo `docs/banco/derived/journal_evidence.jsonl.gz` reúne tudo em um formato só. Ele é gerado no Mac, porque as listas estão em uma pasta fora do Git, e enviado ao servidor na publicação.

## 3. O que encontramos, um problema de cada vez

### 3.1 O campo de área era uma mistura

**Sintoma.** O campo "Área de Avaliação / Conhecimento" estava preenchido em 36.987 linhas (44%), com 4.173 valores diferentes.

**Como medimos.** `SELECT area, COUNT(*) FROM qualis_journals GROUP BY area`. Apareceram categorias WoS em inglês, subtemas do Latindex em espanhol e cerca de 80 áreas CAPES em maiúsculas.

**O valor estranho.** "Social Sciences" aparecia em 14.285 linhas. Abrindo o Excel do Scopus, vimos que `Top level: Social Sciences` é uma coluna que só marca sim ou não. O importador a confundia com a área e ignorava a coluna real, `All Science Journal Classification Codes (ASJC)`. Só as revistas com a marca recebiam algo, e sempre o mesmo texto.

**O que foi feito.** O importador passou a ler a coluna certa. O texto antigo foi copiado para `area_raw` e não vale como evidência. O campo `area` passou a guardar só o rótulo canônico.

### 3.2 Só uma pequena parte do cadastro aparece na página

Das 83.528 linhas iniciais, só 2.405 tinham artigo. Por isso a primeira medida de qualidade olhou essas 2.405 e não o cadastro inteiro: é o que o público vê.

### 3.3 A mesma revista estava em várias linhas

**Como apareceu.** O SciELO tinha poucos artigos para o esperado. Investigando a Paidéia (Ribeirão Preto), achamos duas linhas: a 11322 com 46 artigos e base Scopus, e a 65044 sem ISSN, com 0 artigos e base SciELO. Contando por linha, a Paidéia sumia do SciELO.

**Causa.** Linhas separadas para versão impressa e online, importação do SciELO só por título, lista JCR sem ISSN.

**Como medimos.** Unimos as linhas por ISSN e pelos pares impresso/online declarados nas fontes, e depois unimos linhas sem ISSN cujo título exato apontasse para um único grupo. A contagem do SciELO foi de 1.101 para 2.277 artigos.

**O que foi feito.** Criamos famílias com `canonical_journal_id`, sem apagar nem mesclar nenhuma linha.

### 3.4 Título errado com ISSN certo

Algumas uniões pareciam erro e estavam certas. A linha 23639, "REVISTA BRASILEIRA DE HISTÓRIA & CIÊNCIAS SOCIAIS", tem o ISSN 0102-6909, que é da *Revista Brasileira de Ciências Sociais*, e os 19 artigos ligados a ela dizem no Lattes "Revista Brasileira de Ciências Sociais". A linha 23600, "ILHA - REVISTA DE ANTROPOLOGIA", tem os ISSNs da *Revista de Antropologia* da USP, e 28 dos seus 33 artigos são dessa revista.

**Conclusão.** O título do cadastro não é confiável para exibir, e o ISSN é mais confiável. O nome exibido passou a vir das fontes externas pelo ISSN ou do nome mais frequente no Lattes (`display_title`).

### 3.5 ISSNs colados

222 linhas tinham dois ISSNs no mesmo campo, como `1806-9053, 0102-6909`, que na coluna de busca virava `1806905301026909`. Nenhum dos dois era encontrado. A correção passou para `Issn::extractAll`, e `app:journals:normalize-titles` agora separa os ISSNs nas colunas de busca sem mudar o texto original. Em produção foram 253 linhas corrigidas.

### 3.6 Um erro que só existia no Mac

**Sintoma.** No banco local, "Turismo: Visão e Ação" tinha 18 artigos, e 16 ou mais eram de outras revistas.

**Causa.** `JournalResolverService::normalizeString` usava `iconv`. No PHP do macOS, "história da educação" virava `?o`, e títulos diferentes colidiam na mesma chave. 991 linhas compartilhavam chave.

**Como conferimos produção.** Em produção a mesma revista tinha 1 artigo. O erro nunca esteve lá, porque o Linux tem outro `iconv`. Sem essa conferência, teríamos relatado um erro inexistente.

**O que foi feito.** A normalização passou a usar `Transliterator`, que dá o mesmo resultado nos dois sistemas.

### 3.7 Artigos que perdiam Qualis

No primeiro ensaio, 1.489 artigos perderam o estrato. A causa: as linhas novas, criadas para ligar artigos a revistas que só existiam nas listas externas, não têm Qualis. O artigo caía nelas. `JournalResolverService::withFamilyQualis` passou a ceder o artigo à linha da mesma família que tem estrato.

### 3.8 Indicadores por base que caíam

`/indicadores` conta por linha, então artigos ligados a uma linha sem a base deixavam de contar. `JournalFamilyBuilder::propagateBaseLinks` passou a estender os vínculos de bases à família inteira, e o passo `families-final` roda depois de o tesauro mudar.

### 3.9 Famílias falsas

Um ISSN errado no cadastro juntava revistas diferentes. Por isso uma linha do cadastro não une nada por conta própria: ela só olha em que grupos de ISSN das listas externas caem os seus ISSNs (`JournalFamilyBuilder`).

- Um grupo só: a linha entra nele, mesmo com título diferente, porque o caso comum é ISSN certo e título errado (3.4).
- Dois grupos de títulos parecidos e sem países que se excluam: é o par impresso/online que nenhuma lista declarou, e os grupos se unem.
- Dois grupos incompatíveis: é uma linha em conflito. Nada se une por ela, e ela entra só no grupo de título mais parecido, se a nota passar de 0,7 sem empate; senão, fica isolada.
- Registros externos com ISSNs demais, ou com 3 ISSNs que duas ou mais fontes contradizem, não unem nada.

A lista das linhas em conflito fica em `docs/banco/derived/review/conflict_rows.txt`.

### 3.10 Medicina I, II e III

Na revisão por amostragem, revistas de psiquiatria apareciam em Medicina I. O [documento da área](https://www.gov.br/capes/pt-br/centrais-de-conteudo/16_MED_2_docarea_2016.pdf) coloca psiquiatria, neurologia, pediatria, infectologia, patologia e radiologia em Medicina II. 30 rótulos dos crosswalks mudaram.

### 3.11 As 411 grafias-lixo

**Sintoma.** O periódico 22013, *Journal of Environmental Protection and Ecology*, tinha 411 variantes, e o segundo maior tinha 4.

**Como conferimos.** A amostra trouxe linhas como `HTTP 500 <small>Internal Server Error</small>`, `.sf-reset .linked ul,` e `#13 /home/runcloud/webapps/app-bibliomap/vendor/...`. Todas têm o mesmo horário, 21/08/2026 15:46:29. Procuramos os mesmos padrões em todas as outras variantes e não achamos nenhuma.

**Causa.** Uma resposta de erro do Bibliomap foi gravada como se fosse a lista de grafias daquela revista.

### 3.12 Títulos das listas que não serviam para reconhecer nome

Os títulos da Scopus, WoS, DOAJ, Latindex, PubMed e OpenAlex estavam sendo usados só para classificar. Uma medição mostrou 48.405 nomes que não estavam no tesauro.

**Primeira tentativa, com dois tropeços que os testes pegaram:**

1. A execução parou em `BRITISH HOMOEOPATHIC JOURNAL`. Para o MySQL, "HOMŒOPATHIC" e "HOMOEOPATHIC" são iguais, e o PHP os via como diferentes. Agora cada nome é gravado na hora, dentro de uma transação, para a trava enxergar a colação do banco.
2. Um artigo perdeu o vínculo. "PSYCHE" era o título sem parênteses de `Psyche (Pasadena)`, e virar variante de `PSYCHE (STUTTGART)` tornou o nome ambíguo. O comando agora usa as mesmas chaves do resolver.

**Resultado no banco local.** 44.747 nomes candidatos: 22.135 viraram variantes `external`, 751 ficaram de fora por estar em mais de uma família e 21.817 por não terem coerência com o título. Nos 13.469 artigos locais, nenhum vínculo foi perdido, trocado ou criado. O ganho é para currículos futuros e para a busca.

## 4. Como cada revista ganhou uma área

**Evidências.** Cada rótulo de cada lista vira uma ou duas áreas CAPES por um crosswalk (`config/journal_areas/crosswalk_*.json`). As regras estão no apêndice C de [periodicos-por-base.md](periodicos-por-base.md).

**Peso por fonte** (`JournalAreaScorer`): WoS, Scopus e categorias WoS do SciELO valem 1,0; descritores do SciELO 0,9; DOAJ, Latindex e área antiga 0,8; área do SciELO e regras de título 0,5. OpenAlex depende de quantos artigos a revista tem, porque revistas muito pequenas geravam ruído (a *INCID*, de documentação e ciência da informação, aparecia como "Information Systems"). A IA vale 0,9 vezes a confiança dela.

**Decisão.** A área com pelo menos 34% da soma vira a principal. Quando nenhuma chega lá e várias têm pelo menos 15%, a revista fica como Interdisciplinar. Confiança alta exige 50% e soma de pesos a partir de 1,0.

**IA.** Entrou depois das regras, em duas passadas, rodando fora do servidor, em lotes (`app:journals:ai:export`, `app:journals:ai:import`):

1. Famílias visíveis com confiança baixa ou sem área, com o título, os rótulos das listas e títulos de artigos como contexto (pasta `docs/banco/derived/ai`).
2. Famílias sem nenhuma área, visíveis ou não, só pelo título (pasta `docs/banco/derived/ai_titles`). É o que levou o campo a quase todo o cadastro, e é a parte menos confiável: uma revista classificada só pelo título nunca recebe confiança alta.

O resultado é evidência como as outras e pode ser refeito. Nada em produção chama IA.

**Conferência.** Sorteamos 200 revistas (`docs/banco/derived/review/sample.jsonl`) e um agente de IA que não participou da classificação avaliou cada uma: 93% de acerto na grande área e 94% na área. Não foi uma revisão humana. Os 14 erros viraram correção manual em `docs/banco/derived/overrides/areas.jsonl`, e essas revistas ficam travadas contra reclassificação.

## 5. Como a fila de nomes foi decidida

A fila tinha 1.145 nomes do Lattes, ligados a 1.788 artigos, que o sistema não conseguia associar com segurança a uma revista. A nota de semelhança é a média de palavras em comum (Jaccard) e de caracteres em comum (`SequenceMatcher`).

| Grupo | Regra | Decisão | Nomes |
|---|---|---|---:|
| 1 | DOI ou URL digitado no lugar do nome | rejeitado | 30 |
| 2 | ISSN aponta para uma revista e título parecido (nota de 0,6 ou mais) | aceito | 177 |
| 3 | ISSN liga o artigo, mas o nome é de outra coisa | rejeitado, e o artigo segue ligado | 135 |
| 4 | Já revisado por IA antes | rejeitado | 162 |
| 5 | Sem revista parecida (nota abaixo de 0,6) e 1 artigo | rejeitado | 436 |
| 6 | Revisão por IA, com candidato | 45 aceitos, 125 rejeitados | 170 |
| 7 | Ambíguo, mais de uma família possível | 5 aceitos, 12 rejeitados, 15 continuam | 32 |
| 8 | ISSN colado no cadastro | resolvido pela correção de 3.5 | 3 |

Dois nomes foram corrigidos à mão depois de conferir: "Cadernos de Pesquisa (Fundação Carlos Chagas. Impresso)" para o ISSN 1980-5314 e "Materials Research (São Carlos" para o ISSN 1516-1439.

**Rejeitar não apaga o nome nem o artigo.** O dado do Lattes fica como está. Rejeitar quer dizer "este nome não é variante da revista sugerida". A coluna `como_foi_decidido` do CSV e a tela `/admin/journal-names` mostram o motivo de cada um.

**Resultado em produção.** 248 aceitos, 900 rejeitados, 17 ambíguos, 1 pendente. Artigos ligados passaram de 12.004 para 12.077.

## 6. Limites que continuam valendo

- A área de uma revista é de uma lista externa ou de um modelo, não um julgamento da CAPES sobre aquela revista. A confiança é baixa em 193 famílias visíveis.
- O nome de uma revista grafado de um jeito desconhecido e sem ISSN fica sem vínculo: 1.410 artigos hoje.
- `/indicadores` conta artigos por docente em algumas figuras. Um artigo com dois docentes conta duas vezes nelas, e uma vez em `/periodicos`. Os totais não precisam bater.
- Os títulos dos currículos estão como cada docente escreveu. Nenhum dado do Lattes foi alterado.

## 7. Como repetir

Listas novas, snapshot novo do OpenAlex ou SciELO novo:

```bash
php bin/console app:journals:evidence:fetch-openalex
php bin/console app:journals:evidence:fetch-scielo
php bin/console app:journals:evidence:build
bin/publish-journals.sh --data-only
```

Depois de um código novo, `bin/publish-journals.sh`. Só o relatório: `php bin/console app:journals:report`. O passo a passo da publicação está em [DEPLOY_E_PRODUCAO.md](../DEPLOY_E_PRODUCAO.md).
