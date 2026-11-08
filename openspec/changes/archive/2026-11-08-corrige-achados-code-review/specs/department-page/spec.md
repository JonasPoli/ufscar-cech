## MODIFIED Requirements

### Requirement: Corpo docente
A página SHALL listar, em ordem alfabética, todos os docentes do departamento, com vínculo vigente ou encerrado, juntando as grafias equivalentes do código e do nome (por exemplo, `CS` e `DCSo`), cada um com foto ou iniciais, nome, número de produções declaradas e link para o perfil. Quando há ano de início ou de fim do vínculo, o cartão MUST mostrar "Desde {ano de início}" para quem está ativo no CECH e, para os demais, "{início}–{fim}" (com "Saída" no lugar do fim ausente) ou "Histórico" quando falta o início.

#### Scenario: Docente com vínculo encerrado
- **WHEN** um docente do departamento teve vínculo de 1995 a 2018
- **THEN** ele aparece na lista com "1995–2018" em destaque

#### Scenario: Código alternativo
- **WHEN** há docentes gravados como `CS` e outros como `DCSo` e alguém abre `/departamento/CS`
- **THEN** todos aparecem na lista
