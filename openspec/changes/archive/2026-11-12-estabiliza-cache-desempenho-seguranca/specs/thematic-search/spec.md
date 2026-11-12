## ADDED Requirements

### Requirement: Cache das APIs de temas no navegador
As respostas 200 a `GET` em `/api/temas/*` (autocomplete, docentes e evolução) SHALL trazer `Cache-Control: public, max-age=3600`. Respostas de erro, como o 404 de tema inexistente, MUST NOT receber esse cabeçalho.

#### Scenario: Autocomplete repetido
- **WHEN** o visitante digita o mesmo texto duas vezes na mesma hora
- **THEN** a resposta de `/api/temas/autocomplete` traz `Cache-Control: public, max-age=3600` e o navegador pode reaproveitá-la

#### Scenario: Tema inexistente
- **WHEN** a chamada é `/api/temas/docentes?term_id=999999` para um id que não existe
- **THEN** a resposta 404 não traz `max-age=3600`
