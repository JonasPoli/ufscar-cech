## MODIFIED Requirements

### Requirement: CORS no prefixo /api/
O sistema SHALL responder às requisições de qualquer origem em `/api/`: o preflight `OPTIONS` recebe 204, antes do roteamento, com a origem da requisição em `Access-Control-Allow-Origin`, os métodos `GET, POST, OPTIONS` e os cabeçalhos pedidos, válido por uma hora; as demais respostas levam a origem e os mesmos métodos permitidos. `PUT` e `DELETE` MUST NOT ser anunciados.

#### Scenario: Preflight da página do Lattes
- **WHEN** a página do Lattes envia `OPTIONS /api/curriculum/import-html` pedindo o cabeçalho `X-Cech-Sync-Token`
- **THEN** a resposta é 204 com a origem da página e o cabeçalho pedido permitidos

#### Scenario: Métodos anunciados
- **WHEN** qualquer resposta de `/api/` é enviada
- **THEN** `Access-Control-Allow-Methods` é `GET, POST, OPTIONS`
