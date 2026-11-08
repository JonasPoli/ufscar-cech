## ADDED Requirements

### Requirement: Limpar a busca de temas
Ao apagar o texto da busca de `/temas` ou clicar no X, o sistema SHALL voltar à nuvem de temas em destaque e ao título "Temas Frequentes em Destaque", mantendo destacado o tema selecionado.

#### Scenario: Busca apagada
- **WHEN** o visitante pesquisa "leitura" e depois clica no X
- **THEN** a nuvem volta a mostrar os temas em destaque, sem erro na página
