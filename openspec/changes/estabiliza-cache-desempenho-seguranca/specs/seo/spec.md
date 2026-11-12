## MODIFIED Requirements

### Requirement: robots.txt
O sistema SHALL servir em `GET /robots.txt`, como texto puro, o conteúdo personalizado gravado nas configurações; sem conteúdo personalizado, SHALL servir o padrão, que permite o resto do portal e bloqueia `/admin/`, `/login`, `/busca`, `/api/`, os fragmentos e as exportações do perfil, `/indicadores/fragment/` e os endereços com os parâmetros `tema`, `aba`, `modo`, `ordem`, `ligacoes`, `pares`, `docente`, `t` e `q`, pede `Crawl-delay: 5` e aponta o `sitemap.xml` no domínio da requisição.

#### Scenario: Sem conteúdo personalizado
- **WHEN** o campo de robots.txt está vazio nas configurações
- **THEN** `/robots.txt` traz `User-agent: *`, `Disallow: /admin/`, `Disallow: /login`, `Disallow: /busca`, `Disallow: /api/`, `Disallow: /professor/*/fragment/`, `Disallow: /professor/*/export/`, `Disallow: /indicadores/fragment/`, `Crawl-delay: 5`, `Allow: /` e a linha `Sitemap:` com o endereço do sitemap

#### Scenario: Parâmetros bloqueados
- **WHEN** o robots.txt padrão é servido
- **THEN** cada parâmetro bloqueado tem as linhas `Disallow: /*?<parâmetro>=` e `Disallow: /*&<parâmetro>=`, de modo que `/professor/fulano?tema=ensino` e `/temas?t=leitura` ficam fora da varredura

#### Scenario: Páginas do sitemap continuam liberadas
- **WHEN** um robô que respeita o robots.txt padrão lê o sitemap
- **THEN** pode visitar os perfis, os departamentos e as páginas de assunto sem parâmetros

#### Scenario: Conteúdo personalizado
- **WHEN** o administrador grava um conteúdo próprio para o robots.txt
- **THEN** `/robots.txt` passa a servir exatamente esse conteúdo, sem as linhas do padrão

## ADDED Requirements

### Requirement: Validade de robots.txt e sitemap.xml
`/robots.txt` e `/sitemap.xml` SHALL responder com `Cache-Control: public, max-age=3600`, para que robôs e intermediários reaproveitem a resposta por uma hora.

#### Scenario: Leitura do sitemap
- **WHEN** um robô pede `/sitemap.xml`
- **THEN** a resposta traz `Cache-Control: public, max-age=3600`

#### Scenario: Leitura do robots.txt personalizado
- **WHEN** um robô pede `/robots.txt` e há conteúdo personalizado
- **THEN** a resposta traz o conteúdo personalizado e `Cache-Control: public, max-age=3600`
