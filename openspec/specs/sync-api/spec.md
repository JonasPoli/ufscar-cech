# sync-api Specification

## Purpose
Receber, de importadores que rodam no navegador do administrador sobre uma página aberta do Lattes (marcador e script de console), o HTML do currículo e a foto do docente, com autorização por um token derivado do segredo da aplicação, já que essas chamadas vêm de outra origem e não levam a sessão do painel.

## Requirements

### Requirement: Token de sincronização
O sistema SHALL autorizar as rotas de escrita da API de sincronização por um token de 32 caracteres hexadecimais derivado do segredo da aplicação (`APP_SECRET`), aceito no cabeçalho `X-Cech-Sync-Token` ou no campo `token` do corpo, comparado em tempo constante e exibido apenas na tela `/admin/curriculum/new`; o token MUST mudar quando o segredo da aplicação muda.

#### Scenario: Requisição sem token
- **WHEN** uma rota de escrita da API recebe uma requisição sem token ou com token errado
- **THEN** a resposta é JSON `{"success": false, "message": "Token de sincronização ausente ou inválido."}` com status 401 e nada é gravado

#### Scenario: Segredo rotacionado
- **WHEN** o `APP_SECRET` da instalação é trocado
- **THEN** os marcadores e scripts com o token antigo passam a receber 401

### Requirement: CORS no prefixo /api/
O sistema SHALL aplicar CORS em `/api/` com os métodos `GET, POST, OPTIONS` (`PUT` e `DELETE` MUST NOT ser anunciados). `GET` em `/api/v1/*` e `/api/temas/*` SHALL receber `Access-Control-Allow-Origin: *`. As demais rotas só SHALL receber a origem da requisição se ela for `buscatextual.cnpq.br`, `lattes.cnpq.br`, `cech.wab.com.br`, o próprio host ou, só em dev e teste, `localhost`; as outras origens MUST NOT receber o cabeçalho. O preflight `OPTIONS` recebe 204 antes do roteamento, válido por uma hora.

#### Scenario: Preflight da página do Lattes
- **WHEN** a página do Lattes envia `OPTIONS /api/curriculum/import-html` pedindo o cabeçalho `X-Cech-Sync-Token`
- **THEN** a resposta é 204 com a origem da página e o cabeçalho pedido permitidos

#### Scenario: Métodos anunciados
- **WHEN** qualquer resposta de `/api/` com origem permitida é enviada
- **THEN** `Access-Control-Allow-Methods` é `GET, POST, OPTIONS`

#### Scenario: Origem não listada em rota de escrita
- **WHEN** uma página de `https://outro-site.example` envia `POST /api/curriculum/import-html` ou o preflight dele
- **THEN** a resposta não traz `Access-Control-Allow-Origin`, e o navegador bloqueia a leitura

#### Scenario: Leitura pública
- **WHEN** qualquer origem consulta `GET /api/v1/metricas` ou `GET /api/temas/...`
- **THEN** a resposta traz `Access-Control-Allow-Origin: *`

#### Scenario: Localhost só fora de produção
- **WHEN** uma página em `http://localhost:3000` faz `POST` a `/api/photo/save`
- **THEN** em dev e teste a origem é aceita, e em produção não recebe o cabeçalho

### Requirement: Importação de currículo por HTML
O sistema SHALL oferecer `POST /api/curriculum/import-html`, que recebe em JSON (ou formulário) o `html` completo da página do Lattes e, opcionalmente, o `idLattes`, importa o currículo como a importação de HTML do painel, busca a foto oficial quando o docente não tem foto e executa as etapas pós-importação da web.

#### Scenario: Importação bem-sucedida
- **WHEN** a requisição traz token válido e o HTML de um currículo
- **THEN** a resposta JSON traz `success: true`, `message` (resumo do relatório e aviso das etapas pesadas), `notice`, `warnings`, `researcher` (`id`, `fullName`, `idLattes`, `orcid`, `photoUrl`, `productionsCount`, `orientationsCount`) e `report`

#### Scenario: ID que não é ID Lattes
- **WHEN** o `idLattes` enviado não tem exatamente 16 dígitos (por exemplo, um K-ID de 10 caracteres)
- **THEN** ele é descartado e o ID é procurado no próprio HTML

#### Scenario: HTML ausente ou ilegível
- **WHEN** o corpo não traz `html`, ou a importação do HTML falha (por exemplo, sem ID Lattes na página)
- **THEN** a resposta tem `success: false`, com status 400 no primeiro caso e 500 com a mensagem do erro no segundo
- **AND** uma falha depois da importação (foto, Repositório, cache) vira item de `warnings`, com `success: true`

### Requirement: Recebimento da foto pela API
O sistema SHALL oferecer `POST /api/photo/save`, que recebe `idLattes` (ID Lattes ou slug do docente) e `photoData` (imagem em base64, com ou sem o prefixo `data:`) e grava a foto do docente pelas mesmas regras das demais fotos.

#### Scenario: Foto gravada
- **WHEN** a requisição traz token válido, um docente existente e uma imagem válida
- **THEN** a resposta traz `success: true`, a mensagem com o nome do docente, `photoUrl` e `researcher`

#### Scenario: Erros de entrada
- **WHEN** falta `idLattes` ou `photoData`, o base64 é inválido ou o conteúdo não é imagem válida
- **THEN** a resposta tem status 400 e `success: false`; docente inexistente dá 404

### Requirement: Importador no navegador
O sistema SHALL oferecer em `/admin/curriculum/new` um marcador (bookmarklet) que, executado sobre uma página do Lattes, envia o HTML da página e o ID encontrado nela para `/api/curriculum/import-html` com o token da instalação, tentando o endereço do próprio portal e, se falhar, endereços locais de desenvolvimento, e mostra na página do Lattes o resultado e os itens novos do relatório.

#### Scenario: Sincronização pelo marcador
- **WHEN** o administrador aciona o marcador numa página de currículo do Lattes
- **THEN** a página mostra o nome do docente, o ID Lattes, os totais de produções e orientações e até 8 itens novos, além dos avisos da importação
