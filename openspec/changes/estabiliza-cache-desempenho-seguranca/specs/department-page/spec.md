## ADDED Requirements

### Requirement: Contagem das produções do corpo docente
O número de produções de cada cartão do corpo docente SHALL vir de uma única consulta agregada para todos os docentes listados. A página MUST NOT carregar as produções de cada docente para contá-las, e os números SHALL ser os mesmos de antes: os registros de produção declarados no currículo de cada docente.

#### Scenario: Departamento grande
- **WHEN** a página de um departamento com mais de 100 docentes é aberta fora do cache
- **THEN** as contagens de todos os cartões saem de uma consulta só, sem carregar as produções de nenhum docente

#### Scenario: Docente sem produção
- **WHEN** um docente do departamento não tem nenhuma produção registrada
- **THEN** o cartão dele mostra zero produções
