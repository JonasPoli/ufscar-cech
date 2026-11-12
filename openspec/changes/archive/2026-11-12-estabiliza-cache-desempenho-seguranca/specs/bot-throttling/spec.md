## Purpose

Limitar quantas páginas fora do cache cada robô pode pedir por minuto, para que uma varredura não esgote o servidor nem o disco, sem afetar visitantes comuns nem as páginas já guardadas.

## ADDED Requirements

### Requirement: Identificação do robô
O sistema SHALL tratar como robô a requisição cujo User-Agent contém, sem diferença de caixa, uma palavra com `bot`, `crawler`, `spider`, `slurp` ou `scrapy`. Essa palavra SHALL identificar o robô, e cada robô MUST ter a sua própria cota. User-Agent sem nenhuma delas é de visitante comum.

#### Scenario: Dois robôs ao mesmo tempo
- **WHEN** o PerplexityBot e o Amazonbot pedem páginas no mesmo minuto
- **THEN** cada um é contado à parte, como `perplexitybot` e `amazonbot`

#### Scenario: Navegador comum
- **WHEN** um navegador com User-Agent do Chrome pede páginas
- **THEN** a requisição não é contada como de robô

### Requirement: Limite por minuto fora do cache
Nas requisições que não são servidas do cache de páginas, um mesmo robô SHALL poder pedir até 30 por minuto. A partir da 31ª no mesmo minuto, o sistema MUST responder 429, com `Retry-After: 60`, `Cache-Control: no-store` e o texto "Muitas requisições. Tente novamente em um minuto.", antes de iniciar a aplicação. A contagem SHALL recomeçar a cada minuto.

#### Scenario: Robô acima do limite
- **WHEN** um robô pede 31 páginas fora do cache no mesmo minuto
- **THEN** a 31ª recebe 429 com `Retry-After: 60`, sem gerar a página

#### Scenario: Minuto seguinte
- **WHEN** o robô que recebeu 429 volta a pedir páginas no minuto seguinte
- **THEN** as próximas 30 são atendidas

### Requirement: Requisições nunca limitadas
O freio MUST NOT se aplicar a visitantes comuns, às páginas servidas do cache, a `/robots.txt` nem a `/sitemap.xml`. Páginas servidas do cache MUST NOT contar na cota do robô.

#### Scenario: Página em cache
- **WHEN** um robô que já gastou a cota do minuto pede uma página que está em cache
- **THEN** recebe a página do cache, com `X-Page-Cache: HIT`

#### Scenario: robots.txt e sitemap
- **WHEN** um robô acima do limite pede `/robots.txt` ou `/sitemap.xml`
- **THEN** recebe o arquivo normalmente

#### Scenario: Visitante comum
- **WHEN** um visitante sem User-Agent de robô abre 100 páginas fora do cache em um minuto
- **THEN** nenhuma recebe 429
