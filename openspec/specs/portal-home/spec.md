# portal-home Specification

## Purpose
Página inicial do portal público (`/`): a porta de entrada para a busca, os assuntos mais frequentes, os números do Centro, os atalhos para as páginas de pesquisa, os departamentos, as publicações recentes e os docentes em destaque.

## Requirements

### Requirement: Busca na capa
A página inicial SHALL oferecer uma caixa de busca com os modos Especialistas (marcado no início), Publicações e Pesquisador, que envia o termo (`q`) e o modo (`modo`) para `/busca`. O comportamento da busca é descrito em `expert-search`.

#### Scenario: Busca de publicações a partir da capa
- **WHEN** a pessoa escolhe Publicações, digita "letramento" e envia
- **THEN** o portal abre `/busca?modo=publicacoes&q=letramento`

### Requirement: Assuntos para começar
A página inicial SHALL mostrar os 14 primeiros assuntos do catálogo do Explorar (`explore-catalog`), com o número de ocorrências e link para a página de cada um, seguidos do link "Todos os N assuntos" para `/explorar`. O bloco MUST ficar oculto quando o catálogo de assuntos está vazio.

#### Scenario: Índice temático vazio
- **WHEN** nenhum termo atende aos critérios do catálogo de assuntos
- **THEN** a página inicial não mostra o bloco de assuntos

### Requirement: Números do Centro
A página inicial SHALL mostrar seis números do portal inteiro: docentes com vínculo vigente ("Docentes Ativos"); produções únicas, com o total de registros nos currículos; artigos com Qualis únicos, com o total de artigos registrados; livros e capítulos únicos, com o número de registros de livros e de capítulos; orientações concluídas; e registros de trabalhos em eventos. As contagens únicas MUST seguir as mesmas regras dos números globais dos Indicadores (`indicators`).

#### Scenario: Produção declarada por dois docentes
- **WHEN** um artigo com DOI está declarado nos currículos de dois docentes
- **THEN** ele soma 1 em produções únicas e 2 nos registros nos Lattes

### Requirement: Caminhos da pesquisa
A página inicial SHALL ter o bloco "Caminhos da pesquisa", com atalhos para Coautorias, Indicadores, Explorar e Projetos.

#### Scenario: Atalho para coautorias
- **WHEN** a pessoa clica em "A ciência acontece em rede"
- **THEN** o portal abre `/coautorias`

### Requirement: Departamentos na capa
A página inicial SHALL mostrar os 8 primeiros departamentos da lista de departamentos (`department-page`), com código e nome e link para a página de cada um, e um link para `/departamentos`.

#### Scenario: Ordem dos departamentos
- **WHEN** a página inicial é aberta
- **THEN** os departamentos aparecem na mesma ordem da lista de departamentos, do que tem mais docentes vigentes para o que tem menos

### Requirement: Publicações recentes
A página inicial SHALL listar os 10 registros de produção mais recentes, do ano mais novo para o mais antigo e, no mesmo ano, do registro gravado por último, cada um com tipo, ano, selo do periódico ou Qualis quando houver, título, veículo, docente dono do currículo (com link para o perfil) e link para o DOI em nova aba quando houver. O link "Ver todas" SHALL levar à busca no modo Publicações com o período a partir do ano anterior, ordenada das mais recentes.

#### Scenario: Publicação com DOI
- **WHEN** um dos registros recentes tem DOI
- **THEN** o item mostra um link para o DOI que abre em nova aba

#### Scenario: Ver todas
- **WHEN** em 2026 o visitante clica em "Ver todas"
- **THEN** abre `/busca?modo=publicacoes&de=2025`, com a lista de publicações, e não a capa da busca

### Requirement: Docentes em destaque
A página inicial SHALL listar 8 docentes, de qualquer vínculo, ordenados pelo número de artigos Qualis A1 declarados e, no empate, pelo total de produções, cada um com foto ou iniciais, nome, selo "N A1" quando há artigos A1, departamento (ou "CECH / UFSCar") e período do vínculo quando informado, com link para o perfil.

#### Scenario: Docente sem artigo A1
- **WHEN** um docente entra na lista sem nenhum artigo A1
- **THEN** o cartão dele não mostra o selo A1
