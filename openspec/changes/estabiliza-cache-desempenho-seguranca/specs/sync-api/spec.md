## MODIFIED Requirements

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
