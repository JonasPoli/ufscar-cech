# seo Specification

## Purpose
Metadados das páginas públicas para buscadores e redes sociais (título, descrição, palavras-chave, robots, URL canônica, Open Graph, Google Analytics e verificação do Search Console), o `sitemap.xml`, o `robots.txt` e a tela de SEO do painel que guarda as configurações globais.

## Requirements

### Requirement: Configurações globais de SEO
O sistema SHALL manter um único registro de configurações do site: ID do Google Analytics, código de verificação do Google Search Console, título, descrição e palavras-chave padrão, imagem de compartilhamento, URL base e conteúdo do `robots.txt`. O registro SHALL ser criado com valores padrão na primeira leitura e ficar disponível para todos os templates. Falha ao lê-lo MUST NOT impedir a página de abrir; valem então os textos padrão dos templates.

#### Scenario: Primeiro acesso
- **WHEN** o portal é aberto e ainda não existe registro de configurações
- **THEN** o registro é criado com o título "Portal de Produção Científica & Acadêmica | CECH UFSCar" e as demais configurações padrão

### Requirement: Metadados padrão das páginas públicas
Toda página pública SHALL ter título, descrição e palavras-chave (os padrão das configurações, salvo quando a página define os seus), a metatag robots `index, follow, max-snippet:-1, max-image-preview:large, max-video-preview:-1` (salvo quando a página define outra) e a URL canônica, que por padrão é o endereço sem a query string.

#### Scenario: Página com filtros na URL
- **WHEN** um visitante abre `/departamentos?x=1`
- **THEN** a URL canônica da página é o endereço sem `?x=1`

#### Scenario: Página sem textos próprios
- **WHEN** uma página pública não define título nem descrição
- **THEN** ela usa o título e a descrição padrão das configurações de SEO

### Requirement: Open Graph, Google Analytics e Search Console
Toda página pública SHALL ter as tags Open Graph `og:site_name`, `og:type`, `og:locale` (pt_BR), título, descrição, URL (a canônica) e imagem. A imagem SHALL ser a configurada (endereço absoluto ou caminho no próprio site) ou, sem ela, a imagem padrão do portal. Quando configurados, o sistema SHALL incluir o script do Google Analytics com o ID informado e a metatag `google-site-verification` com o código do Search Console.

#### Scenario: Google Analytics configurado
- **WHEN** o administrador grava um ID de medição do Google Analytics
- **THEN** as páginas geradas a partir daí (e as que estavam no cache de páginas, depois de limpá-lo) carregam o script do Google Analytics com esse ID

#### Scenario: Sem Google Analytics
- **WHEN** o ID de medição está vazio
- **THEN** nenhuma página gerada carrega o script do Google Analytics

#### Scenario: Imagem de compartilhamento padrão
- **WHEN** não há imagem configurada e a página não define a sua
- **THEN** `og:image` aponta para a imagem padrão do portal no domínio da requisição

### Requirement: Páginas de resultado de busca fora do índice
As páginas cujo conteúdo depende de texto livre ou de filtros SHALL pedir aos buscadores `noindex, follow`: a busca quando há busca feita, o catálogo de projetos quando há texto em `q`, a página de coautorias quando há filtro e as páginas de assunto, área e grande área quando há um recorte aceito.

#### Scenario: Projetos com texto livre
- **WHEN** um buscador lê `/projetos?q=pibid`
- **THEN** a página traz a metatag robots `noindex, follow`

#### Scenario: Coautorias sem filtro
- **WHEN** um buscador lê `/coautorias`
- **THEN** a página traz `index, follow` e canônica `/coautorias`

#### Scenario: Página de assunto só com paginação
- **WHEN** um buscador lê uma página de assunto com `?pagina=2` ou com um parâmetro que a página ignora
- **THEN** a página continua com `index, follow`, porque não há recorte aceito

### Requirement: Canônica das páginas de resultado
As páginas de resultado com recorte SHALL declarar como canônica a versão sem os parâmetros que geram o recorte, exceto o catálogo de projetos, cuja URL canônica inclui os filtros.

#### Scenario: Assunto filtrado por período
- **WHEN** um buscador lê uma página de assunto com `?de=2015`
- **THEN** a canônica aponta para a página do assunto sem parâmetros

#### Scenario: Catálogo de projetos filtrado
- **WHEN** um buscador lê `/projetos` com filtros
- **THEN** a canônica inclui os filtros aplicados

### Requirement: Sitemap XML
O sistema SHALL servir em `GET /sitemap.xml`, como `application/xml`, um sitemap no padrão sitemaps.org, com endereços absolutos no domínio da requisição e `lastmod`, `changefreq` e `priority` em cada um. Ele SHALL listar as páginas de primeiro nível do portal, as páginas de departamento (até 100, com docentes de vínculo vigente), as de assunto, área e grande área e o perfil de todos os docentes cadastrados. As páginas individuais de projeto MUST NOT constar do sitemap.

#### Scenario: Perfil de docente no sitemap
- **WHEN** um docente teve o Lattes atualizado em 2026-09-15
- **THEN** o sitemap lista `/professor/{slug}` com `lastmod` 2026-09-15 e prioridade 0.9; sem data do Lattes, vale a da última alteração do registro e, sem ela, a data atual

#### Scenario: Páginas de primeiro nível
- **WHEN** um buscador lê o sitemap
- **THEN** encontra a página inicial, Indicadores, Pesquisa Temática, Departamentos, Periódicos (geral e por base), Busca, Coautorias, Projetos, os catálogos do Explorar, Sobre, Sobre os dados, Dúvidas e Novidades

#### Scenario: Domínio da requisição
- **WHEN** o sitemap é pedido em `https://exemplo.ufscar.br/sitemap.xml`
- **THEN** todos os endereços começam por `https://exemplo.ufscar.br`

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

### Requirement: Tela de SEO no painel
O sistema SHALL oferecer em `/admin/seo/`, só a administradores, o formulário das configurações globais, com token CSRF obrigatório. Campos vazios de Google Analytics, Search Console e robots.txt SHALL apagar o valor; título, descrição, palavras-chave e URL base vazios SHALL manter o anterior. A imagem enviada MUST ser PNG, JPG, WebP ou GIF pelo conteúdo e SHALL ir para `public/uploads/seo/` com nome único. Gravar SHALL limpar o cache de páginas.

#### Scenario: Apagar o Google Analytics
- **WHEN** o administrador grava o formulário com o campo do Google Analytics vazio
- **THEN** o ID de medição é removido das configurações

#### Scenario: Título em branco
- **WHEN** o administrador grava o formulário com o título em branco
- **THEN** o título padrão anterior continua valendo

#### Scenario: Nova imagem de compartilhamento
- **WHEN** o administrador envia uma imagem no campo de imagem de compartilhamento
- **THEN** a imagem é guardada em `/uploads/seo/` e passa a ser usada em `og:image` das páginas sem imagem própria

#### Scenario: Arquivo que não é imagem
- **WHEN** o administrador envia um `.php` ou `.html` renomeado como imagem
- **THEN** nada é alterado e a tela informa os tipos aceitos

#### Scenario: Envio sem token
- **WHEN** o formulário chega sem token CSRF válido
- **THEN** nada é alterado

### Requirement: Validade de robots.txt e sitemap.xml
`/robots.txt` e `/sitemap.xml` SHALL responder com `Cache-Control: public, max-age=3600`, para que robôs e intermediários reaproveitem a resposta por uma hora.

#### Scenario: Leitura do sitemap
- **WHEN** um robô pede `/sitemap.xml`
- **THEN** a resposta traz `Cache-Control: public, max-age=3600`

#### Scenario: Leitura do robots.txt personalizado
- **WHEN** um robô pede `/robots.txt` e há conteúdo personalizado
- **THEN** a resposta traz o conteúdo personalizado e `Cache-Control: public, max-age=3600`
