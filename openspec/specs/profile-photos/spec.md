# profile-photos Specification

## Purpose
Manter a foto de cada docente, obtida da plataforma Lattes, enviada pelo administrador ou importada em lote, guardada com o ID Lattes como nome e acompanhada de versões WebP leves para as páginas públicas.

## Requirements
### Requirement: Gravação da foto do docente
O sistema SHALL aceitar como foto apenas imagens JPEG, PNG, GIF ou WebP com pelo menos 600 bytes, convertendo GIF e WebP para JPEG, e SHALL gravá-la em `public/uploads/photos/<ID Lattes>.jpg` (ou `.png`), apontando a foto do docente para `/uploads/photos/<ID Lattes>.<extensão>`.

#### Scenario: Página de erro no lugar da imagem
- **WHEN** o conteúdo recebido é uma página HTML ou tem menos de 600 bytes
- **THEN** nada é gravado e a foto do docente não muda

#### Scenario: Foto em WebP
- **WHEN** a imagem recebida é WebP
- **THEN** ela é gravada como `<ID Lattes>.jpg`

### Requirement: Versões WebP da foto
Ao gravar uma foto, o sistema SHALL gerar `<ID Lattes>.webp` (qualidade 80) e `<ID Lattes>-256.webp` (lado maior de até 256 pixels, qualidade 82), e SHALL oferecer o comando `app:photos:generate-webp`, que gera essas versões para todas as fotos JPEG e PNG da pasta, pulando as que já têm as duas versões, salvo com `--force`.

#### Scenario: Geração em lote
- **WHEN** o comando roda sem `--force` e uma foto já tem as duas versões WebP
- **THEN** essa foto é pulada e o resumo conta só as versões geradas

#### Scenario: PHP sem suporte a WebP
- **WHEN** o PHP não tem suporte a WebP na extensão GD
- **THEN** o comando termina com erro sem processar nenhuma foto

### Requirement: Busca automática da foto no CNPq
O sistema SHALL tentar obter a foto oficial de um docente resolvendo o K-ID a partir da página `lattes.cnpq.br/<ID Lattes>` e consultando os serviços de foto do CNPq, primeiro pelo K-ID e depois pelo ID Lattes, gravando a primeira resposta que for uma imagem válida.

#### Scenario: Proteção contra robôs
- **WHEN** nenhum serviço devolve uma imagem válida
- **THEN** a foto do docente não muda
- **AND** o comando e o botão de busca do painel avisam que a foto não pôde ser obtida

#### Scenario: Comando de busca em lote
- **WHEN** `app:crawl:lattes-photos` roda sem `--id`
- **THEN** são consultados os docentes sem foto (até 50, ou todos com `--all`), com uma pausa de 200 ms entre eles, e o resumo informa quantas fotos foram baixadas

#### Scenario: Comando para um docente
- **WHEN** `app:crawl:lattes-photos --id=<ID Lattes ou slug>` aponta para um docente inexistente
- **THEN** o comando termina com erro

### Requirement: Foto pelo painel administrativo
O sistema SHALL permitir a administradores enviar a foto de um docente (`POST /admin/curriculum/{id}/photo`), pedir a busca da foto oficial de um docente (`POST /admin/curriculum/{id}/crawl-photo`) ou de todos os docentes sem foto (`POST /admin/curriculum/sync-all-photos`), buscar a foto ao cadastrar um docente em `/admin/curriculum/new` (opção marcada por padrão) e gravar uma foto recebida em base64 (`POST /admin/curriculum/api/quick-photo`, com `idLattes` e `photoData`).

#### Scenario: Arquivo enviado inválido
- **WHEN** o administrador envia um arquivo que não é imagem JPEG, PNG, GIF ou WebP válida
- **THEN** a tela mostra o erro e a foto não muda

#### Scenario: Opção de buscar foto desmarcada
- **WHEN** o cadastro em `/admin/curriculum/new` é enviado com a opção de buscar a foto desmarcada
- **THEN** a foto oficial não é buscada e a foto existente não é trocada

#### Scenario: Foto em base64 para docente inexistente
- **WHEN** `quick-photo` recebe um ID Lattes que não pertence a nenhum docente
- **THEN** a resposta é JSON com `success: false` e status 404; parâmetros ausentes dão 400

### Requirement: Importação de fotos em lote
O sistema SHALL oferecer o comando `app:import:photos` com `--dir` (pasta) ou `--zip` (arquivo ZIP), que liga cada imagem `jpg`, `jpeg`, `png` ou `webp` da pasta ao docente cujo ID Lattes, slug ou nome normalizado (com `_` e `-` lidos como espaço) é o nome do arquivo, grava a foto como as demais e termina com falha quando algum arquivo que corresponde a um docente não pôde ser gravado.

#### Scenario: Arquivo sem docente correspondente
- **WHEN** o nome do arquivo não corresponde a nenhum docente
- **THEN** o arquivo é contado como sem correspondência e o comando segue

#### Scenario: Imagem inválida de um docente
- **WHEN** o arquivo corresponde a um docente mas não é imagem válida
- **THEN** ele é listado como não importado e o comando termina com código de falha

#### Scenario: Nenhuma origem informada
- **WHEN** o comando roda sem `--dir` e sem `--zip`
- **THEN** ele termina com erro pedindo uma das duas opções
