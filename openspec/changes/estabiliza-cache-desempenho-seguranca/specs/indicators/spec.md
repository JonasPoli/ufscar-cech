## ADDED Requirements

### Requirement: Resumo global guardado
Os números globais do portal, usados na página inicial, em `/indicadores` e em `/sobre-os-dados`, SHALL ser calculados uma vez e guardados no cache da pesquisa por até um dia. Eles MUST ser recalculados depois que o cache de páginas públicas é limpo.

#### Scenario: Páginas diferentes no mesmo dia
- **WHEN** um visitante abre a página inicial e outro abre `/indicadores` no mesmo dia, ambas fora do cache de páginas
- **THEN** o resumo é calculado na primeira visita e reaproveitado na segunda, com os mesmos números

#### Scenario: Dados reimportados
- **WHEN** currículos são reimportados e o administrador roda `app:cache:clear`
- **THEN** a próxima página que mostra os números globais recalcula o resumo com os dados novos

### Requirement: Pares de coautoria calculados uma vez na aba de redes
Ao montar a aba de redes, o sistema SHALL calcular uma única vez a lista de pares de coautoria da Figura 16 e tirar dela os 8 pares do destaque, o total de conexões e a matriz. No empate de publicações em comum, os pares SHALL seguir a ordem de cadastro dos docentes.

#### Scenario: Destaque e matriz coerentes
- **WHEN** a aba de redes é montada
- **THEN** os 8 pares do destaque são os 8 primeiros da lista completa, na mesma ordem, e a consulta dos pares roda uma vez

#### Scenario: Versão completa
- **WHEN** a pessoa abre `/indicadores?tab=all`
- **THEN** a Figura 16 mostra os mesmos pares, o mesmo total e a mesma matriz da aba de redes
