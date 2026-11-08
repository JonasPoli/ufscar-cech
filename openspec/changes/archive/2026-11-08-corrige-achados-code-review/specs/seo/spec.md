## MODIFIED Requirements

### Requirement: Tela de SEO no painel
O sistema SHALL oferecer em `/admin/seo/`, só a administradores, o formulário das configurações globais, com token CSRF obrigatório. Campos vazios de Google Analytics, Search Console e robots.txt SHALL apagar o valor; título, descrição, palavras-chave e URL base vazios SHALL manter o anterior. A imagem enviada MUST ser PNG, JPG, WebP ou GIF pelo conteúdo e SHALL ir para `public/uploads/seo/` com nome único. Gravar SHALL limpar o cache de páginas.

#### Scenario: Apagar o Google Analytics
- **WHEN** o administrador grava o formulário com o campo do Google Analytics vazio
- **THEN** o ID de medição é removido das configurações

#### Scenario: Título em branco
- **WHEN** o administrador grava o formulário com o título em branco
- **THEN** o título padrão anterior continua valendo

#### Scenario: Nova imagem de compartilhamento
- **WHEN** o administrador envia uma imagem no campo de imagem de compartilhamento
- **THEN** a imagem é guardada em `/uploads/seo/` e passa a ser usada em `og:image` das páginas sem imagem própria

#### Scenario: Arquivo que não é imagem
- **WHEN** o administrador envia um `.php` ou `.html` renomeado como imagem
- **THEN** nada é alterado e a tela informa os tipos aceitos

#### Scenario: Envio sem token
- **WHEN** o formulário chega sem token CSRF válido
- **THEN** nada é alterado
