## MODIFIED Requirements

### Requirement: Catálogo de grandes áreas
O sistema SHALL listar em `/explorar/grandes-areas` as grandes áreas do CNPq presentes nas áreas de atuação dos docentes, juntando as grafias por código e por extenso antes de contar, cada uma com o nome, o número de docentes, o número de publicações únicas desses docentes, a parcela em relação à soma das publicações das grandes áreas listadas ("% da produção") e o link para `/explorar/grande-area/{slug}`, da que tem mais publicações para a que tem menos.

#### Scenario: Grafia por extenso
- **WHEN** um registro traz a grande área "Ciências Agrárias" por extenso e outro traz `CIENCIAS_AGRARIAS`
- **THEN** o catálogo mostra uma só grande área "Ciências Agrárias"

#### Scenario: Docente com as duas grafias
- **WHEN** um docente tem uma área gravada como `CIENCIAS_HUMANAS` e outra como "Ciências Humanas"
- **THEN** ele conta uma vez em Ciências Humanas, e as publicações dele também
