# Site existente
Vamos criar um novo projeto baseado num site existente.
Trata-se do projeto "Somos" da UFMG

Então, para entender completamente onde queremos chegar, preciso que vc analise todos estes dados e e entenda como montar o sistema de navegação entre os curriculos dos docentes/pesquisadores.

https://somos.ufmg.br/
https://somos.ufmg.br/professor/gilberto-de-lima-guimaraes
https://somos.ufmg.br/indicadores
https://somos.ufmg.br/unidade/faculdade-de-medicina
https://somos.ufmg.br/professor/laura-de-souza-cota-carvalho-silva-pinto
https://somos.ufmg.br/departamento/arq-departamento-de-tecnologia-do-design-da-arquitetura-e-do-urbanismo
https://somos.ufmg.br/unidade/escola-de-arquitetura

Depois de montar toda esta estrutura, vc deve organizar o banco de dados de curriculos.
Todos os gráficos com seus filtros e tudo mais.
Precisa ter, no mínimo, todas as funcionalidades disponíveis na versão atual.

Este novo painel será público, sem necessidade de se logar.

## Novas fincionalidades
Preciso que, pelo link dos DOIs, seja possível abrir em outra aba os trabalhos citados de cada curriculo


# Tema (CSS)
inspire-se no tema do projeto
~/work/bibliomap
modo claro/escuro, etc. Veja toda a área administrativa.


# Estruturação do novo banco
Primeiro, vc deve entender a estrutura do currículo.
Você deve ler os XMLs desta pasta: docs/banco/CECH e entender o padrão entre eles.
Depois que tiver entendido o padrão, vamos precisar montar um command para importar todos eles.
Você deve rodar este command e importar todos.
Depois poder ser capaz de ver se existe, se existe atualiza, se não existe insere. Deve poder ler uma pasta ou um arquivo só.

## Outro modelo
este local possui os mesmos dados de outra maneira, normatizados.
docs/banco/items_cech.json
Então, vc deve analisar a estrutura vinda do XML e entender como ele pode ser esquematizado para ser padronizado e virar os dados como no JSON. Padronizar nome de instituição, etc. 
Vamos usar Tesaurus para fazer isso, vc precisa aprender.
Precisa prever a existencia de importadores e exportadores de tesausoros para intituições, países, autores.
Neste mesmo computador tem um outro projeto que trabalha com tesauros, em  ~/work/bibliomap
veja os campos das instituições, autores e países.
Aproveite para importar de lá e popular o banco novo daqui.


# Administrativo
Este sistema deve ter um painel administrativo para importar novos XML, exportar json filtrado, etc
Deve ter crud de usuários para definir o que cada um pode fazer
Deve ter crud de curriculos com diversos filtros para localizar e gerar csv, json, xml ou pdf.
Deve ter crud de países, com os dados dos paises de  ~/work/bibliomap com importar e exportar para xml, csv, tesauros
Deve ter crud de instituições, com os dados das instituições de  ~/work/bibliomap com importar e exportar para xml, csv, tesauros
Deve ter crud de autores, também com tesauros. Veja os campos em ~/work/bibliomap/api/author.php e importe os dados do tesauros de la

deve ser possível exportar e importar curriculos. São grandes, pense na melhor maneira de se fazer isso.

## CRUDs
Preciso dos cruds com todas as funcionalidades necessários já destde o início.
Todas as formas de se localizar, exportar, importar, mesclar, etc funcionando.
Todos os botões de editar/novo/excluir, etc devem ser padronizados e estarem prontos desde o início.

## recursos
Este novo sistema deve ter alguns relatórios na área administrativa, veja o que vc pode deduzir de relatórios extraídos de  docs/banco/2026-08-23 - Info docentes do CECH.xlsx e de docs/banco/2026-08-23 - Producao cientifica-tecnologica-cultura.xlsx

# Diálogos
Não quero perguntas com alert.
Veja em  ~/work/bibliomap como as perguntas são feitas ao usuários com caixas de diálogos com html mesmo. Use isso.

# Finalizado
Preciso que todo o painel administrativo, dashboard, esteja pronto e util. com todas as funcionalidades.
Preciso do banco criado, estruturado, populado com os dados dos xml e dos xlsx. 
Preciso dos tesauros completos, importados e exportados, com importadores e exportadores para xml, csv, json e tesauros.
Preciso dos usuarios criados.
Preciso que tudo esteja pronto e operacional.
Preciso dos migrations para tudo isso poder ser recriado em outra maquina.
