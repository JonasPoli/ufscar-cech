## MODIFIED Requirements

### Requirement: Modo Teses e orientações
No modo `orientacoes`, o sistema SHALL listar orientações cujo título, título alternativo, nome do orientando ou palavras-chave contenham o texto, 15 por página, das mais recentes para as mais antigas, aplicando também tema, `departamento`, `grande_area`, `area` e `vinculo` do orientador e o período pelo ano. O tipo de publicação MUST NOT se aplicar e fica desativado no formulário. Cada orientação SHALL mostrar tipo, ano, situação, título, orientando, curso, orientador e o link do Repositório quando houver.

#### Scenario: Tese encontrada pela palavra-chave
- **WHEN** o visitante busca uma palavra-chave de uma dissertação no modo Teses e orientações
- **THEN** a dissertação aparece com o orientando, o orientador e, se houver, o link do repositório

#### Scenario: Período e vínculo
- **WHEN** o visitante busca no modo Teses e orientações com `de=2020` e `vinculo=vigente`
- **THEN** só aparecem orientações de 2020 em diante, de orientadores com vínculo vigente
