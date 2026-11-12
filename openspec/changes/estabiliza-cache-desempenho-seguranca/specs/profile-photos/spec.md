## MODIFIED Requirements

### Requirement: Busca automática da foto no CNPq
O sistema SHALL tentar obter a foto oficial de um docente resolvendo o K-ID a partir da página `lattes.cnpq.br/<ID Lattes>` e consultando os serviços de foto do CNPq, primeiro pelo K-ID e depois pelo ID Lattes, gravando a primeira resposta que for uma imagem válida. As consultas SHALL verificar o certificado TLS, usar só HTTPS (inclusive nos redirecionamentos) e seguir no máximo 3 redirecionamentos.

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

#### Scenario: Certificado inválido ou redirecionamento para HTTP
- **WHEN** o serviço apresenta um certificado que não passa na verificação, ou redireciona para um endereço `http://`
- **THEN** a consulta é recusada e nada é gravado

#### Scenario: Redirecionamentos demais
- **WHEN** a resposta encadeia mais de 3 redirecionamentos
- **THEN** a consulta termina sem imagem
