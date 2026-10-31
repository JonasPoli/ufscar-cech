# Importação de currículos Lattes

Como os currículos chegaram ao portal, como importar mais e onde cada dado do XML vai parar.

## Resumo

- O portal importa o **XML** do Lattes. Existe um comando: `app:import:lattes`.
- Para importar em produção, use **`bin/import-curriculos.sh`**. Ele faz o backup, a importação e tudo o que depende dela.
- Importar de novo o mesmo docente **substitui** a produção dele pelo que está no XML. O ID Lattes é a chave.
- O XML não traz o departamento. Para docente novo, informe `--dept`.

```bash
bin/import-curriculos.sh --check pasta-com-xmls/                               # só confere, não altera nada
bin/import-curriculos.sh --dept="ED|Departamento de Educação" pasta-com-xmls/  # importa em produção
```

## Como os currículos atuais chegaram aqui

| Quantos | Origem | Evidência |
|---:|---|---|
| 414 | XML em `docs/banco/CECH/lattes<ID>.xml`, importados por `app:import:lattes` | `researchers.created_at` de 24/08/2026; os 414 arquivos existem na pasta |
| 1 | Luís Fernando Soares Zuin (3389666977978800), sem XML na pasta | criado em 25/08/2026, 00:13 |
| 1 | Cristina Helena Bruno (7690269357195395), só em produção | criada em 23/09/2026, 22:44; o log do servidor mostra `POST /admin/curriculum/new` |

**O que os arquivos dizem.** Todos têm `SISTEMA-ORIGEM-XML="LATTES_OFFLINE"` e estão na pasta com data de 23/08/2026, 09:32 a 09:33. A `DATA-ATUALIZACAO` deles vai de 2000 a agosto de 2026: cada um traz a última atualização que o docente fez, por isso há currículos bem antigos.

**O que não sabemos.**
- Como os XML foram obtidos. Nada no projeto registra isso.
- Se o lote foi importado no Mac e levado por dump ao servidor. Os `created_at` iguais nos dois bancos e a pasta existir só no Mac indicam que sim, mas é uma inferência.
- A origem de cada currículo, porque o banco não guarda esse dado.

**Reimportação de 31/10/2026.** Os 414 XML da pasta foram importados de novo em produção com o parser corrigido, por `bin/import-curriculos.sh docs/banco/CECH`. Levou cerca de 1h45, com o site no ar; backup em `var/backups/antes_curriculos_20261031-062021.sql.zip`. Artigos seguiram em 13.487 e ligados a uma revista foram de 12.077 para 12.079. Os 2 docentes sem XML na pasta não mudaram.

| Medida | Antes | Depois |
|---|---:|---:|
| Produções | 76.837 | 121.073 |
| Demais produções técnicas | 0 | 42.349 |
| Bancas | 41.842 | 46.937 |
| Projetos com coordenador marcado | 36, no banco local | 4.983 de 6.825 |
| Formações com orientador | 265, no banco local | 1.090 |
| Formações com agência | 1, no banco local | 928 |
| Autores | 157.309 | 242.658 |

Os XML enviados ficam em `docs/banco/CECH/lote-*` no servidor e podem ser apagados depois de conferir.

**O JSON não foi usado.** `docs/banco/items_cech.json`, de 270 MB, só aparece como referência em `docs/somos.md`. Nenhum código o lê.

## Como importar mais

### 1. Conseguir os XML

Cada XML precisa ter os 16 dígitos do ID Lattes em `NUMERO-IDENTIFICADOR`. Como 10 dos 414 vieram sem esse atributo, é mais seguro manter o nome `lattes<ID>.xml`: o importador usa o nome quando o atributo falta.

- **Um a um:** o próprio docente exporta o XML do currículo dele no Lattes e envia.
- **Em lote:** segundo a [UFRGS](https://www.ufrgs.br/saberviver/plataforma-lattes-o-que-e-e-como-participar/), o CNPq tem uma modalidade de "extração de currículos e grupos da instituição", disponível para instituições de pesquisa e inovação do Brasil, que entrega XML. Ela cobre os currículos da própria instituição. Confira as regras atuais com o CNPq.

Evite baixar em massa pela página pública. Ela tem CAPTCHA, e `scripts/crawl_lattes_stealth.js` existe só para fotos e automatiza o clique no CAPTCHA, o que pode violar os termos da plataforma ou bloquear o acesso.

### 2. Conferir

```bash
bin/import-curriculos.sh --check pasta-com-xmls/
```

Para cada arquivo, o script diz uma destas três coisas e não altera nada:

| Mensagem | O que acontecerá |
|---|---|
| `novo` | cria o docente |
| `atualiza` | substitui a produção do docente |
| `pulado` | não importa, porque o XML é mais antigo que o currículo no banco |

### 3. Importar

```bash
bin/import-curriculos.sh --dept="ED|Departamento de Educação" pasta-com-xmls/
```

Use um `--dept` por lote: todos os arquivos daquele comando ficam no mesmo departamento. Sem `--dept`, o docente novo fica sem departamento e o docente existente mantém o dele. Códigos em uso: PS, LE, AC, CS, IFD, ED, TPP, FI, CI, SO, CA, DCSo.

O script faz, em ordem:

| Etapa | Comando |
|---|---|
| Backup | `app:database:dump`, em `var/backups/antes_curriculos_<data>.sql.zip` |
| Envio dos arquivos | `docs/banco/CECH/lote-<data>/` no servidor |
| Importação | `app:import:lattes --dir=<lote>` |
| Teses do Repositório UFSCar | `app:import:repository`, para repor o link e o resumo das orientações, se o CSV estiver no servidor |
| Revistas, áreas e fila de nomes | `app:journals:pipeline` |
| Temas | `app:index:topics` |
| Cache | `app:cache:clear` |

Para testar no banco local: `LOCAL=1 bin/import-curriculos.sh pasta/`. Cada currículo leva cerca de 15 s no servidor e 3,4 s no Mac; depois disso, o pipeline de revistas, as teses e os temas levam uns 15 minutos no servidor.

### 4. Depois de importar

- Veja os nomes de revista novos em `/admin/journal-names`.
- Se o log mostrar `pulado` e você tiver certeza de que o XML antigo é o certo, rode o comando com `--force` no servidor.

## Outros caminhos

| Caminho | Quando usar | O que ele faz e o que falta |
|---|---|---|
| `/admin/curriculum/new` | um docente, com departamento; aceita XML ou o HTML colado da página do Lattes | importa, normaliza, repõe as teses do Repositório e limpa o cache das páginas. Falta revistas, temas e rankings |
| `/admin/curriculum/import` | vários XML pela tela, com departamento opcional | igual ao anterior |
| Marcador em `/admin/curriculum/new` (`scripts/browser_lattes_crawler.js`, chama `/api/curriculum/import-html` com token) | atualizar um docente a partir da página aberta no navegador | igual ao anterior, e o HTML traz menos dados que o XML |
| `php bin/console app:import:lattes --dir=... [--dept=...] [--force]` | só o passo de importação e normalização | você roda as etapas seguintes à mão |
| `app:import:excel` | complementa departamentos; não cria currículo | não mexe no Qualis |
| `app:import:repository` | teses e dissertações do Repositório UFSCar; ver [REPOSITORY_IMPORT.md](REPOSITORY_IMPORT.md) | |

As telas recusam um XML mais antigo que o currículo no banco, salvo se você marcar "Importar mesmo com data antiga". Um arquivo com erro não impede os seguintes.

Depois de usar uma das telas, rode `app:journals:pipeline` e `app:index:topics`, ou `bin/import-curriculos.sh`. Sem isso, temas e rankings ficam defasados até a próxima publicação.

## Fotos

| Caminho | O que faz |
|---|---|
| Tela do docente em `/admin/curriculum`, envio manual | grava a foto enviada |
| `app:import:photos --dir=... ` ou `--zip=...` | importa um lote, com nome por ID Lattes, slug ou nome |
| `app:photos:generate-webp` | gera as versões WebP de todas as fotos |
| `app:crawl:lattes-photos` | tenta baixar a foto da plataforma Lattes |
| `scripts/lattes_photo_downloader.py` | baixa fotos usando a sessão de navegador que você abriu e resolveu o CAPTCHA |
| `scripts/crawl_lattes_stealth.js` | automatiza o navegador e o clique no CAPTCHA. Evite: pode violar os termos da plataforma ou bloquear o acesso |

## Mapa do XML

Base: os 414 XML reais e `src/Service/Import/LattesXmlParserService.php`. "Arquivos" é em quantos dos 414 o bloco aparece. O caminho sempre começa em `CURRICULO-VITAE`.

### Pesquisador (`researchers`)

| Caminho | Atributo | Coluna |
|---|---|---|
| `CURRICULO-VITAE` | `NUMERO-IDENTIFICADOR` | `id_lattes`, a chave. 10 dos 414 XML vêm sem ele; nesse caso vale o ID informado na tela ou os 16 dígitos do nome do arquivo |
| | `DATA-ATUALIZACAO` (DDMMAAAA) | `last_lattes_update` |
| `DADOS-GERAIS` | `NOME-COMPLETO` | `full_name`, `slug` |
| | `NOME-EM-CITACOES-BIBLIOGRAFICAS` | `citation_names` |
| | `NACIONALIDADE`, `PAIS-DE-NASCIMENTO`, `UF-NASCIMENTO`, `CIDADE-NASCIMENTO` | `nationality`, `birth_country`, `birth_state`, `birth_city` |
| | `ORCID-ID` | `orcid` |
| `DADOS-GERAIS/RESUMO-CV` | `TEXTO-RESUMO-CV-RH` | `abstract_resume` |
| `DADOS-GERAIS/ENDERECO/ENDERECO-PROFISSIONAL` | `NOME-ORGAO`, `NOME-UNIDADE`, `CIDADE`, `UF`, `PAIS`, `CEP`, `TELEFONE` (ou `RAMAL`) | `work_agency`, `unit`, `work_city`, `work_state`, `work_country`, `work_postal_code`, `work_phone` |

`department` e `department_code` não vêm do XML: vêm de `--dept`, da tela do admin ou de `app:import:excel`.

`email` fica vazio de propósito. O parser procura `E-MAIL`, que não existe em nenhum dos 414 arquivos. O e-mail está em `ENDERECO/@ELETRONICO`, mas importá-lo faria o endereço aparecer na página pública do docente, então isso depende de decisão.

### Formação (`educations`)

| Caminho | Arquivos | Atributo | Coluna |
|---|---:|---|---|
| `DADOS-GERAIS/FORMACAO-ACADEMICA-TITULACAO/*` (`GRADUACAO`, `MESTRADO`, `DOUTORADO`, `POS-DOUTORADO`, `LIVRE-DOCENCIA`...) | 414 | nome da tag | `level` |
| | | `NOME-CURSO`, senão `NOME-DO-CURSO`, senão `TITULO-DO-TRABALHO` | `course_name` |
| | | `NOME-INSTITUICAO`, senão `NOME-DA-INSTITUICAO` | `institution_name` |
| | | `ANO-DE-INICIO` | `start_year` |
| | | `ANO-DE-CONCLUSAO`, senão `ANO-DE-OBTENCAO-DO-TITULO` | `end_year` |
| | | `TITULO-DA-DISSERTACAO-TESE`, senão `TITULO-DO-TRABALHO-DE-CONCLUSAO-DE-CURSO`, senão `TITULO-DO-TRABALHO`, senão `TITULO-DA-MONOGRAFIA` | `monograph_title` |
| | | `NOME-COMPLETO-DO-ORIENTADOR`, senão `NOME-DO-ORIENTADOR`, senão `NOME-ORIENTADOR-DOUT` | `advisor_name` |
| | | `NOME-AGENCIA` | `granting_agency` |
| | | `CARGA-HORARIA` | `workload_hours` |
| `DADOS-COMPLEMENTARES/FORMACAO-COMPLEMENTAR/*` | 378 | os mesmos campos de curso, instituição, anos e carga horária; `level` indica o tipo | |

### Atuação, áreas, prêmios e idiomas

| Tabela | Caminho | Arquivos | Atributo → coluna |
|---|---|---:|---|
| `professional_experiences` | `DADOS-GERAIS/ATUACOES-PROFISSIONAIS/ATUACAO-PROFISSIONAL` | 413 | `NOME-INSTITUICAO` → `institution_name`; `CODIGO-INSTITUICAO` → `institution_code` |
| | `.../VINCULOS` | 413 | `OUTRO-ENQUADRAMENTO-FUNCIONAL-INFORMADO`, senão `ENQUADRAMENTO-FUNCIONAL` → `role_name`; `OUTRO-VINCULO-INFORMADO`, senão `TIPO-DE-VINCULO` → `contract_type`; `ANO-INICIO`, `MES-INICIO`, `ANO-FIM`, `MES-FIM` → `start_year`, `start_month`, `end_year`, `end_month`; `is_current` é calculado: `ANO-FIM` vazio, ou `ANO-FIM` e `MES-FIM` a partir do mês atual; `CARGA-HORARIA-SEMANAL` → `workload_hours`; `OUTRAS-INFORMACOES` → `other_info`; `FLAG-DEDICACAO-EXCLUSIVA` → `is_exclusive_dedication` |
| `knowledge_areas` | `DADOS-GERAIS/AREAS-DE-ATUACAO/AREA-DE-ATUACAO` | 404 | `NOME-GRANDE-AREA-DO-CONHECIMENTO` → `major_area`; `NOME-DA-AREA-DO-CONHECIMENTO` → `area`; `NOME-DA-SUB-AREA-DO-CONHECIMENTO` → `sub_area`; `NOME-DA-ESPECIALIDADE` → `specialty` |
| `awards` | `DADOS-GERAIS/PREMIOS-TITULOS/PREMIO-TITULO` | 270 | `NOME-DO-PREMIO-OU-TITULO` → `name`; `NOME-DA-ENTIDADE-PROMOTORA` → `promoter_entity`; `ANO-DA-PREMIACAO` → `year` |
| `language_proficiencies` | `DADOS-GERAIS/IDIOMAS/IDIOMA` | 400 | `DESCRICAO-DO-IDIOMA` ("Inglês"), senão `IDIOMA` (o código, "EN") → `language`; `PROFICIENCIA-DE-LEITURA`, `-ESCRITA`, `-FALA`, `-COMPREENSAO` → `reading`, `writing`, `speaking`, `comprehension` |
| `research_projects` | qualquer `PROJETO-DE-PESQUISA` dentro de `DADOS-GERAIS` | 392 | `NOME-DO-PROJETO` → `name`; `NATUREZA` → `nature`; `SITUACAO` → `status`; `ANO-INICIO`, `ANO-FIM` → `start_year`, `end_year`; `DESCRICAO-DO-PROJETO` → `description`; `FINANCIADORES-DO-PROJETO/FINANCIADOR-DO-PROJETO` `NOME-INSTITUICAO` → `agency_financier`; `EQUIPE-DO-PROJETO/INTEGRANTES-DO-PROJETO` com `NRO-ID-CNPQ` do docente e `FLAG-RESPONSAVEL=SIM` → `is_coordinator` |

### Produção (`production_items`, `item_type`)

Todos os itens gravam ainda `AUTORES` e `PALAVRAS-CHAVE`, descritos depois desta tabela.

| Caminho | Arquivos | `item_type` | Atributos principais → coluna |
|---|---:|---|---|
| `PRODUCAO-BIBLIOGRAFICA/ARTIGOS-PUBLICADOS/ARTIGO-PUBLICADO` | 403 | `ARTIGO` | `DADOS-BASICOS-DO-ARTIGO`: `TITULO-DO-ARTIGO` → `title`; `NATUREZA` → `nature`; `ANO-DO-ARTIGO` → `year`; `DOI`, `IDIOMA` → `doi`, `language`; `PAIS-DE-PUBLICACAO` → `country`; `FLAG-DIVULGACAO-CIENTIFICA` → `is_scientific_dissemination`. `DETALHAMENTO-DO-ARTIGO`: `TITULO-DO-PERIODICO-OU-REVISTA` → `journal_name`; `ISSN` → `issn`; `VOLUME`, `FASCICULO` → `volume`, `issue`; `PAGINA-INICIAL` + `PAGINA-FINAL` → `pages` |
| `.../ARTIGOS-ACEITOS-PARA-PUBLICACAO/ARTIGO-ACEITO-PARA-PUBLICACAO` | 97 | `OUTRA`, `nature` = `ARTIGO-ACEITO-PARA-PUBLICACAO` | como o artigo, sem volume nem páginas; não conta como artigo nem vai ao ranking |
| `.../LIVROS-E-CAPITULOS/LIVROS-PUBLICADOS-OU-ORGANIZADOS/LIVRO-PUBLICADO-OU-ORGANIZADO` | 347 | `LIVRO` | `TITULO-DO-LIVRO`, `TIPO`, `ANO`, `DOI`, `IDIOMA`, `PAIS-DE-PUBLICACAO`; `NOME-DA-EDITORA` → `publisher`; `ISBN` → `isbn` |
| `.../CAPITULOS-DE-LIVROS-PUBLICADOS/CAPITULO-DE-LIVRO-PUBLICADO` | 388 | `CAPITULO` | `TITULO-DO-CAPITULO-DO-LIVRO` → `title`; `TITULO-DO-LIVRO` → `journal_name`; demais como livro; páginas → `pages` |
| `.../TRABALHOS-EM-EVENTOS/TRABALHO-EM-EVENTOS` | 394 | `EVENTO` | `TITULO-DO-TRABALHO`, `NATUREZA`, `ANO-DO-TRABALHO`, `DOI`, `IDIOMA`, `PAIS-DO-EVENTO`; `NOME-DO-EVENTO`, `CIDADE-DO-EVENTO` → `event_name`, `event_city`; `ISBN` |
| `.../TEXTOS-EM-JORNAIS-OU-REVISTAS/TEXTO-EM-JORNAL-OU-REVISTA` | 259 | `TEXTO_JORNAL` | `TITULO-DO-TEXTO`, `NATUREZA`, `ANO-DO-TEXTO`, `DOI`, `IDIOMA`; `TITULO-DO-JORNAL-OU-REVISTA` → `journal_name`; `ISSN` |
| `.../DEMAIS-TIPOS-DE-PRODUCAO-BIBLIOGRAFICA/*` (`OUTRA-PRODUCAO-BIBLIOGRAFICA`, `PREFACIO-POSFACIO`, `TRADUCAO`, `PARTITURA-MUSICAL`) | 340 | `OUTRA` | `TITULO`, `ANO`, `DOI`; a tag vai para `nature` |
| `PRODUCAO-TECNICA/SOFTWARE` | 37 | `SOFTWARE` | `TITULO-DO-SOFTWARE`, `ANO`, `DOI`, `IDIOMA`, `PAIS`; a tag vai para `nature` |
| `PRODUCAO-TECNICA/PATENTE`, `MARCA` | 4 e 4 | `PATENTE`, `MARCA` | `TITULO`, `ANO-DESENVOLVIMENTO`, `PAIS` |
| `PRODUCAO-TECNICA/TRABALHO-TECNICO` | 353 | `TRABALHO_TECNICO` | `TITULO-DO-TRABALHO-TECNICO`, `ANO`, `DOI`, `IDIOMA`, `PAIS` |
| `PRODUCAO-TECNICA/PRODUTO-TECNOLOGICO`, `PROCESSOS-OU-TECNICAS`, `DESENHO-INDUSTRIAL` | 26, 36 e 1 | `TRABALHO_TECNICO` | `TITULO-DO-PRODUTO`, `TITULO-DO-PROCESSO`, `TITULO`; demais iguais; a tag vai para `nature` |
| `PRODUCAO-TECNICA/DEMAIS-TIPOS-DE-PRODUCAO-TECNICA/*` (`APRESENTACAO-DE-TRABALHO`, `ORGANIZACAO-DE-EVENTO`, `PROGRAMA-DE-RADIO-OU-TV`, `CURSO-DE-CURTA-DURACAO-MINISTRADO`, `OUTRA-PRODUCAO-TECNICA`, `RELATORIO-DE-PESQUISA`, `DESENVOLVIMENTO-DE-MATERIAL-DIDATICO-OU-INSTRUCIONAL`, `EDITORACAO`, `MIDIA-SOCIAL-WEBSITE-BLOG`, `CARTA-MAPA-OU-SIMILAR`, `MAQUETE`) | 405 | `OUTRA_TECNICA` | `TITULO`, `ANO`, `DOI`, `IDIOMA`, `PAIS`; a tag vai para `nature` |
| `OUTRA-PRODUCAO/PRODUCAO-ARTISTICA-CULTURAL/*` | 136 | `PRODUCAO_ARTISTICA` | `TITULO`, `NATUREZA`, `ANO` |
| `OUTRA-PRODUCAO/DEMAIS-TRABALHOS` | 137 | `OUTRA` | `TITULO`, `NATUREZA`, `ANO`, `DOI`, `IDIOMA`, `PAIS` |

**Autores (`production_authors`).** `AUTORES` dentro de cada item: `NOME-COMPLETO-DO-AUTOR` → `author_name`; `NOME-PARA-CITACAO` → `citation_name`; `NRO-ID-CNPQ` → `id_lattes`; `ORDEM-DE-AUTORIA` → `author_order`.

**Palavras-chave.** `PALAVRAS-CHAVE`, atributos `PALAVRA-CHAVE-1` a `-6`, vão para `production_items.extra_data.keywords`.

### Orientações, bancas e eventos

| Tabela | Caminho | Arquivos | Atributo → coluna |
|---|---|---:|---|
| `orientations`, `nature` = concluída | `OUTRA-PRODUCAO/ORIENTACOES-CONCLUIDAS/*` | 396 | tag ou `NATUREZA` → `orientation_type`; `TITULO` → `title`; `ANO` → `year`; `NOME-DO-ORIENTADO` → `student_name`; `NOME-DA-INSTITUICAO` → `institution_name`; `NOME-DO-CURSO` → `course_name` |
| `orientations`, `nature` = em andamento | `DADOS-COMPLEMENTARES/ORIENTACOES-EM-ANDAMENTO/*` | 326 | iguais, com `TITULO-DO-TRABALHO` |
| `examination_boards` | `DADOS-COMPLEMENTARES/PARTICIPACAO-EM-BANCA-TRABALHOS-CONCLUSAO/*` e `PARTICIPACAO-EM-BANCA-JULGADORA/*` (concursos, livre-docência, avaliação de cursos e outras) | 401 e 382 | tag → `board_type`; `TITULO` → `title`; `ANO` → `year`; `NOME-DO-CANDIDATO` → `candidate_name`; `NOME-INSTITUICAO` → `institution_name` |
| `event_participations` | `DADOS-COMPLEMENTARES/PARTICIPACAO-EM-EVENTOS-CONGRESSOS/*` | 405 | tag → `event_type`; `TITULO` → `presentation_title`; `TIPO-PARTICIPACAO`, senão `FORMA-PARTICIPACAO` → `participation_type`; `ANO` → `year`; `NOME-DO-EVENTO` → `event_name` |

### O que o XML traz e o portal não guarda

| Bloco | Arquivos | Conteúdo |
|---|---:|---|
| `DADOS-GERAIS/OUTRAS-INFORMACOES-RELEVANTES` | 289 | texto livre |
| `DADOS-GERAIS/LICENCAS` | 33 | licenças funcionais |
| `ATUACAO-PROFISSIONAL/ATIVIDADES-DE-ENSINO`, `-DIRECAO-E-ADMINISTRACAO`, `-PESQUISA-E-DESENVOLVIMENTO`, `-CONSELHO-COMISSAO-E-CONSULTORIA`, `-EXTENSAO-UNIVERSITARIA` | 274 a 383 | disciplinas, cargos e linhas de pesquisa |
| `DADOS-COMPLEMENTARES/INFORMACOES-ADICIONAIS-*` | 414 | dados extras de instituições e cursos |
| `AREAS-DO-CONHECIMENTO`, `SETORES-DE-ATIVIDADE`, `INFORMACOES-ADICIONAIS` dentro de cada produção | variável | classificação por item |
| Títulos e nomes em inglês (`*-INGLES`), `HOME-PAGE`, `MEIO-DE-DIVULGACAO`, `FLAG-RELEVANCIA`, códigos de instituição | variável | |

Para guardar algum deles, é preciso coluna nova por migration e leitura no parser.

## Regras da importação

- **Substituição completa.** O docente é localizado por `id_lattes`. Formação, atuação, áreas, prêmios, idiomas, projetos, bancas, eventos, orientações e produção são apagados e regravados a partir do XML, numa transação: se algo falhar, nada muda para aquele docente.
- **XML mais antigo é pulado.** Se `DATA-ATUALIZACAO` for anterior à data no banco, o arquivo não é importado, salvo com `--force`.
- **Teses do Repositório ficam.** Só as orientações com `source = 'lattes'` são substituídas. As do Repositório UFSCar não vêm no XML e não são tocadas. Ver [REPOSITORY_IMPORT.md](REPOSITORY_IMPORT.md).
- **Falha em um arquivo não para o lote.** O comando segue e lista os erros no fim. O `bin/import-curriculos.sh` conclui as etapas seguintes, para o que entrou ficar consistente, e termina com um aviso e código de falha.
- **Dado do Lattes não é alterado.** `journal_name`, `issn`, `title`, `author_name` e afins ficam como vieram, inclusive com as entidades HTML que o Lattes grava, como `Educa&ccedil;&atilde;o`. Só se corta o que não cabe na coluna. Para exibir, os getters das entidades decodificam com `App\Support\LattesText`. O que é normalizado vai para colunas separadas (`journal_name_normalized`, `qualis_journal_id`...). Regra completa em `AGENTS.md`.
- **Normalização logo depois.** Ao final de cada arquivo, o importador liga autores a docentes e ao tesauro, revistas e instituições (`CurriculumNormalizationService`), e renova o índice de docentes em cache.
- **Memória.** Em lote, rode com `--no-debug`: em modo de depuração o log de SQL cresce sem parar. O comando limpa o EntityManager a cada arquivo.

## Erros comuns

| Mensagem | Causa | O que fazer |
|---|---|---|
| `Invalid XML structure for Lattes CV` | o arquivo não é um XML do Lattes ou está corrompido | exporte de novo |
| `Não é um XML do Lattes (sem CURRICULO-VITAE)` | o arquivo não é um currículo | exporte de novo |
| `Sem ID Lattes` | o XML não traz o ID, e o nome do arquivo não tem 16 dígitos | renomeie para `lattes<ID>.xml` |
| `Use --dept="CODIGO\|Nome do departamento"` | falta a barra vertical | `--dept="ED\|Departamento de Educação"` |
| `pulado` no `--check` ou no log | o XML é mais antigo que o banco | use o XML mais novo, ou `--force` no comando, se tiver certeza |
