## ADDED Requirements

### Requirement: HTML do perfil independente do filtro
Fora de `aba=busca`, o HTML do perfil enviado pelo servidor MUST ser o mesmo para qualquer valor de `tema`, `keyword` ou `q`. O preenchimento da caixa de busca e a escolha da aba descritos no filtro global do perfil SHALL ser feitos pelo navegador, a partir da URL.

#### Scenario: Dois temas para o mesmo docente
- **WHEN** o servidor gera `/professor/maria-silva?tema=letramento` e `/professor/maria-silva?tema=ensino`
- **THEN** os dois HTML são iguais ao de `/professor/maria-silva`

#### Scenario: Caixa preenchida no navegador
- **WHEN** o visitante abre `/professor/maria-silva?tema=letramento` com JavaScript ligado
- **THEN** a caixa de busca mostra "letramento" e os itens aparecem filtrados, como antes

#### Scenario: Perfil aberto pela busca
- **WHEN** o visitante abre `/professor/maria-silva?aba=busca&q=alfabetização`
- **THEN** o servidor monta a aba "Publicações da busca" com o filtro, como antes
