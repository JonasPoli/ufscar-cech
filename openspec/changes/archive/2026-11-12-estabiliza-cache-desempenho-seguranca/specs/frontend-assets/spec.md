## Purpose

Controlar os recursos de terceiros carregados pelas páginas do portal (scripts, estilos e fontes): versão exata, verificação de integridade e carga só onde são usados, para que uma troca no servidor do fornecedor não altere o portal nem execute código não verificado.

## ADDED Requirements

### Requirement: Recursos de terceiros com versão exata e integridade
Todo script e folha de estilo de terceiros carregado por CDN SHALL apontar para uma versão exata, nunca para `latest` nem para uma faixa, e SHALL trazer `integrity` (SHA-384) com `crossorigin`. Exceção: os módulos que o autoloader do Shoelace importa em tempo de execução, que ficam sem `integrity`.

#### Scenario: Script de gráfico
- **WHEN** uma página carrega Chart.js, D3, d3-sankey, ECharts, jQuery ou DataTables
- **THEN** o endereço traz a versão exata (4.4.7, 7.9.0, 0.12.3, 5.5.1, 3.7.1 e 1.13.7) e o atributo `integrity` com `sha384-`

#### Scenario: Shoelace
- **WHEN** uma página carrega o autoloader e as folhas `light.css` e `dark.css` do Shoelace
- **THEN** os três usam a versão 2.19.1 e trazem `integrity`

#### Scenario: Recurso adulterado
- **WHEN** o servidor do fornecedor devolve um arquivo diferente do verificado
- **THEN** o navegador recusa executá-lo

### Requirement: Script do grafo só onde há rede de coautoria
O script `graph.js` MUST ser carregado apenas nas páginas que mostram a rede de coautoria: perfil do docente, departamento, `/coautorias` e o escopo do Explorar. Ele SHALL entrar pelo bloco `graph_script` do layout, que as outras páginas deixam vazio.

#### Scenario: Página com rede
- **WHEN** um visitante abre `/coautorias`
- **THEN** o HTML inclui `graph.js`

#### Scenario: Página sem rede
- **WHEN** um visitante abre a página inicial
- **THEN** o HTML não inclui `graph.js`

### Requirement: Imagens abaixo da dobra
As imagens fora da primeira tela SHALL ter `loading="lazy"` e dimensões (`width` e `height`) declaradas, para não atrasar a página nem deslocar o conteúdo.

#### Scenario: Foto em lista
- **WHEN** uma lista mostra fotos de docentes abaixo da primeira tela
- **THEN** cada `img` traz `loading="lazy"`, `width` e `height`
