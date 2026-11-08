## MODIFIED Requirements

### Requirement: Destino dos egressos
A aba de corpo docente SHALL trazer a Figura 4 para os docentes com vínculo encerrado ou ano de saída anterior ao ano corrente. O destino MUST ser a experiência profissional fora da UFSCar e de São Carlos, sem as editoriais ou de periódicos, preferindo a atual, a posterior à saída e a de vínculo funcional, com o endereço profissional do Lattes como alternativa. A figura SHALL mostrar o fluxo departamento → categoria → instituição, o ranking de destinos, a tabela e o resumo.

#### Scenario: Números de resumo
- **WHEN** a figura é exibida
- **THEN** ela mostra quantos egressos têm destino mapeado sobre o total, a parcela em ensino superior público e a parcela fora do estado ou no exterior

#### Scenario: Egresso sem experiência fora da UFSCar
- **WHEN** um docente com vínculo encerrado só tem experiências na UFSCar e não informa endereço profissional fora dela
- **THEN** ele aparece na tabela como "Não mapeado / Aposentado" e não conta no total mapeado

#### Scenario: Virada do ano
- **WHEN** em 2027 um docente ainda marcado como vigente tem ano de saída 2026
- **THEN** ele entra nos egressos, como no perfil, onde já aparece como inativo
