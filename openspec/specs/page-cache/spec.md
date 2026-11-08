# page-cache Specification

## Purpose
Cache em disco das páginas públicas em HTML, para que abram rápido sem refazer as consultas: quais requisições e respostas entram no cache, quanto tempo valem, como são servidas e como o cache é limpo pelo painel, pelo terminal e automaticamente.

## Requirements
### Requirement: Liga e desliga por ambiente
O cache de páginas SHALL funcionar somente quando a variável de ambiente `PAGE_CACHE_ENABLED` for verdadeira (ligada no `.env` padrão e desligada no ambiente de testes). Desligado, o sistema MUST NOT ler nem gravar páginas em cache.

#### Scenario: Ambiente de testes
- **WHEN** os testes rodam com `PAGE_CACHE_ENABLED=false`
- **THEN** toda página é gerada na hora e nada é gravado em `var/cache/public_pages`

### Requirement: Requisições elegíveis
Uma requisição SHALL ser elegível ao cache somente se for `GET` ou `HEAD`, não trouxer cookie de sessão (sessão anterior ou qualquer cookie cujo nome contenha "SESS"), não tiver caminho começando por `/admin`, `/_wdt`, `/_profiler`, `/_error`, `/login`, `/logout`, `/photo`, `/api` ou `/busca` e não tiver o parâmetro `q` com texto. As demais requisições MUST ser processadas normalmente, sem ler nem gravar o cache.

#### Scenario: Administrador logado
- **WHEN** um visitante com cookie de sessão abre a página inicial
- **THEN** a página é gerada para ele e não é lida nem gravada no cache

#### Scenario: Texto livre na URL
- **WHEN** um visitante abre `/projetos?q=letramento`
- **THEN** a página é gerada na hora e não é gravada no cache

#### Scenario: Rotas excluídas
- **WHEN** um visitante abre `/busca`, uma rota de `/api` ou `/login`
- **THEN** a resposta não passa pelo cache de páginas

### Requirement: Respostas gravadas
O sistema SHALL gravar no cache apenas respostas com status 200, conteúdo não vazio, tipo HTML (ou sem tipo declarado) e sem nenhum cookie definido. Redirecionamentos, erros, JSON, XML e texto puro MUST NOT ser gravados.

#### Scenario: Resposta que cria cookie
- **WHEN** uma página pública responde criando um cookie de sessão ou de CSRF
- **THEN** a resposta não é gravada

#### Scenario: Página 404
- **WHEN** uma página pública responde 404
- **THEN** nada é gravado

### Requirement: Chave, armazenamento e validade
Cada página SHALL ser guardada como um arquivo HTML em `var/cache/public_pages`, identificado pelo endereço completo da requisição, incluindo a query string. O arquivo SHALL valer por 30 dias a partir da gravação; depois disso, a página MUST ser gerada de novo e regravada.

#### Scenario: Mesma página com filtros diferentes
- **WHEN** visitantes abrem `/projetos?situacao=CONCLUIDO` e `/projetos?situacao=EM_ANDAMENTO`
- **THEN** cada endereço tem o seu próprio arquivo em cache

#### Scenario: Arquivo expirado
- **WHEN** o arquivo de uma página foi gravado há mais de 30 dias
- **THEN** a próxima visita gera a página de novo e substitui o arquivo

### Requirement: Entrega a partir do cache
Quando há arquivo válido para uma requisição elegível, o sistema SHALL responder com o conteúdo do arquivo, status 200, tipo `text/html; charset=UTF-8` e o cabeçalho `X-Page-Cache: HIT`, antes de executar o controlador, e MUST NOT regravar essa resposta.

#### Scenario: Segunda visita
- **WHEN** um visitante sem sessão abre uma página que outro visitante já abriu
- **THEN** a resposta vem do arquivo, com `X-Page-Cache: HIT`

### Requirement: Limpeza pelo painel
O sistema SHALL mostrar em `/admin/cache`, somente a administradores, se o cache está ligado, o número de páginas guardadas, o espaço ocupado, a validade de 30 dias e a pasta usada. A limpeza SHALL ser feita por `POST /admin/cache/clear` com token CSRF válido, após confirmação na tela, removendo a pasta inteira das páginas e também os cálculos guardados da pesquisa pública (panorama e rede por filtro). Token inválido MUST cancelar a ação com mensagem.

#### Scenario: Limpar pelo painel
- **WHEN** o administrador confirma "limpar" em `/admin/cache`
- **THEN** a pasta de páginas é removida, os cálculos guardados da pesquisa são descartados e a tela mostra a mensagem de sucesso

#### Scenario: Token inválido
- **WHEN** o pedido de limpeza chega sem token CSRF válido
- **THEN** nada é limpo e a tela mostra "Token CSRF inválido. Ação cancelada."

### Requirement: Limpeza pelo terminal
O sistema SHALL oferecer o comando `app:cache:clear` (apelidos `app:clear:cache` e `app:page-cache:clear`), que limpa as páginas em cache e os cálculos guardados da pesquisa pública e informa quantos arquivos foram removidos. A opção `--all` (`-a`) SHALL apenas informar a pasta do cache do Symfony, sem limpá-la; a limpeza do cache do Symfony continua sendo feita por `cache:clear`.

#### Scenario: Limpeza após reindexar
- **WHEN** o administrador roda `app:cache:clear` depois de uma indexação
- **THEN** o comando informa o número de páginas removidas e as próximas visitas mostram os dados novos

### Requirement: Limpeza automática
O sistema SHALL apagar as páginas em cache ao fim de uma restauração do banco, de uma importação de currículo pelo painel ou pelo importador do navegador, da exclusão de um currículo pelo painel, da mesclagem de periódicos e da alteração do Qualis ou das bases de um periódico pelo painel. `app:import:lattes`, `app:index:projects` e `app:projects:backfill-members` MUST NOT limpar o cache; isso fica com `app:cache:clear`, que o script de importação de produção chama.

#### Scenario: Restauração do banco
- **WHEN** um backup é restaurado
- **THEN** as páginas públicas em cache são apagadas e as próximas visitas mostram os dados restaurados

#### Scenario: Indexação de projetos
- **WHEN** o administrador roda `app:index:projects`
- **THEN** o cache de páginas continua como estava e o comando recomenda `app:cache:clear`
