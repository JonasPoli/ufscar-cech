# page-cache Specification

## Purpose
Cache em disco das páginas públicas em HTML, para que abram rápido sem refazer as consultas: quais requisições e respostas entram no cache, quanto tempo valem, como são servidas e como o cache é limpo pelo painel, pelo terminal e automaticamente.

## Requirements

### Requirement: Liga e desliga por ambiente
O cache de páginas SHALL funcionar somente quando a variável de ambiente `PAGE_CACHE_ENABLED` for verdadeira (ligada no `.env` padrão e desligada no ambiente de testes). Desligado, o sistema MUST NOT ler nem gravar páginas em cache. A pasta do cache SHALL ter o marcador `.enabled` só com o cache ligado: desligado, o marcador MUST ser removido na primeira requisição que chega à aplicação, e `app:cache:clear` também o remove.

#### Scenario: Ambiente de testes
- **WHEN** os testes rodam com `PAGE_CACHE_ENABLED=false`
- **THEN** toda página é gerada na hora e nada é gravado em `var/cache/public_pages`

#### Scenario: Cache desligado com páginas antigas na pasta
- **WHEN** o administrador muda `PAGE_CACHE_ENABLED` para `false` e roda `app:cache:clear`
- **THEN** o marcador `.enabled` deixa de existir e nenhuma página é servida do cache

#### Scenario: Marcador criado ao gravar
- **WHEN** o cache está ligado e a primeira página é gravada depois de uma limpeza
- **THEN** a pasta passa a ter o marcador `.enabled`

### Requirement: Requisições elegíveis
Uma requisição SHALL ser elegível ao cache somente se for `GET` ou `HEAD`, sem cookie de sessão, fora de `/admin`, `/_wdt`, `/_profiler`, `/_error`, `/login`, `/logout`, `/photo`, `/api` e `/busca`, sem texto em `q` e só com parâmetros que as páginas públicas leem. A página que descarta ou reescreve um valor de filtro (área ou departamento inexistente, por exemplo) MUST NOT ser gravada. As demais requisições MUST NOT gravar o cache e só recebem a cópia guardada do seu endereço canônico.

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
- **WHEN** alguém abre `/projetos?a=1` ou uma página com `utm_source`
- **THEN** a página é gerada na hora e não é gravada no cache

#### Scenario: Perfil com parâmetro desconhecido
- **WHEN** alguém abre `/professor/fulano?a=1` e `/professor/fulano` já está em cache
- **THEN** recebe a cópia guardada do perfil, e nenhum arquivo novo é gravado

#### Scenario: Filtro com valor inexistente
- **WHEN** alguém abre `/coautorias?area=area-inventada`
- **THEN** a página mostra o conjunto inteiro e não é gravada no cache

### Requirement: Respostas gravadas
O sistema SHALL gravar no cache apenas respostas com status 200, conteúdo não vazio e de no máximo 16 MB, tipo HTML (ou sem tipo declarado) e sem nenhum cookie definido. Redirecionamentos, erros, JSON, XML e texto puro MUST NOT ser gravados.

#### Scenario: Resposta que cria cookie
- **WHEN** uma página pública responde criando um cookie de sessão ou de CSRF
- **THEN** a resposta não é gravada

#### Scenario: Página 404
- **WHEN** uma página pública responde 404
- **THEN** nada é gravado

#### Scenario: Página grande demais
- **WHEN** uma página pública gera mais de 16 MB de HTML
- **THEN** ela é entregue ao visitante e não é gravada

### Requirement: Chave, armazenamento e validade
Cada página SHALL ser guardada compactada em gzip, num arquivo `<md5 do endereço canônico>.html.gz` em `var/cache/public_pages`. O endereço canônico SHALL ser o caminho com os parâmetros em ordem alfabética; no perfil do docente (`/professor/{slug}`), fora de `aba=busca`, SHALL ser só o caminho, porque o servidor não lê esses parâmetros. O arquivo SHALL valer por 30 dias a partir da gravação; depois disso, a página MUST ser gerada de novo e regravada.

#### Scenario: Mesma página com filtros diferentes
- **WHEN** visitantes abrem `/projetos?situacao=CONCLUIDO` e `/projetos?situacao=EM_ANDAMENTO`
- **THEN** cada endereço tem o seu próprio arquivo em cache

#### Scenario: Ordem dos parâmetros
- **WHEN** visitantes abrem `/coautorias?departamento=ED&ligacoes=200` e `/coautorias?ligacoes=200&departamento=ED`
- **THEN** os dois endereços usam o mesmo arquivo

#### Scenario: Perfil com temas diferentes
- **WHEN** robôs abrem `/professor/fulano?tema=astronomia` e `/professor/fulano?tema=ensino`
- **THEN** os dois usam o mesmo arquivo de `/professor/fulano`, e o disco guarda uma só cópia do perfil

#### Scenario: Perfil aberto pela busca
- **WHEN** alguém abre `/professor/fulano?aba=busca&tema=ensino`
- **THEN** a página usa um arquivo próprio, separado da cópia do perfil sem parâmetros

#### Scenario: Arquivo compactado
- **WHEN** uma página de 6 MB de HTML é gravada
- **THEN** o arquivo em disco termina em `.html.gz` e, descompactado, é igual ao HTML gerado

#### Scenario: Arquivo expirado
- **WHEN** o arquivo de uma página foi gravado há mais de 30 dias
- **THEN** a próxima visita gera a página de novo e substitui o arquivo

### Requirement: Entrega a partir do cache
Quando há arquivo válido para o endereço canônico de uma requisição `GET` ou `HEAD` sem cookie de sessão e o marcador `.enabled` existe, o sistema SHALL responder com esse arquivo antes de iniciar a aplicação, com status 200, tipo `text/html; charset=UTF-8` e os cabeçalhos `X-Page-Cache: HIT`, `ETag`, `Last-Modified`, `Cache-Control: no-cache` e `Vary: Accept-Encoding`, e MUST NOT regravar essa resposta.

#### Scenario: Segunda visita
- **WHEN** um visitante sem sessão abre uma página que outro visitante já abriu
- **THEN** a resposta vem do arquivo, com `X-Page-Cache: HIT`, sem iniciar a aplicação

#### Scenario: Pedido HEAD
- **WHEN** um robô faz `HEAD` numa página em cache
- **THEN** a resposta traz os cabeçalhos do HIT e nenhum corpo

#### Scenario: Sem marcador
- **WHEN** a pasta tem páginas gravadas, mas não tem o marcador `.enabled`
- **THEN** nenhuma página é servida antes da aplicação

### Requirement: Limpeza pelo painel
O sistema SHALL mostrar em `/admin/cache`, somente a administradores, se o cache está ligado, o número de páginas guardadas, o espaço ocupado, a validade de 30 dias e a pasta usada. A limpeza SHALL ser feita por `POST /admin/cache/clear` com token CSRF válido, após confirmação na tela, removendo a pasta inteira das páginas e também os cálculos guardados da pesquisa pública (panorama e rede por filtro). Token inválido MUST cancelar a ação com mensagem.

#### Scenario: Limpar pelo painel
- **WHEN** o administrador confirma "limpar" em `/admin/cache`
- **THEN** a pasta de páginas é removida, os cálculos guardados da pesquisa são descartados e a tela mostra a mensagem de sucesso

#### Scenario: Token inválido
- **WHEN** o pedido de limpeza chega sem token CSRF válido
- **THEN** nada é limpo e a tela mostra "Token CSRF inválido. Ação cancelada."

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

### Requirement: Texto livre fora do cache da pesquisa
Os cálculos da pesquisa (panorama, pares e publicações em comum) feitos com texto livre em `q` MUST NOT ser guardados no cache da pesquisa; os demais recortes, de valores limitados, SHALL continuar guardados por um dia.

#### Scenario: Busca por termo na API
- **WHEN** alguém chama `/api/v1/metricas?q=<termo>` com milhares de termos diferentes
- **THEN** nenhum arquivo novo é gravado no cache da pesquisa

### Requirement: Compactação na entrega
Ao servir uma página do cache, o sistema SHALL enviar o arquivo como está, com `Content-Encoding: gzip`, a quem declara aceitar gzip em `Accept-Encoding`; aos demais, SHALL enviar o HTML descompactado. Nos dois casos, `Content-Length` MUST ser o tamanho do corpo enviado.

#### Scenario: Navegador com gzip
- **WHEN** um navegador com `Accept-Encoding: gzip, br` abre uma página em cache
- **THEN** recebe o arquivo compactado com `Content-Encoding: gzip`

#### Scenario: Cliente sem gzip
- **WHEN** um cliente sem `Accept-Encoding` abre uma página em cache
- **THEN** recebe o HTML descompactado, sem `Content-Encoding`

### Requirement: Revalidação pelo navegador
O `ETag` de uma página em cache SHALL mudar quando o arquivo é regravado. Quando a requisição traz `If-None-Match` igual ao `ETag` atual, o sistema SHALL responder 304, sem corpo, com os mesmos cabeçalhos de cache.

#### Scenario: Página já vista
- **WHEN** o navegador volta a pedir uma página em cache com o `ETag` que recebeu
- **THEN** a resposta é 304, sem corpo

#### Scenario: Página regravada
- **WHEN** a página foi gerada de novo depois da última visita
- **THEN** o `ETag` antigo não corresponde e a resposta é 200 com o HTML novo

### Requirement: Teto de espaço e descarte
O cache SHALL ter um teto de espaço definido por `PAGE_CACHE_MAX_MB` (padrão 2048 MB), mostrado em `/admin/cache`. Em média a cada 200 gravações, o sistema SHALL apagar as páginas vencidas e, se o total passar do teto, as mais antigas até sobrar no máximo 80% dele. `app:cache:clear --prune` SHALL rodar o mesmo descarte sem limpar o resto e informar quantas páginas saíram e quanto sobrou.

#### Scenario: Cache acima do teto
- **WHEN** o cache passa de 2048 MB e o descarte roda
- **THEN** as páginas mais antigas são apagadas até o total ficar em no máximo 1638 MB, e as mais recentes continuam

#### Scenario: Descarte pelo terminal
- **WHEN** o administrador roda `app:cache:clear --prune`
- **THEN** só as páginas vencidas e as que passam do teto são apagadas, e o comando informa as removidas, as que restam e o espaço ocupado

#### Scenario: Teto no painel
- **WHEN** o administrador abre `/admin/cache`
- **THEN** a tela mostra o espaço ocupado e o teto configurado

### Requirement: Aquecimento das páginas principais
O sistema SHALL oferecer `app:cache:warm`, que pede uma de cada vez as páginas principais: início, `/indicadores` e as 4 abas, `/periodicos`, `/periodicos/geral` e as 5 bases, `/departamentos` e cada departamento, `/coautorias`, `/explorar` e as listas, `/temas`, `/projetos`, `/sobre`, `/sobre-os-dados` e `/novidades`. Mostra status e tempo de cada uma, não para num erro e sai com falha se alguma falhar.

#### Scenario: Aquecimento pelo endereço do site
- **WHEN** o administrador roda `app:cache:warm --url=https://cech.wab.com.br`
- **THEN** cada página é pedida em sequência, com a pausa de `--pausa` segundos (padrão 1) entre elas, e o comando informa status e tempo de cada uma e o total

#### Scenario: Uma página falha
- **WHEN** uma das páginas responde 500 ou excede o tempo
- **THEN** as demais continuam sendo pedidas, e o comando termina com falha

#### Scenario: Filtro e modo sem rede
- **WHEN** o comando roda com `--apenas=/periodicos` ou com `--em-processo`
- **THEN** só os caminhos que contêm o texto são pedidos, e com `--em-processo` o pedido é feito ao kernel da aplicação, sem rede

#### Scenario: Sem endereço
- **WHEN** não há `--url`, nem `DEFAULT_URI`, nem `--em-processo`
- **THEN** o comando avisa que falta o endereço e termina com falha

### Requirement: Aquecimento depois da limpeza
`app:cache:clear --aquecer` SHALL chamar `app:cache:warm` depois de limpar, aceitando `--url` para o endereço do site. A falha do aquecimento MUST NOT alterar o resultado da limpeza.

#### Scenario: Limpar e aquecer
- **WHEN** o administrador roda `app:cache:clear --all --aquecer --url=https://cech.wab.com.br`
- **THEN** o cache é limpo e, em seguida, as páginas principais são pedidas

#### Scenario: Limpar sem aquecer
- **WHEN** `app:cache:clear` roda sem `--aquecer`
- **THEN** nenhuma página é pedida
