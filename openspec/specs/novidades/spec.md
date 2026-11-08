# novidades Specification

## Purpose
Histórico de manutenções do portal em linguagem simples, mantido em `config/novidades.yaml`: a página `/novidades`, a dica de novidade no topo das páginas que mudaram (que some com "Entendi"), o selo "Novo" no menu e o comando que aponta os commits do git ainda sem explicação.

## Requirements
### Requirement: Arquivo de novidades como fonte única
O sistema SHALL ler o histórico de `config/novidades.yaml`, com as chaves `destaque_ate` (data limite do selo "Novo"), `novos_no_menu` (nomes de rota que recebem o selo), `dicas` (cada uma com `rotas`, `desde`, `titulo`, `antes` e `agora`) e `entradas` (cada uma com `data`, `titulo`, `resumo`, `mudancas` e `commits`, cada commit com `hash` e `texto`). Arquivo ausente MUST ser tratado como histórico vazio, sem erro. Datas SHALL ser tratadas no formato AAAA-MM-DD.

#### Scenario: Arquivo ausente
- **WHEN** `config/novidades.yaml` não existe
- **THEN** `/novidades` abre sem entradas, nenhuma página mostra dica e nenhum item do menu recebe o selo "Novo"

#### Scenario: Alteração no arquivo
- **WHEN** quem mantém o portal acrescenta uma entrada ao arquivo e publica
- **THEN** a entrada aparece em `/novidades` sem mudança de código

### Requirement: Página de novidades
O sistema SHALL servir em `GET /novidades` todas as entradas do histórico, da data mais recente para a mais antiga. Cada entrada SHALL mostrar a data (dd/mm/aaaa), o título, o resumo, a lista de mudanças e, no bloco recolhível "Ajustes no git", os commits com código e explicação, e SHALL ter a âncora `#d-{AAAA-MM-DD}`. O topo SHALL mostrar o número de atualizações e o total de ajustes no git. O menu e o rodapé das páginas públicas SHALL ter link para `/novidades`.

#### Scenario: Ordem das entradas
- **WHEN** o arquivo tem entradas de 2026-10-31 e 2026-11-07
- **THEN** a de 2026-11-07 aparece primeiro, com o selo "Mais recente"

#### Scenario: Atalhos para as datas
- **WHEN** o histórico tem mais de uma entrada
- **THEN** o topo da lista tem um atalho para a âncora de cada data

#### Scenario: Commits de uma entrada
- **WHEN** o visitante abre "Ajustes no git" de uma entrada
- **THEN** vê o código de cada commit ao lado da explicação em linguagem simples

### Requirement: Dica de novidade por rota
O sistema SHALL mostrar no topo de uma página pública a primeira dica cuja lista `rotas` contém o nome da rota da página, com "Novidade de {desde em dd/mm/aaaa}", o título, "Antes:" (só quando informado), "Agora:", o link "Ver todas as novidades" para `/novidades#d-{desde}` e os botões "Entendi" e fechar. Página cuja rota não está em nenhuma dica MUST NOT mostrar dica.

#### Scenario: Página que mudou
- **WHEN** um visitante abre `/busca` e há uma dica com a rota `app_pub_search`
- **THEN** o topo mostra a dica com "Antes:" e "Agora:"

#### Scenario: Página que não mudou
- **WHEN** um visitante abre `/periodicos` e nenhuma dica cita a rota dessa página
- **THEN** nenhuma dica aparece

### Requirement: "Entendi" esconde a dica no navegador
Ao clicar em "Entendi" ou em fechar, o sistema SHALL esconder a dica e lembrar a escolha no armazenamento local do navegador, com uma chave formada pela primeira rota da dica e pela data `desde`. A escolha SHALL valer para todas as rotas listadas na mesma dica, e a dica já dispensada MUST ficar escondida desde o carregamento da página, sem piscar. Uma dica nova (outra data `desde`) SHALL aparecer de novo.

#### Scenario: Dica dispensada em uma das rotas
- **WHEN** o visitante clica em "Entendi" na dica de `/projetos` e depois abre uma página de projeto coberta pela mesma dica
- **THEN** a dica não aparece na página do projeto

#### Scenario: Navegador sem armazenamento local
- **WHEN** o navegador bloqueia o armazenamento local
- **THEN** "Entendi" esconde a dica na página atual sem erro, e ela volta a aparecer na próxima página

### Requirement: Selo "Novo" no menu
O sistema SHALL marcar como novos, no menu das páginas públicas, os itens cuja rota está em `novos_no_menu`, enquanto a data atual for igual ou anterior a `destaque_ate`. No menu do computador a marca SHALL ser um ponto com o texto acessível "(novo)" e a dica "Novidade: veja o que mudou em Novidades"; no menu do celular, o rótulo "Novo". Sem `destaque_ate`, nenhum item SHALL receber o selo.

#### Scenario: Dentro do prazo
- **WHEN** `destaque_ate` é 2027-01-31, `app_pub_coauthorship` está em `novos_no_menu` e a data é 2026-11-08
- **THEN** o item Coautorias do menu mostra o selo

#### Scenario: Prazo vencido
- **WHEN** a data atual é posterior a `destaque_ate`
- **THEN** nenhum item do menu mostra o selo

#### Scenario: Rota fora da lista
- **WHEN** `app_pub_journals_index` não está em `novos_no_menu`
- **THEN** o item Periódicos não mostra o selo, mesmo dentro do prazo

### Requirement: Consistência do arquivo de novidades
O conteúdo de `config/novidades.yaml` MUST ser conferido pela suíte de testes: toda rota citada em `novos_no_menu` e em `dicas` existe; toda entrada tem data AAAA-MM-DD, título e ao menos uma mudança; todo commit tem código hexadecimal de 7 a 40 caracteres e explicação, e nenhum código aparece duas vezes.

#### Scenario: Rota inexistente citada
- **WHEN** uma dica cita uma rota que não existe no portal
- **THEN** a suíte de testes falha apontando a rota

### Requirement: Comando de commits pendentes
O sistema SHALL oferecer o comando `app:novidades:pendentes`, que lê o `git log` do projeto e lista em tabela (código curto, data e mensagem) os commits cujo código não começa por nenhum dos códigos registrados em `config/novidades.yaml`. Commits com assunto iniciado por `docs(novidades)` MUST ficar de fora. O comando MUST NOT alterar o arquivo; SHALL terminar com sucesso tanto quando há pendências (com aviso) quanto quando não há, e com falha quando não consegue ler o histórico do git.

#### Scenario: Commit ainda não explicado
- **WHEN** existe um commit `feat: ...` cujo código não está no arquivo
- **THEN** o comando o lista entre os pendentes

#### Scenario: Commit de registro de novidades
- **WHEN** existe um commit com assunto `docs(novidades): registra o commit X` fora do arquivo
- **THEN** o comando não o lista

#### Scenario: Tudo registrado
- **WHEN** todos os commits estão no arquivo ou são `docs(novidades)`
- **THEN** o comando informa que todos os commits estão no histórico
