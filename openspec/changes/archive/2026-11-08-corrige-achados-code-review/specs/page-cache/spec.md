## MODIFIED Requirements

### Requirement: Requisições elegíveis
Uma requisição SHALL ser elegível ao cache somente se for `GET` ou `HEAD`, sem cookie de sessão, fora de `/admin`, `/_wdt`, `/_profiler`, `/_error`, `/login`, `/logout`, `/photo`, `/api` e `/busca`, sem texto em `q` e só com parâmetros que as páginas públicas leem. A página que descarta ou reescreve um valor de filtro (área ou departamento inexistente, por exemplo) MUST NOT ser gravada. As demais requisições MUST ser processadas sem ler nem gravar o cache.

#### Scenario: Administrador logado
- **WHEN** um visitante com cookie de sessão abre a página inicial
- **THEN** a página é gerada para ele e não é lida nem gravada no cache

#### Scenario: Texto livre na URL
- **WHEN** um visitante abre `/projetos?q=letramento`
- **THEN** a página é gerada na hora e não é gravada no cache

#### Scenario: Rotas excluídas
- **WHEN** um visitante abre `/busca`, uma rota de `/api` ou `/login`
- **THEN** a resposta não passa pelo cache de páginas

#### Scenario: Parâmetro desconhecido
- **WHEN** alguém abre `/professor/fulano?a=1` ou uma página com `utm_source`
- **THEN** a página é gerada na hora e não é gravada no cache

#### Scenario: Filtro com valor inexistente
- **WHEN** alguém abre `/coautorias?area=area-inventada`
- **THEN** a página mostra o conjunto inteiro e não é gravada no cache

### Requirement: Limpeza pelo terminal
O sistema SHALL oferecer o comando `app:cache:clear` (apelidos `app:clear:cache` e `app:page-cache:clear`), que limpa as páginas em cache e os cálculos guardados da pesquisa pública e informa quantos arquivos foram removidos. A opção `--all` (`-a`) SHALL esvaziar também o cache de dados da aplicação (`cache.app`: índices dos tesauros, Pesquisa Temática e catálogos); o cache compilado do Symfony continua sendo limpo por `cache:clear`.

#### Scenario: Limpeza após reindexar
- **WHEN** o administrador roda `app:cache:clear` depois de uma indexação
- **THEN** o comando informa o número de páginas removidas e as próximas visitas mostram os dados novos

#### Scenario: Limpeza completa dos dados
- **WHEN** o administrador roda `app:cache:clear --all`
- **THEN** os índices dos tesauros e os dados da Pesquisa Temática são refeitos na próxima leitura

### Requirement: Limpeza automática
O sistema SHALL apagar as páginas em cache e os cálculos da pesquisa ao fim de uma restauração do banco, de uma importação de currículo pelo painel ou pelo navegador, da exclusão de um currículo, da mesclagem ou exclusão de periódicos, da alteração do Qualis ou das bases de um periódico e da gravação das configurações de SEO. `app:import:lattes`, `app:index:projects` e `app:projects:backfill-members` MUST NOT limpar o cache.

#### Scenario: Restauração do banco
- **WHEN** um backup é restaurado
- **THEN** as páginas públicas em cache e os cálculos da pesquisa são apagados e as próximas visitas mostram os dados restaurados

#### Scenario: Indexação de projetos
- **WHEN** o administrador roda `app:index:projects`
- **THEN** o cache de páginas continua como estava e o comando recomenda `app:cache:clear`

#### Scenario: Configurações de SEO
- **WHEN** o administrador grava um novo título padrão em `/admin/seo/`
- **THEN** as páginas em cache são apagadas e o título novo aparece na próxima visita

## ADDED Requirements

### Requirement: Texto livre fora do cache da pesquisa
Os cálculos da pesquisa (panorama, pares e publicações em comum) feitos com texto livre em `q` MUST NOT ser guardados no cache da pesquisa; os demais recortes, de valores limitados, SHALL continuar guardados por um dia.

#### Scenario: Busca por termo na API
- **WHEN** alguém chama `/api/v1/metricas?q=<termo>` com milhares de termos diferentes
- **THEN** nenhum arquivo novo é gravado no cache da pesquisa
