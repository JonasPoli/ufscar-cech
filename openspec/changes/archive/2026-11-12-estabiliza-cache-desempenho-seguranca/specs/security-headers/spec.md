## Purpose

Cabeçalhos HTTP de segurança enviados em todas as respostas do portal, públicas ou do painel, para limitar o vazamento de endereços, a interpretação errada de conteúdo e o uso das páginas dentro de sites de terceiros.

## ADDED Requirements

### Requirement: Cabeçalhos de segurança em todas as respostas
Toda resposta do portal SHALL trazer `Referrer-Policy: strict-origin-when-cross-origin`, `X-Content-Type-Options: nosniff` e `X-Frame-Options: SAMEORIGIN`, inclusive as páginas servidas do cache antes da aplicação, as respostas 304 e as respostas 429 do freio para robôs.

#### Scenario: Página pública gerada
- **WHEN** um visitante abre a página inicial fora do cache
- **THEN** a resposta traz os três cabeçalhos

#### Scenario: Página servida do cache
- **WHEN** um visitante recebe uma página com `X-Page-Cache: HIT`
- **THEN** a resposta traz os mesmos três cabeçalhos

#### Scenario: Painel dentro de outro site
- **WHEN** um site de outro domínio tenta abrir `/admin/` num iframe
- **THEN** o navegador recusa exibir a página, por causa de `X-Frame-Options: SAMEORIGIN`

### Requirement: HSTS em HTTPS
Nas respostas a requisições feitas por HTTPS, o sistema SHALL enviar `Strict-Transport-Security` com um `max-age` positivo. Nas requisições por HTTP simples, o cabeçalho MUST NOT ser enviado.

#### Scenario: Acesso por HTTPS
- **WHEN** um visitante abre o portal por HTTPS
- **THEN** a resposta traz `Strict-Transport-Security`

#### Scenario: Ambiente local por HTTP
- **WHEN** o desenvolvedor abre o portal em `http://localhost:8000`
- **THEN** a resposta não traz `Strict-Transport-Security`

### Requirement: Hosts aceitos
O sistema SHALL aceitar apenas requisições cujo `Host` case com a expressão de `TRUSTED_HOSTS`; o padrão cobre `localhost`, `127.0.0.1`, `cech.wab.com.br` e `www.cech.wab.com.br`. Um `Host` fora da lista MUST receber 400. `trusted_proxies` MUST NOT ser configurado sem confirmar os proxies reais do RunCloud.

#### Scenario: Host conhecido
- **WHEN** um visitante abre `https://cech.wab.com.br/`
- **THEN** a página é servida normalmente

#### Scenario: Host desconhecido
- **WHEN** uma requisição chega com `Host: outro.example`
- **THEN** a resposta é 400

#### Scenario: Novo domínio
- **WHEN** o administrador inclui o domínio novo na variável `TRUSTED_HOSTS`
- **THEN** o portal passa a responder nesse domínio
