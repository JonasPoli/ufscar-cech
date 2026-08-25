<?php

namespace App\Command;

use Doctrine\ORM\EntityManagerInterface;
use phpDocumentor\Reflection\Types\Void_;
use PHPUnit\TextUI\XmlConfiguration\CodeCoverage\Report\Php;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Finder\Finder;

#[AsCommand(
    name: 'app:generate-index',
    description: 'Generates or modifies an index.html.twig for a given entity.',
)]
class GenerateIndexCommand extends Command
{
    private const JSON_FILE_DIR = 'var';

    // Add a property to store the selected entity
    private string $entityClass;

    private string $entityHumanName;
    private string $entityRouteName;
    private array $formTypes = [];
    private array $enum = [];
    private $className;
    private $classNameCamelCase;
    private $controllerFilePath;

    private $templatePath;
    private EntityManagerInterface $entityManager;

    private ParameterBagInterface $parameterBag;

    private $io;

    public function __construct(EntityManagerInterface $entityManager, ParameterBagInterface $parameterBag)
    {
        parent::__construct();
        $this->entityManager = $entityManager;
        $this->parameterBag = $parameterBag;;
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Cria modificações nos Cruds feitos automaticamente pelo Symfony')
            ->setHelp('This command allows you to configure field visibility and labels for an index listing.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {

        $io = new SymfonyStyle($input, $output);
        $this->io = $io;

        $this->formTypes[1] = 'Simple Input Text';
        $this->formTypes[2] = 'Edit Text with editor';
        $this->formTypes[3] = 'Date';
        $this->formTypes[4] = 'Date and Time';
        $this->formTypes[5] = 'imageFile';
        $this->formTypes[6] = 'ChoiceType from list';
        $this->formTypes[7] = 'ChoiceType from entity';
        $this->formTypes[8] = 'Sim/Não int => 1/0';
        $this->formTypes[9] = 'ID';
        $this->formTypes[10] = 'Boolean (True/False)';
        $this->formTypes[11] = 'Imagem (imageFile + imageName + updatedAt)';
        $this->formTypes[12] = 'Ignore (não aparece nem na lista, nem no form)';
        $this->formTypes[13] = 'SelectBox de um Enum';
        $this->formTypes[14] = 'Texto com Editor';
        $this->formTypes[15] = 'E-mail';
        $this->formTypes[16] = 'Número Simples';
        $this->formTypes[17] = 'SelectBox de uma outra Entity';
        $this->formTypes[18] = 'PDF (File + fileName + updatedAt)';
        $this->formTypes[19] = 'Galeria de Imagens';


        // Step 1: Select entity
        // List all entities
        $entityClasses = $this->getAllEntities();

        if (empty($entityClasses)) {
            $io->error('No entities found in the project.');
            return Command::FAILURE;
        }

        // Display entities as a numbered list
        $io->section('Available Entities');
        foreach ($entityClasses as $index => $entityClass) {
            $io->writeln(sprintf('%d. %s', $index + 1, $entityClass));
        }

        // Prompt user to select an entity by number
        $entityNumber = $io->ask('Enter the number of the entity you want to modify', null, function ($value) use ($entityClasses) {
            if (!is_numeric($value) || $value < 1 || $value > count($entityClasses)) {
                throw new \RuntimeException('Invalid number. Please select a valid entity number.');
            }
            return (int)$value;
        });

        // Store the selected entity in the property
        $this->entityClass = $entityClasses[$entityNumber - 1];
        $io->success(sprintf('Você selecionou: %s', $this->entityClass));

        // Proceed with the rest of the command logic
        $metadata = $this->entityManager->getClassMetadata($this->entityClass);
        $entityFields = $metadata->getFieldNames();


        // Load or create configuration
        $this->templatePath = $this->selectTemplate($io);
        $config = $this->loadOrCreateConfig($entityFields, $io);

        // modify contoller
        $this->modifyController($config);

        // Modify the Twig template
        $templateContent = file_get_contents($this->templatePath);
        $newContent = $this->modifyIndex($templateContent, $config);
        file_put_contents($this->templatePath, $newContent);


        // Encontrar e modificar arquivos irmãos

        if (!file_exists($this->templatePath)) {
            $io->error(sprintf('Template file %s not found.', $this->templatePath));
            return Command::FAILURE;
        }

        $siblings = $this->locateSiblingTemplates($this->templatePath);

        if (isset($siblings['_delete_form.html.twig'])) {
            $this->modifyDeleteFormTemplate($siblings['_delete_form.html.twig']);
            $io->success('Modified _delete_form.html.twig successfully.');
        } else {
            $io->warning('_delete_form.html.twig not found.');
        }

        if (isset($siblings['edit.html.twig'])) {
            $this->modifyEditTemplate($siblings['edit.html.twig']);
            $io->success('Modified edit.html.twig successfully.');
        } else {
            $io->warning('edit.html.twig not found.');
        }

        if (isset($siblings['new.html.twig'])) {
            $this->modifyNewTemplate($siblings['new.html.twig']);
            $io->success('Modified new.html.twig successfully.');
        } else {
            $io->warning('new.html.twig not found.');
        }

        if (isset($siblings['_form.html.twig'])) {
            $this->modifyFormTemplate($siblings['_form.html.twig'], $config);
            $io->success('Modified _form.html.twig successfully.');
        } else {
            $io->warning('_form.html.twig not found.');
        }

        $this->editBaseTemplate('templates/admin/base.html.twig');


        $io->success('Index file updated successfully!');
        return Command::SUCCESS;
    }


//     ▄▄▄▄▄▄▄▄▄▄▄  ▄▄▄▄▄▄▄▄▄▄▄  ▄▄        ▄  ▄▄▄▄▄▄▄▄▄▄▄  ▄▄▄▄▄▄▄▄▄▄▄  ▄▄▄▄▄▄▄▄▄▄▄  ▄            ▄            ▄▄▄▄▄▄▄▄▄▄▄  ▄▄▄▄▄▄▄▄▄▄▄
//    ▐░░░░░░░░░░░▌▐░░░░░░░░░░░▌▐░░▌      ▐░▌▐░░░░░░░░░░░▌▐░░░░░░░░░░░▌▐░░░░░░░░░░░▌▐░▌          ▐░▌          ▐░░░░░░░░░░░▌▐░░░░░░░░░░░▌
//    ▐░█▀▀▀▀▀▀▀▀▀ ▐░█▀▀▀▀▀▀▀█░▌▐░▌░▌     ▐░▌ ▀▀▀▀█░█▀▀▀▀ ▐░█▀▀▀▀▀▀▀█░▌▐░█▀▀▀▀▀▀▀█░▌▐░▌          ▐░▌          ▐░█▀▀▀▀▀▀▀▀▀ ▐░█▀▀▀▀▀▀▀█░▌
//    ▐░▌          ▐░▌       ▐░▌▐░▌▐░▌    ▐░▌     ▐░▌     ▐░▌       ▐░▌▐░▌       ▐░▌▐░▌          ▐░▌          ▐░▌          ▐░▌       ▐░▌
//    ▐░▌          ▐░▌       ▐░▌▐░▌ ▐░▌   ▐░▌     ▐░▌     ▐░█▄▄▄▄▄▄▄█░▌▐░▌       ▐░▌▐░▌          ▐░▌          ▐░█▄▄▄▄▄▄▄▄▄ ▐░█▄▄▄▄▄▄▄█░▌
//    ▐░▌          ▐░▌       ▐░▌▐░▌  ▐░▌  ▐░▌     ▐░▌     ▐░░░░░░░░░░░▌▐░▌       ▐░▌▐░▌          ▐░▌          ▐░░░░░░░░░░░▌▐░░░░░░░░░░░▌
//    ▐░▌          ▐░▌       ▐░▌▐░▌   ▐░▌ ▐░▌     ▐░▌     ▐░█▀▀▀▀█░█▀▀ ▐░▌       ▐░▌▐░▌          ▐░▌          ▐░█▀▀▀▀▀▀▀▀▀ ▐░█▀▀▀▀█░█▀▀
//    ▐░▌          ▐░▌       ▐░▌▐░▌    ▐░▌▐░▌     ▐░▌     ▐░▌     ▐░▌  ▐░▌       ▐░▌▐░▌          ▐░▌          ▐░▌          ▐░▌     ▐░▌
//    ▐░█▄▄▄▄▄▄▄▄▄ ▐░█▄▄▄▄▄▄▄█░▌▐░▌     ▐░▐░▌     ▐░▌     ▐░▌      ▐░▌ ▐░█▄▄▄▄▄▄▄█░▌▐░█▄▄▄▄▄▄▄▄▄ ▐░█▄▄▄▄▄▄▄▄▄ ▐░█▄▄▄▄▄▄▄▄▄ ▐░▌      ▐░▌
//    ▐░░░░░░░░░░░▌▐░░░░░░░░░░░▌▐░▌      ▐░░▌     ▐░▌     ▐░▌       ▐░▌▐░░░░░░░░░░░▌▐░░░░░░░░░░░▌▐░░░░░░░░░░░▌▐░░░░░░░░░░░▌▐░▌       ▐░▌
//     ▀▀▀▀▀▀▀▀▀▀▀  ▀▀▀▀▀▀▀▀▀▀▀  ▀        ▀▀       ▀       ▀         ▀  ▀▀▀▀▀▀▀▀▀▀▀  ▀▀▀▀▀▀▀▀▀▀▀  ▀▀▀▀▀▀▀▀▀▀▀  ▀▀▀▀▀▀▀▀▀▀▀  ▀         ▀
//

    private function modifyController(array $config): void
    {
        echo PHP_EOL.PHP_EOL.'Realizando ajustes no Controller...'.PHP_EOL;
        echo 'Ajustes serão realizados na classe '.$this->className.PHP_EOL;

        echo 'Se for CamelCase, '.$this->classNameCamelCase.PHP_EOL;
        //dump($this->classNameCamelCase);

        // Get the full path to the controller
        $projectDir = $this->parameterBag->get('kernel.project_dir');
        //dump($projectDir);
        $controllerFullPath = $projectDir . '/src/' . $this->controllerFilePath;

        // Check if the file exists
        if (file_exists($controllerFullPath)) {
            echo "Controller encontrado em: {$controllerFullPath}".PHP_EOL;
        } else {
            echo "Controller Não encontrado em: {$controllerFullPath}".PHP_EOL;
            exit;
        }

        $content = file_get_contents($controllerFullPath);


        foreach ($config['fields'] as $field => $fieldConfig) {


            if ($this->formTypes[$fieldConfig['formType']] == 'Galeria de Imagens') {
                echo 'Ajustando o Controller para gerenciar a galeria de imagens em '.$this->className.'.'.$fieldConfig['name'].PHP_EOL;
                // Modificação para a rota "new"
                $stringToAdd = "
            foreach(\$form->get(\"images\") as \$doc){
                \$doc->getData()->set".$this->className."(\$".$this->classNameCamelCase.");
            }
        ";
                $content = $this->inserirTexto($content,$stringToAdd,'#[Route(\'/new','$entityManager->persist(');



                // Modificação para a rota "edit"
                $stringToAdd = "
        \$originalImages = new ArrayCollection();
        foreach(\$".$this->classNameCamelCase."->getImages() as \$prevI){
            \$originalImages->add(\$prevI);
        }
        ";
                $content = $this->inserirTexto($content,$stringToAdd,'#[Route(\'/{id}/edit\',','$form = $this->createForm(');




                // Modificação para a rota "edit"
                $stringToAdd = "
            foreach(\$form->get(\"images\") as \$doc){
                \$doc->getData()->set".$this->className."(\$".$this->classNameCamelCase.");
            }
            foreach (\$originalImages as \$oi) {
                if (false === \$".$this->classNameCamelCase."->getImages()->contains(\$oi)) {
                    \$".$this->classNameCamelCase."->removeImage(\$oi);
                    \$entityManager->remove(\$oi);
                }
            }
            ";
                $content = $this->inserirTexto($content,$stringToAdd,'#[Route(\'/{id}/edit\',','$entityManager->flush();');

                //dump($content);


                // Garantir a inclusão do `use` para ChoiceType
                if (!str_contains($content, "use Doctrine\Common\Collections\ArrayCollection;")) {
                    $content = str_replace(
                        "namespace App\Controller\admin;\n\n",
                        "namespace App\Controller\admin;\n\nuse Doctrine\Common\Collections\ArrayCollection;\n",
                        $content
                    );
                }

            }

        }


        // Salvar as alterações no arquivo
        file_put_contents($controllerFullPath, $content);


    }

    //
    // ▄▄▄▄▄▄▄▄▄▄▄   ▄▄        ▄  ▄▄▄▄▄▄▄▄▄▄   ▄▄▄▄▄▄▄▄▄▄▄  ▄       ▄
    //▐░░░░░░░░░░░▌ ▐░░▌      ▐░▌▐░░░░░░░░░░▌ ▐░░░░░░░░░░░▌▐░▌     ▐░▌
    // ▀▀▀▀█░█▀▀▀▀  ▐░▌░▌     ▐░▌▐░█▀▀▀▀▀▀▀█░▌▐░█▀▀▀▀▀▀▀▀▀  ▐░▌   ▐░▌
    //    ▐░▌       ▐░▌▐░▌    ▐░▌▐░▌       ▐░▌▐░▌            ▐░▌ ▐░▌
    //    ▐░▌       ▐░▌ ▐░▌   ▐░▌▐░▌       ▐░▌▐░█▄▄▄▄▄▄▄▄▄    ▐░▐░▌
    //    ▐░▌       ▐░▌  ▐░▌  ▐░▌▐░▌       ▐░▌▐░░░░░░░░░░░▌    ▐░▌
    //    ▐░▌       ▐░▌   ▐░▌ ▐░▌▐░▌       ▐░▌▐░█▀▀▀▀▀▀▀▀▀    ▐░▌░▌
    //    ▐░▌       ▐░▌    ▐░▌▐░▌▐░▌       ▐░▌▐░▌            ▐░▌ ▐░▌
    // ▄▄▄▄█░█▄▄▄▄  ▐░▌     ▐░▐░▌▐░█▄▄▄▄▄▄▄█░▌▐░█▄▄▄▄▄▄▄▄▄  ▐░▌   ▐░▌
    //▐░░░░░░░░░░░▌ ▐░▌      ▐░░▌▐░░░░░░░░░░▌ ▐░░░░░░░░░░░▌▐░▌     ▐░▌
    // ▀▀▀▀▀▀▀▀▀▀▀   ▀        ▀▀  ▀▀▀▀▀▀▀▀▀▀   ▀▀▀▀▀▀▀▀▀▀▀  ▀       ▀
    //
    private function modifyIndex(string $template, array $config): string
    {
        //dump($config);
        // Detect paths for Create New and Edit
        preg_match('/<a href="{{ path\((.*?)\) }}">Create new<\/a>/', $template, $newPathMatch);
        $createPath = $newPathMatch[1] ?? '';

        preg_match('/<a href="{{ path\((.*?),.*?}}">edit<\/a>/', $template, $editPathMatch);
        $editPath = $editPathMatch[1] ?? '';

        // Detect variables in the FOR loop
        preg_match('/{% for (\w+) in (\w+) %}/', $template, $forMatch);
        $loopVariable = $forMatch[1] ?? null; // Variable inside the loop (e.g., "warning")
        $collectionVariable = $forMatch[2] ?? null; // Collection being iterated (e.g., "warnings")
        $loopVariable = str_replace('.', '', $loopVariable);
        $loopVariable = str_replace("'", '', $loopVariable);

        if ($loopVariable && $collectionVariable) {
            echo PHP_EOL . sprintf('Detected FOR loop variables: "%s" in "%s"', $loopVariable, $collectionVariable);
        } else {
            echo PHP_EOL . 'Could not detect FOR loop variables.';
            exit;
        }

        // Model
        $modelTemplate = "
{% extends 'admin/base.html.twig' %}

{% block body %}

{# ENUM LIST #}

<div class=\"grid gap-6 lg:grid-cols-4\"> 

    <div class=\"lg:col-span-4 p-4 block rounded shadow bg-white/50 dark:bg-white/10 backdrop-blur text-right\"><sl-button href=\"{{ path({# Path do Novo #}) }}\" variant=\"success\" outline>{# Novo #}</sl-button></div>

    <div id=\"listjs\" class=\"lg:col-span-4 p-6 block rounded shadow bg-white/50 dark:bg-white/10 backdrop-blur\">
        <p class=\"text-lg font-bold mb-4\">{#  Título #}</p>

        <input type=\"search\" class=\"search p-2 h-10 mb-4 border focus:outline-0 focus:border-slate-400 dark:border-slate-100/30 dark:bg-slate-800\" placeholder=\"Buscar\" />

        <table class=\"w-full text-left\">
            <tr>
                {# TR TH aqui #}
            </tr>
            <tbody class=\"list\">
            {% for {# ENTITY ITEM #} in {# LIST ENTITY #} %}
                {# TR TD aqui #}

            {% endfor %}
            </tbody>
        </table>

        <ul class=\"pagination flex gap-4 mt-4\"></ul>

    </div>
</div>

<script>
    var options = {
        valueNames: [ {# Parray de campos #} ],
        page: 20,
        pagination: true
    };

    var userList = new List('listjs', options);
</script>

{% endblock %}
        ";

        // Generate thead and tbody based on configuration
        $thead = '';
        $tbody = '<tr class="hover:bg-slate-900/5 dark:hover:bg-slate-100/10">' . PHP_EOL;
        $valueNames = [];
        $enumList = "";


        foreach ($config['fields'] as $field => $fieldConfig) {
            if ($fieldConfig['include']) {
                $thead .= sprintf('<th class="py-1 px-2 border-b">%s</th>' . PHP_EOL, $fieldConfig['label']);
                $tbody .= $this->generateFieldHtmltoIndex($fieldConfig, $fieldConfig['label'], $loopVariable) . PHP_EOL;
                $valueNames[] = "ti-" . strtolower($field);

                if ($this->formTypes[$fieldConfig['formType']] == 'SelectBox de um Enum') {
                    $enumName = $this->getEnumNameByRealPath($fieldConfig['enumFileRealPath']);
                    $enumList.= "{% set ".$enumName."Enum = enum('".$enumName."') %}".PHP_EOL;
                }

            }
        }

        // Add action column
        $thead .= '<th class="py-1 px-2 border-b"></th>';
        $tbody .= "
                    <td class=\"py-1 px-2 border-t\">
                        <sl-button href=\"{{ path(" . $editPath . ", {'id':" . $loopVariable . ".id}) }}\" size=\"small\" variant=\"success\" outline>Editar</sl-button>
                    </td>
                 </tr>
";

        $valueNamesString = implode("', '", array_unique($valueNames));

        // Replace content in the template
        $modelTemplate = str_replace('{# Path do Novo #}', $createPath, $modelTemplate);
        $modelTemplate = str_replace('{# Novo #}', $config['buttonText'], $modelTemplate);
        $modelTemplate = str_replace('{#  Título #}', $config['entityName'], $modelTemplate);
        $modelTemplate = str_replace('{# TR TH aqui #}', $thead, $modelTemplate);
        $modelTemplate = str_replace('{# TR TD aqui #}', $tbody, $modelTemplate);
        $modelTemplate = str_replace('{# Parray de campos #}', "'" . $valueNamesString . "'", $modelTemplate);
        $modelTemplate = str_replace('{# LIST ENTITY #}', $collectionVariable, $modelTemplate);
        $modelTemplate = str_replace('{# ENTITY ITEM #}', $loopVariable, $modelTemplate);
        $modelTemplate = str_replace('{# ENUM LIST #}', $enumList, $modelTemplate);


        return $modelTemplate;
    }

    private function generateFieldHtmltoIndex(array $field, string $label, string $loopVariable): string
    {

        if ($this->formTypes[$field['formType']] == 'SelectBox de um Enum') {
            // Verificar se o Enum possui a funcion "badge"
            $fileContent = file_get_contents($field['enumFileRealPath']);

            // Verifica se o texto está presente
            if (strpos($fileContent, 'public static function badge(') !== false) {
                $enumName = $this->getEnumNameByRealPath($field['enumFileRealPath']);
                return "                    <td class=\"py-1 px-2 border-t ti-status\">{{ ".$enumName."Enum.badge(" . $loopVariable . "." . $field['name'] . ")|raw }}</td>";
            } else {
                $enumName = $this->getEnumNameByRealPath($field['enumFileRealPath']);
                return "                    <td class=\"py-1 px-2 border-t ti-status\">{{ ".$enumName."Enum.label(" . $loopVariable . "." . $field['name'] . ") }}</td>";
            }


        }


        // Handle boolean fields
        if ($this->isBooleanField($field['name']) || $field['type'] == 'boolean') {
            return "
                    <td class=\"py-1 px-2 border-t ti-status\">
                        {% if " . $loopVariable . "." . $field['name'] . " %}
                            <sl-badge variant=\"success\" pill>Sim</sl-badge>
                        {% else %}
                            <sl-badge variant=\"danger\" pill>Não</sl-badge>
                        {% endif %}
                    </td>
";
        }

        if ($field['type'] == 'date_immutable') {
            return "                    <td class=\"py-1 px-2 border-t ti-" . strtolower($field['name']) . "\">{{ " . $loopVariable . "." . $field['name'] . " ? " . $loopVariable . "." . $field['name'] . "|date('d/m/Y') : '' }}</td>";
        }

        if ($field['type'] == 'datetime_immutable') {
            return "                    <td class=\"py-1 px-2 border-t ti-" . strtolower($field['name']) . "\">{{ " . $loopVariable . "." . $field['name'] . " ? " . $loopVariable . "." . $field['name'] . "|date('d/m/Y H:i') : '' }}</td>";
        }

        if ($field['type'] == 'date') {
            return "                    <td class=\"py-1 px-2 border-t ti-" . strtolower($field['name']) . "\">{{ " . $loopVariable . "." . $field['name'] . " ? " . $loopVariable . "." . $field['name'] . "|date('d/m/Y') : '' }}</td>";
        }

        if ($field['type'] == 'datetime') {
            return "                    <td class=\"py-1 px-2 border-t ti-" . strtolower($field['name']) . "\">{{ " . $loopVariable . "." . $field['name'] . " ? " . $loopVariable . "." . $field['name'] . "|date('d/m/Y H:i') : '' }}</td>";
        }

        if ($this->formTypes[$field['formType']] == 'Imagem (imageFile + imageName + updatedAt)') {
            return "                    <td class=\"py-1 px-2 border-t\"><img src=\"{{ vich_uploader_asset(" . $loopVariable . ",'imageFile')|imagine_filter('admin_thumb') }}\"></td>";
        }

        if ($this->formTypes[$field['formType']] == 'SelectBox de uma outra Entity') {
            return "                    <td class=\"py-1 px-2 border-t ti-title\">{{ " . $loopVariable . "." . $field['name'] .".".$field['foreignKeyShow'] ." }}</td>";
        }

        if ($this->formTypes[$field['formType']] == 'PDF (File + fileName + updatedAt)') {
            return "                                    <td>
                    {% if work_candidate.fileName %}
                        <sl-button href=\"{{ vich_uploader_asset(" . $loopVariable . ",'" . $field['name'] ."')}}\" target=\_blank\"  size=\"small\"   variant=\"warning\" outline>Download</sl-button>
                    {% else %}
                        Sem arquivo
                    {% endif %}
                </td>";
        }

        if ($this->formTypes[$field['formType']] == 'Galeria de Imagens') {
            return "                                    <td class=\"py-1 px-2 border-t ti-size\">{{ " . $loopVariable . "." . $field['name'] .".count }} Imagens</td>";
        }


        // Default rendering for other fields
        return "                    <td class=\"py-1 px-2 border-t ti-" . strtolower($field['name']) . "\">{{ " . $loopVariable . "." . $field['name'] . " }}</td>";
    }




//
//
//
//   ▄▄▄▄▄▄▄▄▄▄▄  ▄▄▄▄▄▄▄▄▄▄▄  ▄▄       ▄▄  ▄▄▄▄▄▄▄▄▄▄▄  ▄            ▄▄▄▄▄▄▄▄▄▄▄  ▄▄▄▄▄▄▄▄▄▄▄  ▄▄▄▄▄▄▄▄▄▄▄  ▄▄▄▄▄▄▄▄▄▄▄
//  ▐░░░░░░░░░░░▌▐░░░░░░░░░░░▌▐░░▌     ▐░░▌▐░░░░░░░░░░░▌▐░▌          ▐░░░░░░░░░░░▌▐░░░░░░░░░░░▌▐░░░░░░░░░░░▌▐░░░░░░░░░░░▌
//   ▀▀▀▀█░█▀▀▀▀ ▐░█▀▀▀▀▀▀▀▀▀ ▐░▌░▌   ▐░▐░▌▐░█▀▀▀▀▀▀▀█░▌▐░▌          ▐░█▀▀▀▀▀▀▀█░▌ ▀▀▀▀█░█▀▀▀▀ ▐░█▀▀▀▀▀▀▀▀▀ ▐░█▀▀▀▀▀▀▀▀▀
//       ▐░▌     ▐░▌          ▐░▌▐░▌ ▐░▌▐░▌▐░▌       ▐░▌▐░▌          ▐░▌       ▐░▌     ▐░▌     ▐░▌          ▐░▌
//       ▐░▌     ▐░█▄▄▄▄▄▄▄▄▄ ▐░▌ ▐░▐░▌ ▐░▌▐░█▄▄▄▄▄▄▄█░▌▐░▌          ▐░█▄▄▄▄▄▄▄█░▌     ▐░▌     ▐░█▄▄▄▄▄▄▄▄▄ ▐░█▄▄▄▄▄▄▄▄▄
//       ▐░▌     ▐░░░░░░░░░░░▌▐░▌  ▐░▌  ▐░▌▐░░░░░░░░░░░▌▐░▌          ▐░░░░░░░░░░░▌     ▐░▌     ▐░░░░░░░░░░░▌▐░░░░░░░░░░░▌
//       ▐░▌     ▐░█▀▀▀▀▀▀▀▀▀ ▐░▌   ▀   ▐░▌▐░█▀▀▀▀▀▀▀▀▀ ▐░▌          ▐░█▀▀▀▀▀▀▀█░▌     ▐░▌     ▐░█▀▀▀▀▀▀▀▀▀  ▀▀▀▀▀▀▀▀▀█░▌
//       ▐░▌     ▐░▌          ▐░▌       ▐░▌▐░▌          ▐░▌          ▐░▌       ▐░▌     ▐░▌     ▐░▌                    ▐░▌
//       ▐░▌     ▐░█▄▄▄▄▄▄▄▄▄ ▐░▌       ▐░▌▐░▌          ▐░█▄▄▄▄▄▄▄▄▄ ▐░▌       ▐░▌     ▐░▌     ▐░█▄▄▄▄▄▄▄▄▄  ▄▄▄▄▄▄▄▄▄█░▌
//       ▐░▌     ▐░░░░░░░░░░░▌▐░▌       ▐░▌▐░▌          ▐░░░░░░░░░░░▌▐░▌       ▐░▌     ▐░▌     ▐░░░░░░░░░░░▌▐░░░░░░░░░░░▌
//        ▀       ▀▀▀▀▀▀▀▀▀▀▀  ▀         ▀  ▀            ▀▀▀▀▀▀▀▀▀▀▀  ▀         ▀       ▀       ▀▀▀▀▀▀▀▀▀▀▀  ▀▀▀▀▀▀▀▀▀▀▀
//

    private function modifyDeleteFormTemplate(string $filePath): void
    {
        $content = file_get_contents($filePath);

        // Substituir o botão Delete
        $content = str_replace(
            '<button class="btn">Delete</button>',
            '<sl-button type="submit" variant="neutral" class="mt-4" outline>Apagar</sl-button>',
            $content
        );

        // Substituir a mensagem de confirmação
        $content = str_replace(
            "confirm('Are you sure you want to delete this item?');\">",
            "confirm('Tem certeza de que quer apagar este registro?');\" class=\"mt-4\">",
            $content
        );

        file_put_contents($filePath, $content);
        echo 'Modified ' . $filePath . ' successfully.' . PHP_EOL;
    }

    private function modifyEditTemplate(string $filePath): void
    {
        $content = file_get_contents($filePath);

        // Remover a linha contendo <!DOCTYPE html>
        $content = preg_replace('/^<!DOCTYPE html>\\R?/m', '', $content);

        // Remover a linha que começa com <title> e termina com </title>
        $content = preg_replace('/^<title>.*<\\/title>\\R?/m', '', $content);

        // Remover a linha que começa com <h1> e termina com </h1>
        $content = preg_replace('/^    <h1>.*<\\/h1>\\R?/m', '', $content);

        $content = str_replace("{% block body %}", "", $content);


        // Substituir o topo do documento
        $content = "{% extends 'admin/base.html.twig' %}

{% block body %}
    <p class=\"text-lg font-bold mb-4\">" . $this->entityHumanName . "</p>" . $content;

        // Substituir o link "back to list"
        $content = preg_replace_callback(
            '/<a href="{{ path\\((.*?)\\) }}">back to list<\\/a>/',
            function ($matches) {
                $path = $matches[1];
                return "<sl-button href=\"{{ path($path) }}\" variant=\"secondary\" class=\"mt-4\" outline>Voltar</sl-button>";
            },
            $content
        );

        // Finalizar o bloco body se necessário
        if (!str_contains($content, '{% endblock %}')) {
            $content .= "\n{% endblock %}\n";
        }

        // Salvar as alterações no arquivo
        file_put_contents($filePath, $content);
    }

    private function modifyNewTemplate(string $filePath): void
    {
        $content = file_get_contents($filePath);

        // Remover a linha contendo <!DOCTYPE html>
        $content = preg_replace('/^<!DOCTYPE html>\\R?/m', '', $content);

        // Remover a linha que começa com <title> e termina com </title>
        $content = preg_replace('/^<title>.*<\\/title>\\R?/m', '', $content);

        // Remover a linha que começa com <h1> e termina com </h1>
        $content = preg_replace('/^    <h1>.*<\\/h1>\\R?/m', '', $content);

        $content = str_replace("{% block body %}", "", $content);


        // Substituir o topo do documento
        $content = "{% extends 'admin/base.html.twig' %}

{% block body %}
    
        <p class=\"text-lg font-bold mb-4\">" . $this->entityHumanName . "</p>" . $content;

        // Substituir o link "back to list"
        $content = preg_replace_callback(
            '/<a href="{{ path\\((.*?)\\) }}">back to list<\\/a>/',
            function ($matches) {
                $path = $matches[1];
                return "<sl-button href=\"{{ path($path) }}\" variant=\"secondary\" class=\"mt-4\" outline>Voltar</sl-button>";
            },
            $content
        );

        // Finalizar o bloco body se necessário
        if (!str_contains($content, '{% endblock %}')) {
            $content .= "\n{% endblock %}\n";
        }

        // Salvar as alterações no arquivo
        file_put_contents($filePath, $content);
    }







//
//  ▄▄▄▄▄▄▄▄▄▄▄  ▄▄▄▄▄▄▄▄▄▄▄  ▄▄▄▄▄▄▄▄▄▄▄  ▄▄       ▄▄
// ▐░░░░░░░░░░░▌▐░░░░░░░░░░░▌▐░░░░░░░░░░░▌▐░░▌     ▐░░▌
// ▐░█▀▀▀▀▀▀▀▀▀ ▐░█▀▀▀▀▀▀▀█░▌▐░█▀▀▀▀▀▀▀█░▌▐░▌░▌   ▐░▐░▌
// ▐░▌          ▐░▌       ▐░▌▐░▌       ▐░▌▐░▌▐░▌ ▐░▌▐░▌
// ▐░█▄▄▄▄▄▄▄▄▄ ▐░▌       ▐░▌▐░█▄▄▄▄▄▄▄█░▌▐░▌ ▐░▐░▌ ▐░▌
// ▐░░░░░░░░░░░▌▐░▌       ▐░▌▐░░░░░░░░░░░▌▐░▌  ▐░▌  ▐░▌
// ▐░█▀▀▀▀▀▀▀▀▀ ▐░▌       ▐░▌▐░█▀▀▀▀█░█▀▀ ▐░▌   ▀   ▐░▌
// ▐░▌          ▐░▌       ▐░▌▐░▌     ▐░▌  ▐░▌       ▐░▌
// ▐░▌          ▐░█▄▄▄▄▄▄▄█░▌▐░▌      ▐░▌ ▐░▌       ▐░▌
// ▐░▌          ▐░░░░░░░░░░░▌▐░▌       ▐░▌▐░▌       ▐░▌
//  ▀            ▀▀▀▀▀▀▀▀▀▀▀  ▀         ▀  ▀         ▀
//

    private function modifyFormTemplate(string $filePath, array $config): void
    {
        //dump($config);
        $content = file_get_contents($filePath);
        $entityName = str_replace('App\Entity\\', '', $this->entityClass);


        // criando um novo conteúdo de acordo com cada um dos itens
        $content = "
{{ form_start(form) }}
    <div class=\"lg:col-span-4 p-6 block rounded shadow bg-white/50 dark:bg-white/10 backdrop-blur mb-4\">
";

//        $this->formTypes[1] = 'Simple Input Text';
//        $this->formTypes[2] = 'Edit Text with editor';
//        $this->formTypes[3] = 'Date';
//        $this->formTypes[4] = 'Date and Time';
//        $this->formTypes[5] = 'imageFile';
//        $this->formTypes[6] = 'ChoiceType from list';
//        $this->formTypes[7] = 'ChoiceType from entity';
//        $this->formTypes[8] = 'Sim/Não int => 1/0';
//        $this->formTypes[9] = 'ID';
//
//        $config['fields'][$field] = [
//            'include' => strtolower($include) === 'yes',
//            'label' => $label,
//            'type' => $type,
//            'length' => $length,
//            'name' => $name,
//            'formType' => $formType,
//        ];
        $tinymceListLine1 = "";
        $tinymceListLine2 = "";
        $afterDivBlock = "";
        $endOfFile = "";
        foreach ($config['fields'] as $field => $fieldConfig) {
            //dump($fieldConfig);

            if ($this->formTypes[$fieldConfig['formType']] == 'E-mail') {
                $content .= "
                <label>" . $fieldConfig['label'] . "</label>
                {{ form_widget(form." . $fieldConfig['name'] . ", {'attr': {'placeholder':'nome@exemplo.com.br', 'type':'email', 'class':'w-full p-2 h-10 mb-4 border focus:outline-0 focus:border-slate-400 dark:border-slate-100/30 dark:bg-slate-800'}}) }}
                    
                    ";
            }

            if ($this->formTypes[$fieldConfig['formType']] == 'Número Simples') {
                $content .= "
                <label>" . $fieldConfig['label'] . "</label>
                {{ form_widget(form." . $fieldConfig['name'] . ", {'attr': {'placeholder':'0', 'type':'number', 'class':'w-full p-2 h-10 mb-4 border focus:outline-0 focus:border-slate-400 dark:border-slate-100/30 dark:bg-slate-800'}}) }}
                    
                    ";
            }


            if ($this->formTypes[$fieldConfig['formType']] == 'SelectBox de uma outra Entity') {
                $content .= "
                <label>" . $fieldConfig['label'] . "</label>
                {{ form_widget(form." . $fieldConfig['name'] . ", {'attr': {'class':'w-full p-2 h-10 mb-4 border focus:outline-0 focus:border-slate-400 dark:border-slate-100/30 dark:bg-slate-800'}}) }}
                    
                    ";
            }


            if ($this->formTypes[$fieldConfig['formType']] == 'Simple Input Text') {
                $content .= "
                <label>" . $fieldConfig['label'] . "</label>
                {{ form_widget(form." . $fieldConfig['name'] . ", {'attr': {'class':'w-full p-2 h-10 mb-4 border focus:outline-0 focus:border-slate-400 dark:border-slate-100/30 dark:bg-slate-800'}}) }}
                    
                    ";
            }


            if ($this->formTypes[$fieldConfig['formType']] == 'Date') {
                $content .= "
                <label>" . $fieldConfig['label'] . "</label>
                {{ form_widget(form." . $fieldConfig['name'] . ", {'attr': {'class':'w-full p-2 h-10 mb-4 border focus:outline-0 focus:border-slate-400 dark:border-slate-100/30 dark:bg-slate-800'}}) }}
                    ";
            }


            if ($this->formTypes[$fieldConfig['formType']] == 'Date and Time') {
                $content .= "
                <label>" . $fieldConfig['label'] . "</label>
                {{ form_widget(form." . $fieldConfig['name'] . ", {'attr': {'class':'w-full p-2 h-10 mb-4 border focus:outline-0 focus:border-slate-400 dark:border-slate-100/30 dark:bg-slate-800'}}) }}
                    ";
            }


            if ($this->formTypes[$fieldConfig['formType']] == 'Sim/Não int => 1/0') {
                $content .= "
                <label>" . $fieldConfig['label'] . "</label>
                {{ form_widget(form." . $fieldConfig['name'] . ", {'attr': {'class':'w-full p-2 h-10 mb-4 border focus:outline-0 focus:border-slate-400 dark:border-slate-100/30 dark:bg-slate-800 bg-white'}}) }}
                
                ";
            }


            if ($this->formTypes[$fieldConfig['formType']] == 'Boolean (True/False)') {
                $content .= "
                <label>" . $fieldConfig['label'] . "</label>
                {{ form_widget(form." . $fieldConfig['name'] . ", {'attr': {'class':'w-full p-2 h-10 mb-4 border focus:outline-0 focus:border-slate-400 dark:border-slate-100/30 dark:bg-slate-800 bg-white'}}) }}
                
                ";
            }


            if ($this->formTypes[$fieldConfig['formType']] == 'ChoiceType from list') {
                $content .= "
                <label>" . $fieldConfig['label'] . "</label>
                {{ form_widget(form." . $fieldConfig['name'] . ", {'attr': {'class':'w-full p-2 h-10 mb-4 border focus:outline-0 focus:border-slate-400 dark:border-slate-100/30 dark:bg-slate-800 bg-white'}}) }}
                
                ";
            }


            if ($this->formTypes[$fieldConfig['formType']] == 'SelectBox de um Enum') {
                $content .= "
                <label>" . $fieldConfig['label'] . "</label>
                {{ form_widget(form." . $fieldConfig['name'] . ", {'attr': {'class':'w-full p-2 h-10 mb-4 border focus:outline-0 focus:border-slate-400 dark:border-slate-100/30 dark:bg-slate-800 bg-white'}}) }}
                
                ";
            }


            if ($this->formTypes[$fieldConfig['formType']] == 'Imagem (imageFile + imageName + updatedAt)') {
                $content .= "
                <label>" . $fieldConfig['label'] . "</label>
                {% if " . strtolower($this->entityRouteName) . ".imageName is not empty %}<img class=\"mb-4\" src=\"{{ vich_uploader_asset(" . strtolower($entityName) . ",'imageFile')|imagine_filter('admin_thumb') }}\">{% endif %}
                {{ form_widget(form.imageFile, {'attr': {'class':''}}) }}

                
                ";
            }


            if ($this->formTypes[$fieldConfig['formType']] == 'PDF (File + fileName + updatedAt)') {
                $content .= "
                <label>" . $fieldConfig['label'] . "</label>
                {% if " . strtolower($this->entityRouteName) . "." . $fieldConfig['name'] . " is not empty %}
                <a href=\"{{ vich_uploader_asset(" . strtolower($this->entityRouteName) . ",'" . $fieldConfig['name'] . "')}}\" target=\"_blank\">Download</a>
                {% endif %}
                {{ form_widget(form." . $fieldConfig['name'] . ", {'attr': {'class':'w-full p-2 h-10 mb-4 border focus:outline-0 focus:border-slate-400 dark:border-slate-100/30 dark:bg-slate-800'}}) }}

                
                ";
            }


            if ($this->formTypes[$fieldConfig['formType']] == 'Galeria de Imagens') {
                $endOfFile = PHP_EOL.PHP_EOL.PHP_EOL."{% include 'admin/inc_form_image_script.html.twig' %}";
                $afterDivBlock .= "
        <div class=\"lg:col-span-4 p-6 block rounded shadow bg-white/50 dark:bg-white/10 backdrop-blur mb-4\">
            <p class=\"text-lg font-bold mb-4\">Visitas</p>
            {% macro printImagesRow(imagesItem) %}
                {{ form_errors(imagesItem) }}
                <label class=\"whitespace-nowrap\">{{ form_widget(imagesItem.active, {'attr':{'checked':''}}) }} visível</label>
                {{ form_widget(imagesItem.description, {'attr':{'class':'w-full p-2 h-10 border focus:outline-0 focus:border-slate-400 dark:border-slate-100/30 dark:bg-slate-800', 'placeholder':'Legenda'}}) }}
                {{ form_widget(imagesItem.imageFile, {'attr':{'class':''}}) }}
            {% endmacro %}
            {% import _self as formMacros %}
            <div class=\"mb-3 text-right\"><sl-button class=\"add_item\" data-collection-holder-class=\"images\" variant=\"success\" outline>+ Adicionar item</sl-button></div>
            <ul class=\"images collection-list\"
                data-index=\"{{ form.images|length > 0 ? form.images|last.vars.name + 1 : 0 }}\"
                data-prototype=\"{{ formMacros.printImagesRow(form.images.vars.prototype)|e('html_attr') }}\"
            >
                {% for imagesItem in form." . $fieldConfig['name'] . " %}
                    <div class=\"mb-3 flex gap-4 items-center\">
                        {{ form_errors(imagesItem) }}
                        <label class=\"whitespace-nowrap\">{{ form_widget(imagesItem.active, {'attr':{'checked':''}}) }} visível</label>
                        {% if imagesItem.vars.data.imageName is not empty %}<img src=\"{{ vich_uploader_asset(imagesItem.vars.data,'imageFile')|imagine_filter('admin_thumb') }}\">{% endif %}
                        {{ form_widget(imagesItem.description, {'attr':{'class':'w-full p-2 h-10 border focus:outline-0 focus:border-slate-400 dark:border-slate-100/30 dark:bg-slate-800', 'placeholder':'Legenda'}}) }}
                        {{ form_widget(imagesItem.imageFile, {'attr':{'class':''}}) }}
                        <div class=\"rb\"></div>
                    </div>
                {% endfor %}
            </ul>
            {% do form.images.setRendered %}
        </div>

";
            }


            if ($this->formTypes[$fieldConfig['formType']] == 'Texto com Editor') {
                $content .= "
                <label>" . $fieldConfig['label'] . "</label>
                {{ form_widget(form." . $fieldConfig['name'] . ", {'attr': {'class':'w-full p-2 h-10 mb-4 border focus:outline-0 focus:border-slate-400 dark:border-slate-100/30 dark:bg-slate-800'}}) }}
                    
                    ";
                $tinymceListLine1 = "        <script src=\"/lib/tinymce/tinymce.min.js\"></script>".PHP_EOL;
                $tinymceListLine2 .= "
        <script>

            tinymce.init({
                selector: '#{{form.".$fieldConfig['name'].".vars.id}}',
                height: 500,
                menubar:false,
                plugins: [
                    'code',
                    'table'
                ],
                toolbar: 'undo redo |' +
                    'bold italic forecolor | alignleft aligncenter ' +
                    'alignright alignjustify | bullist numlist outdent indent | code ' ,
                content_style: 'body { font-family:Helvetica,Arial,sans-serif; font-size:16px }'
            });
        </script>                    
                    ".PHP_EOL;
            }

        }

        $content .= "
    </div>".$afterDivBlock."

    <sl-button type=\"submit\" variant=\"primary\" outline>Salvar</sl-button>
{{ form_end(form) }}
".$tinymceListLine1.$tinymceListLine2.$endOfFile;

        // Salvar as alterações no arquivo
        file_put_contents($filePath, $content);
        echo 'Modified ' . $filePath . ' successfully.' . PHP_EOL;







//
//      ▄▄▄▄▄▄▄▄▄▄▄  ▄▄▄▄▄▄▄▄▄▄▄  ▄▄▄▄▄▄▄▄▄▄▄  ▄▄       ▄▄       ▄▄▄▄▄▄▄▄▄▄▄  ▄         ▄  ▄▄▄▄▄▄▄▄▄▄▄  ▄▄▄▄▄▄▄▄▄▄▄
//     ▐░░░░░░░░░░░▌▐░░░░░░░░░░░▌▐░░░░░░░░░░░▌▐░░▌     ▐░░▌     ▐░░░░░░░░░░░▌▐░▌       ▐░▌▐░░░░░░░░░░░▌▐░░░░░░░░░░░▌
//     ▐░█▀▀▀▀▀▀▀▀▀ ▐░█▀▀▀▀▀▀▀█░▌▐░█▀▀▀▀▀▀▀█░▌▐░▌░▌   ▐░▐░▌      ▀▀▀▀█░█▀▀▀▀ ▐░▌       ▐░▌▐░█▀▀▀▀▀▀▀█░▌▐░█▀▀▀▀▀▀▀▀▀
//     ▐░▌          ▐░▌       ▐░▌▐░▌       ▐░▌▐░▌▐░▌ ▐░▌▐░▌          ▐░▌     ▐░▌       ▐░▌▐░▌       ▐░▌▐░▌
//     ▐░█▄▄▄▄▄▄▄▄▄ ▐░▌       ▐░▌▐░█▄▄▄▄▄▄▄█░▌▐░▌ ▐░▐░▌ ▐░▌          ▐░▌     ▐░█▄▄▄▄▄▄▄█░▌▐░█▄▄▄▄▄▄▄█░▌▐░█▄▄▄▄▄▄▄▄▄
//     ▐░░░░░░░░░░░▌▐░▌       ▐░▌▐░░░░░░░░░░░▌▐░▌  ▐░▌  ▐░▌          ▐░▌     ▐░░░░░░░░░░░▌▐░░░░░░░░░░░▌▐░░░░░░░░░░░▌
//     ▐░█▀▀▀▀▀▀▀▀▀ ▐░▌       ▐░▌▐░█▀▀▀▀█░█▀▀ ▐░▌   ▀   ▐░▌          ▐░▌      ▀▀▀▀█░█▀▀▀▀ ▐░█▀▀▀▀▀▀▀▀▀ ▐░█▀▀▀▀▀▀▀▀▀
//     ▐░▌          ▐░▌       ▐░▌▐░▌     ▐░▌  ▐░▌       ▐░▌          ▐░▌          ▐░▌     ▐░▌          ▐░▌
//     ▐░▌          ▐░█▄▄▄▄▄▄▄█░▌▐░▌      ▐░▌ ▐░▌       ▐░▌          ▐░▌          ▐░▌     ▐░▌          ▐░█▄▄▄▄▄▄▄▄▄
//     ▐░▌          ▐░░░░░░░░░░░▌▐░▌       ▐░▌▐░▌       ▐░▌          ▐░▌          ▐░▌     ▐░▌          ▐░░░░░░░░░░░▌
//      ▀            ▀▀▀▀▀▀▀▀▀▀▀  ▀         ▀  ▀         ▀            ▀            ▀       ▀            ▀▀▀▀▀▀▀▀▀▀▀
//


        // Ajustar o form Type
        $content = file_get_contents($config['formFileIntPath']);
        //dump($config['formFileIntPath']);
        //dump($content);

        foreach ($config['fields'] as $field => $fieldConfig) {


            if ($this->formTypes[$fieldConfig['formType']] == 'Imagem (imageFile + imageName + updatedAt)') {
                // Substituir `->add('status')` por uma lista de opções
                $content = str_replace("->add('" . $fieldConfig['name'] . "')",
                    "->add('imageFile', VichFileType::class, [
                'required' => false,
                'allow_delete' => false,
                'download_label' => 'baixar arquivo atual',
                'asset_helper' => true,
            ])
                      ",
                    $content
                );
                // Garantir a inclusão do `use` para ChoiceType
                if (!str_contains($content, 'use Vich\UploaderBundle\Form\Type\VichFileType;')) {
                    $content = str_replace(
                        "\n\nclass ",
                        "\nuse Vich\\UploaderBundle\\Form\\Type\\VichFileType;\n\nclass ",
                        $content
                    );
                }
            }


            if ($this->formTypes[$fieldConfig['formType']] == 'PDF (File + fileName + updatedAt)') {

                $oldItem = "->add('" . $fieldConfig['name'] . "')";
                $newItem = "->add('" . $fieldConfig['name'] . "', VichFileType::class, [
                    'required' => true,
                    'allow_delete' => false,
                    'download_label' => 'baixar arquivo atual',
                    'asset_helper' => true,
                ])";
                // ver se o campo existe na listagem
                if (str_contains($content, $oldItem)) {
                    // Substituir `->add('status')` por uma lista de opções
                    $content = str_replace($oldItem,$newItem,$content);
                } else{
                    // Adiciona o novo item exatamente abaixo de $builder
                    $content = preg_replace(
                        '/(        \$builder)/',
                        "\$1\n            $newItem",
                        $content,
                        1 // Limita a substituição à primeira ocorrência
                    );
                }





                // Garantir a inclusão do `use` para ChoiceType
                if (!str_contains($content, 'use Vich\UploaderBundle\Form\Type\VichFileType;')) {
                    $content = str_replace(
                        "\n\nclass ",
                        "\nuse Vich\\UploaderBundle\\Form\\Type\\VichFileType;\n\nclass ",
                        $content
                    );
                }
            }




            if ($this->formTypes[$fieldConfig['formType']] == 'Galeria de Imagens') {

                $oldItem = "->add('" . $fieldConfig['name'] . "')";
                $newItem = "->add('" . $fieldConfig['name'] . "', CollectionType::class, [
                'entry_type' => GalleryImageType::class,
                'required' => false,
                'allow_add' => true,
                'allow_delete' => true,
            ])";
                // ver se o campo existe na listagem
                if (str_contains($content, $oldItem)) {
                    // Substituir `->add('status')` por uma lista de opções
                    $content = str_replace($oldItem,$newItem,$content);
                } else{
                    // Adiciona o novo item exatamente abaixo de $builder
                    $content = preg_replace(
                        '/(        \$builder)/',
                        "\$1\n            $newItem",
                        $content,
                        1 // Limita a substituição à primeira ocorrência
                    );
                }

                // Garantir a inclusão do `use` para ChoiceType
                if (!str_contains($content, 'use Symfony\Component\Form\Extension\Core\Type\CollectionType;')) {
                    $content = str_replace(
                        "\n\nclass ",
                        "\nuse Symfony\\Component\\Form\\Extension\\Core\\Type\\CollectionType;\n\nclass ",
                        $content
                    );
                }
            }





            if ($this->formTypes[$fieldConfig['formType']] == 'SelectBox de uma outra Entity') {

                // Substituição com preg_replace_callback para ajuste dinâmico
                $content = preg_replace_callback(
                    "/->add\('{$fieldConfig['name']}', EntityType::class, \[(.*?)\]\)/s",
                    function ($matches) use ($fieldConfig) {
                        return "->add('{$fieldConfig['name']}', EntityType::class, [
                'class' => {$fieldConfig['foreignKeyEntity']}::class,
                'choice_value' => '{$fieldConfig['foreignKeyStore']}',
                'choice_label' => '{$fieldConfig['foreignKeyShow']}',
            ])";
                    },
                    $content
                );


            }




            if ($this->formTypes[$fieldConfig['formType']] == 'SelectBox de um Enum') {
                // Path do enumFileRealPath

                // Substitui tudo até "Enum/" por uma string vazia
                $enum = $this->getEnumNameByRealPath($fieldConfig['enumFileRealPath']);

                // Substituir `->add('status')` por uma lista de opções
                $content = str_replace("->add('" . $fieldConfig['name'] . "')",
                    "->add('" . $fieldConfig['name'] . "', ChoiceType::class, [
                'choices'=> ".$enum."::getOptions(),
            ])",
                    $content
                );

                // Garantir a inclusão do `use` para Enum
                if (!str_contains($content, 'use App\Enum\\'.$enum.';')) {
                    $content = str_replace(
                        "\n\nclass ",
                        "\nuse App\\Enum\\".$enum.";\n\nclass ",
                        $content
                    );
                }

                // Garantir a inclusão do `use` para ChoiceType
                if (!str_contains($content, 'use Symfony\Component\Form\Extension\Core\Type\ChoiceType;')) {
                    $content = str_replace(
                        "\n\nclass ",
                        "\nuse Symfony\\Component\\Form\\Extension\\Core\\Type\\ChoiceType;\n\nclass ",
                        $content
                    );
                }
            }



            if ($this->formTypes[$fieldConfig['formType']] == 'Sim/Não int => 1/0') {
                // Substituir `->add('status')` por uma lista de opções
                $content = str_replace("->add('" . $fieldConfig['name'] . "')",
                    "->add('" . $fieldConfig['name'] . "', ChoiceType::class, [
                'choices' => [
                    'Sim' => 1,
                    'Não' => 0,
                ],
                'expanded' => true, // Radio buttons
                'multiple' => false, // Single choice
            ])",
                    $content
                );
                // Garantir a inclusão do `use` para ChoiceType
                if (!str_contains($content, 'use Symfony\Component\Form\Extension\Core\Type\ChoiceType;')) {
                    $content = str_replace(
                        "\n\nclass ",
                        "\nuse Symfony\\Component\\Form\\Extension\\Core\\Type\\ChoiceType;\n\nclass ",
                        $content
                    );
                }
            }


            if ($this->formTypes[$fieldConfig['formType']] == 'Boolean (True/False)') {
                // Substituir `->add('status')` por uma lista de opções
                $content = str_replace("->add('" . $fieldConfig['name'] . "')",
                    "->add('" . $fieldConfig['name'] . "', ChoiceType::class, [\n                'choices' => [\n                    'Sim' => True,\n                    'Não' => False,\n                ],\n                'expanded' => true, // Radio buttons\n                'multiple' => false, // Single choice\n            ])",
                    $content
                );
                // Garantir a inclusão do `use` para ChoiceType
                if (!str_contains($content, 'use Symfony\Component\Form\Extension\Core\Type\ChoiceType;')) {
                    $content = str_replace(
                        "\n\nclass ",
                        "\nuse Symfony\\Component\\Form\\Extension\\Core\\Type\\ChoiceType;\n\nclass ",
                        $content
                    );
                }
            }


            if ($this->formTypes[$fieldConfig['formType']] == 'ChoiceType from list') {
                // Substituir `->add('type')` por uma lista de opções
                $content = str_replace("->add('" . $fieldConfig['name'] . "')",
                    "->add('" . $fieldConfig['name'] . "', ChoiceType::class, [
                'choices' => [
                    'Informação' => 1,
                    'Atenção' => 2,
                    'Aviso Crítico' => 3,
                ],
                'expanded' => false, // Dropdown select
                'multiple' => false, // Single choice
            ])
",
                    $content
                );

                // Garantir a inclusão do `use` para ChoiceType
                if (!str_contains($content, 'use Symfony\Component\Form\Extension\Core\Type\ChoiceType;')) {
                    $content = str_replace(
                        "\n\nclass ",
                        "\nuse Symfony\\Component\\Form\\Extension\\Core\\Type\\ChoiceType;\n\nclass ",
                        $content
                    );
                }

            }


            if ($this->formTypes[$fieldConfig['formType']] == 'Ignore (não aparece nem na lista, nem no form)') {

                $content = str_replace(
                    "->add('" . $fieldConfig['name'] . "', null, [
                'widget' => 'single_text'
            ])",
                    "",
                    $content
                );

                $content = str_replace(
                    "->add('" . $fieldConfig['name'] . "')",
                    "",
                    $content
                );

            }


        }


        // Salvar as alterações no arquivo
        file_put_contents($config['formFileIntPath'], $content);
        echo 'Modified ' . $config['formFileIntPath'] . ' successfully.' . PHP_EOL;
    }




//     ▄▄▄▄▄▄▄▄▄▄   ▄▄▄▄▄▄▄▄▄▄▄  ▄▄▄▄▄▄▄▄▄▄▄  ▄▄▄▄▄▄▄▄▄▄▄
//    ▐░░░░░░░░░░▌ ▐░░░░░░░░░░░▌▐░░░░░░░░░░░▌▐░░░░░░░░░░░▌
//    ▐░█▀▀▀▀▀▀▀█░▌▐░█▀▀▀▀▀▀▀█░▌▐░█▀▀▀▀▀▀▀▀▀ ▐░█▀▀▀▀▀▀▀▀▀
//    ▐░▌       ▐░▌▐░▌       ▐░▌▐░▌          ▐░▌
//    ▐░█▄▄▄▄▄▄▄█░▌▐░█▄▄▄▄▄▄▄█░▌▐░█▄▄▄▄▄▄▄▄▄ ▐░█▄▄▄▄▄▄▄▄▄
//    ▐░░░░░░░░░░▌ ▐░░░░░░░░░░░▌▐░░░░░░░░░░░▌▐░░░░░░░░░░░▌
//    ▐░█▀▀▀▀▀▀▀█░▌▐░█▀▀▀▀▀▀▀█░▌ ▀▀▀▀▀▀▀▀▀█░▌▐░█▀▀▀▀▀▀▀▀▀
//    ▐░▌       ▐░▌▐░▌       ▐░▌          ▐░▌▐░▌
//    ▐░█▄▄▄▄▄▄▄█░▌▐░▌       ▐░▌ ▄▄▄▄▄▄▄▄▄█░▌▐░█▄▄▄▄▄▄▄▄▄
//    ▐░░░░░░░░░░▌ ▐░▌       ▐░▌▐░░░░░░░░░░░▌▐░░░░░░░░░░░▌
//     ▀▀▀▀▀▀▀▀▀▀   ▀         ▀  ▀▀▀▀▀▀▀▀▀▀▀  ▀▀▀▀▀▀▀▀▀▀▀
    private function editBaseTemplate(string $baseTemplatePath): void
    {


        // Ler o conteúdo do arquivo base
        $content = file($baseTemplatePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        // Procurar a linha que contém "> Logout</a>"
        $logoutLineIndex = null;
        foreach ($content as $index => $line) {
            if (strpos($line, '> Logout</a>') !== false) {
                $logoutLineIndex = $index;
                break;
            }
        }

        if ($logoutLineIndex === null) {
            throw new \RuntimeException('Could not find the "> Logout</a>" line in the base template.');
        }

        // Procurar a última linha de texto (não vazia) antes de logout
        $lastNonEmptyLineIndex = $logoutLineIndex;
        $lastNonEmptyLineIndex--;
        while ($lastNonEmptyLineIndex > 0 && empty(trim($content[$lastNonEmptyLineIndex]))) {
            $lastNonEmptyLineIndex--;
        }

        // Fazer uma cópia da última linha não vazia
        $newLine = $content[$lastNonEmptyLineIndex];

        // Alterar o nome do link
        $newLine = preg_replace(
            "/<\/sl-icon> .*?<\/a>/",
            "</sl-icon> $this->entityHumanName</a>",
            $newLine
        );

        // Alterar o path para corresponder ao nome da entidade
        $newLine = preg_replace(
            "/path\\('.*?'\\)/",
            "path('app_admin_" . strtolower($this->entityRouteName) . "_index')",
            $newLine
        );

        // Inserir a nova linha após a linha encontrada
        array_splice($content, $logoutLineIndex, 0, $newLine . "\n" . "\n");

        // Salvar o conteúdo alterado de volta no arquivo
        file_put_contents($baseTemplatePath, implode("\n", $content));
    }




//
//     ▄▄▄▄▄▄▄▄▄▄▄  ▄▄▄▄▄▄▄▄▄▄▄  ▄▄        ▄  ▄▄▄▄▄▄▄▄▄▄▄  ▄▄▄▄▄▄▄▄▄▄▄  ▄▄▄▄▄▄▄▄▄▄▄
//    ▐░░░░░░░░░░░▌▐░░░░░░░░░░░▌▐░░▌      ▐░▌▐░░░░░░░░░░░▌▐░░░░░░░░░░░▌▐░░░░░░░░░░░▌
//    ▐░█▀▀▀▀▀▀▀▀▀ ▐░█▀▀▀▀▀▀▀█░▌▐░▌░▌     ▐░▌▐░█▀▀▀▀▀▀▀▀▀  ▀▀▀▀█░█▀▀▀▀ ▐░█▀▀▀▀▀▀▀▀▀
//    ▐░▌          ▐░▌       ▐░▌▐░▌▐░▌    ▐░▌▐░▌               ▐░▌     ▐░▌
//    ▐░▌          ▐░▌       ▐░▌▐░▌ ▐░▌   ▐░▌▐░█▄▄▄▄▄▄▄▄▄      ▐░▌     ▐░▌ ▄▄▄▄▄▄▄▄
//    ▐░▌          ▐░▌       ▐░▌▐░▌  ▐░▌  ▐░▌▐░░░░░░░░░░░▌     ▐░▌     ▐░▌▐░░░░░░░░▌
//    ▐░▌          ▐░▌       ▐░▌▐░▌   ▐░▌ ▐░▌▐░█▀▀▀▀▀▀▀▀▀      ▐░▌     ▐░▌ ▀▀▀▀▀▀█░▌
//    ▐░▌          ▐░▌       ▐░▌▐░▌    ▐░▌▐░▌▐░▌               ▐░▌     ▐░▌       ▐░▌
//    ▐░█▄▄▄▄▄▄▄▄▄ ▐░█▄▄▄▄▄▄▄█░▌▐░▌     ▐░▐░▌▐░▌           ▄▄▄▄█░█▄▄▄▄ ▐░█▄▄▄▄▄▄▄█░▌
//    ▐░░░░░░░░░░░▌▐░░░░░░░░░░░▌▐░▌      ▐░░▌▐░▌          ▐░░░░░░░░░░░▌▐░░░░░░░░░░░▌
//     ▀▀▀▀▀▀▀▀▀▀▀  ▀▀▀▀▀▀▀▀▀▀▀  ▀        ▀▀  ▀            ▀▀▀▀▀▀▀▀▀▀▀  ▀▀▀▀▀▀▀▀▀▀▀


    private function loadOrCreateConfig(array $fields, SymfonyStyle $io): array
    {

        // Definir nome da Entidadade na rota. fertilizer_request
        $this->entityRouteName = '';
        if (preg_match('/\/([^\/]+)\/index\.html\.twig$/', $this->templatePath, $matches)) {
            $this->entityRouteName = $matches[1];
        }


        $listOptionFormType = "";
        foreach ($this->formTypes as $index => $formType) {
            $listOptionFormType .= $index . '. ';
            if ($index < 10 ){ $listOptionFormType .= ' ';}
            $listOptionFormType .=  $formType . PHP_EOL;
        }

        $configFile = self::JSON_FILE_DIR . '/' . str_replace('\\', '_', $this->entityClass) . '_index_config.json';
        $config = is_file($configFile) ? json_decode(file_get_contents($configFile), true) : [];

        $config['entityName'] = $io->ask('Nome da Entity (Para listagem e Form)', $config['entityName'] ?? 'Entity');
        $config['buttonText'] = $io->ask('O que deve estar escrito no botão "Create New"', $config['buttonText'] ?? 'Create New');



        //  ---------------------------------------------------------
        //                    Localizar o FormType
        //  ---------------------------------------------------------

        // Procurar todos os arquivos na pasta src/Form
        $finder = new Finder();
        $finder->files()->in('src/Form')->name('*.php');

        $forms = [];
        $realPaths = [];

        // Coletar nomes e caminhos reais dos arquivos
        foreach ($finder as $file) {
            $forms[] = $file->getRelativePathname(); // Nome relativo
            $realPaths[] = $file->getRealPath(); // Caminho absoluto
        }

        // Exibir a lista de forms e pedir para o usuário escolher um
        $io->section('Forms disponíveis');
        foreach ($forms as $index => $form) {
            $io->writeln(sprintf('%d. %s', $index + 1, $form));
        }

        if (!isset($config['formFileInt'])) {
            $config['formFileInt'] = 0;
        }

        $config['formFileInt'] = $io->ask('Informe o número do form que você deseja alterar', $config['formFileInt'], function ($value) use ($forms) {
            if (!is_numeric($value) || $value < 1 || $value > count($forms)) {
                throw new \RuntimeException('Opção inválida, por favor, informe outro número.');
            }
            return (int)$value;
        });

        // Obter o realpath do arquivo escolhido
        $config['formFileIntPath'] = $realPaths[$config['formFileInt'] - 1];

        // Exibir o caminho real no console
        $io->success(sprintf('Você selecionou: %s', $config['formFileIntPath']));

        // --------------------------------------------------------------


        //  ---------------------------------------------------------
        //                    Localiar os ENUMs
        //  ---------------------------------------------------------
        // Procurar todos os arquivos na pasta src/Form
        $finder = new Finder();
        $finder->files()->in('src/Enum')->name('*.php');

        $enums = [];
        $enumsRealPaths = [];

        // Coletar nomes e caminhos reais dos arquivos
        foreach ($finder as $file) {
            $enums[] = $file->getRelativePathname(); // Nome relativo
            $enumsRealPaths[] = $file->getRealPath(); // Caminho absoluto
        }

        // --------------------------------------------------------------



        // Obter o metadata da entidade selecionada
        $metadata = $this->entityManager->getClassMetadata($this->entityClass);

        // Combinar campos simples e associações
        $fieldsAndAssociations = array_merge(
            $metadata->getFieldNames(),
            array_keys($metadata->getAssociationMappings()) // Pega o nome das propriedades locais
        );

        // Usar reflexão para obter todas as propriedades da classe, incluindo não mapeadas
        $reflectionClass = new \ReflectionClass($this->entityClass);
        foreach ($reflectionClass->getProperties() as $property) {
            // Garantir que o campo não foi incluído por Doctrine
            if (!in_array($property->getName(), $fieldsAndAssociations, true)) {
                $fieldsAndAssociations[] = $property->getName();
            }
        }

        foreach ($fieldsAndAssociations as $field) {
            $io->writeln('');
            $io->writeln('-------------------------------------------------------');
            $io->writeln('');
            if ($metadata->hasField($field)) {
                // Obter o mapeamento do campo
                $fieldMapping = $metadata->getFieldMapping($field);

                // Obter tipo, tamanho e nome do campo
                $type = $fieldMapping['type'] ?? 'unknown';
                $length = $fieldMapping['length'] ?? 'undefined';
                $name = $fieldMapping['fieldName'] ?? $field; // Nome do campo
            } else {
                // Campo não mapeado
                $type = 'unknown';
                $length = 'undefined';
                $name = $field;
            }

            if (!isset($config['fields'][$field]['include'])) {
                $config['fields'][$field]['include'] = "";
            }
            if ($config['fields'][$field]['include'] == 1) {
                $config['fields'][$field]['include'] = "yes";
            }

            $include = $io->ask(sprintf("Incluir o campo '%s' na listagem? (yes/no)", $field), $config['fields'][$field]['include'] ?? 'yes');
            $label = $io->ask(sprintf("Qual o rótulo do campo '%s'", $field), $config['fields'][$field]['label'] ?? ucfirst($field));
            $formType = $io->ask(sprintf("Qual o tipo do campo '%s'" . PHP_EOL . $listOptionFormType, $field), $config['fields'][$field]['formType'] ?? ucfirst($field));


            if ($this->formTypes[$formType] == 'Galeria de Imagens'){
                $this->showMessageGalery();
            }

            //  ---------------------------------------------------------
            //                          ENUM
            //  ---------------------------------------------------------
            $enumFileRealPath = "";
            $enumFile="";
            if ($this->formTypes[$formType] == 'SelectBox de um Enum'){

                // Exibir a lista de enums e pedir para o usuário escolher um
                $io->section('ENUNs Disponíveis');
                foreach ($enums as $index => $form) {
                    $io->writeln(sprintf('%d. %s', $index + 1, $form));
                }

                if (!isset($config['fields'][$field]['enumFile'] )) {
                    $config['fields'][$field]['enumFile']  = 0;
                }

                $enumFile  = $io->ask('Informe o número do ENUM que você deseja usar: ', $config['fields'][$field]['enumFile'] , function ($value) use ($enums) {
                    if (!is_numeric($value) || $value < 1 || $value > count($enums)) {
                        throw new \RuntimeException('Opção inválida, por favor, informe outro número.');
                    }
                    return (int)$value;
                });

                // Obter o realpath do arquivo escolhido
                $enumFileRealPath = $enumsRealPaths[$enumFile - 1];

                // Exibir o caminho real no console
                $io->success(sprintf('Você selecionou: %s', $enumFileRealPath));

            }
            // -----------------------------------------------------------


            //  ---------------------------------------------------------
            //                          CHAVE ESTRANGEIRA
            //  ---------------------------------------------------------
            $foreignKeyEntity = "";
            $foreignKeyStore = "";
            $foreignKeyShow = "";
            if ($this->formTypes[$formType] == 'SelectBox de uma outra Entity'){
                // List all entities
                $entityClasses = $this->getAllEntities();

                // Display entities as a numbered list
                $io->section('Entities Disponívies');
                foreach ($entityClasses as $index => $entityClass) {
                    $io->writeln(sprintf('%d. %s', $index + 1, $entityClass));
                }

                // Prompt user to select an entity by number
                $entityNumber = $io->ask('Informe o número da Entitidade que deseja usar', null, function ($value) use ($entityClasses) {
                    if (!is_numeric($value) || $value < 1 || $value > count($entityClasses)) {
                        throw new \RuntimeException('Opção inválida, por favor, informe outro número.');
                    }
                    return (int)$value;
                });

                // Store the selected entity in the property
                $foreignKeyEntity = $entityClasses[$entityNumber - 1];
                $foreignKeyEntity = str_replace('App\Entity\\','',$foreignKeyEntity);
                $io->success(sprintf('Você selecionou: %s', $foreignKeyEntity));

                $io->section('Campo a ser exibido');
                // Obter o metadata da entidade selecionada
                $metadata = $this->entityManager->getClassMetadata('App\Entity\\'.$foreignKeyEntity);

                // Combinar campos simples e associações
                $allFields = array_merge($metadata->getFieldNames(),  array_keys($metadata->getAssociationMappings()));

                // Iterar sobre todos os campos e associações
                $io->writeln('Campos e Associações Encontrados:');

                foreach ($allFields as $index => $field2) {
                    $io->writeln(sprintf('%d. %s', $index + 1, $field2));
                }

                // Prompt user to select field an entity to show
                $fieldNumber = $io->ask('Informe o número do campo que você deseja usar na propriedade "SHOW"', null, function ($value) use ($allFields) {
                    if (!is_numeric($value) || $value < 1 || $value > count($allFields)) {
                        throw new \RuntimeException('Opção inválida, por favor, informe outro número.');
                    }
                    return (int)$value;
                });
                $foreignKeyShow = $allFields[$fieldNumber - 1];
                $foreignKeyShow = str_replace('App\Entity\\','',$foreignKeyShow);
                $io->success(sprintf('Você selecionou: %s', $foreignKeyShow));



                // Iterar sobre todos os campos e associações
                $io->writeln('Campos e Associações Encontrados:');
                foreach ($allFields as $index => $field2) {
                    $io->writeln(sprintf('%d. %s', $index + 1, $field2));
                }
                // Prompt user to select field an entity to store
                $fieldNumber = $io->ask('Informe o número do campo que você deseja usar na propriedade  "STORE"', null, function ($value) use ($allFields) {
                    if (!is_numeric($value) || $value < 1 || $value > count($allFields)) {
                        throw new \RuntimeException('Opção inválida, por favor, informe outro número.');
                    }
                    return (int)$value;
                });
                $foreignKeyStore = $allFields[$fieldNumber - 1];
                $foreignKeyStore = str_replace('App\Entity\\','',$foreignKeyStore);
                $io->success(sprintf('Você selecionou: %s', $foreignKeyStore));



            }
            // -----------------------------------------------------------



            $config['fields'][$field] = [
                'include' => strtolower($include) === 'yes',
                'label' => $label,
                'type' => $type,
                'length' => $length,
                'name' => $name,
                'formType' => $formType,
                'enumFile' => $enumFile,
                'enumFileRealPath' => $enumFileRealPath,
                'foreignKeyEntity' => $foreignKeyEntity,
                'foreignKeyStore' => $foreignKeyStore,
                'foreignKeyShow' => $foreignKeyShow,
            ];

            $this->entityHumanName = $config['entityName'];
        }



        if (!is_dir(self::JSON_FILE_DIR)) {
            mkdir(self::JSON_FILE_DIR, 0777, true);
        }
        file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT));


        // Remover duplicados com base no campo 'name'
        $uniqueFields = [];
        foreach ($config['fields'] as $key => $field) {
            if (!isset($uniqueFields[$field['name']])) {
                $uniqueFields[$field['name']] = $field; // Adiciona se não existir
            }
        }

        // Atualizar os campos no $config
        $config['fields'] = array_values($uniqueFields); // Reindexa os índices numéricos


        $metadata = $this->entityManager->getClassMetadata($this->entityClass);
        // Derive the controller namespace from the entity namespace
        $controllerNamespace = str_replace('Entity', 'Controller\admin', $metadata->getName()) . 'Controller';
        //dump($controllerNamespace);
        $controllerNamespace = str_replace('App\\', '\\',$controllerNamespace);
        //dump($controllerNamespace);
        $this->controllerFilePath = str_replace('\\', DIRECTORY_SEPARATOR, $controllerNamespace) . '.php';
        //dump($this->controllerFilePath);

        // Nome da classe
        $this->className = $this->controllerFilePath;
        $this->className = str_replace('/Controller/admin/','',$this->className);
        $this->className = str_replace('/Controller.php','',$this->className);
        $this->className = str_replace('Controller.php','',$this->className);
        // dump($className);


        // Class Name CamelCase
        $this->classNameCamelCase = lcfirst($this->className);


        $io->success('Configuração Salva');

        $io->section('Resumo das configurações');
        $io->writeln('Nome da Entidade: <info>'.$config['entityName'].'</info>');
        $io->writeln('Botão novo: <info>'.$config['buttonText'].'</info>');
        $io->writeln('Nome da Classe: <info>'.$this->className.'</info>');
        $io->writeln('Nome da Classe com Camel Case: <info>'.$this->classNameCamelCase.'</info>');
        $io->writeln('Path do Controller: <info>'.$this->controllerFilePath.'</info>');
        $io->writeln('Nome da Rota: <info>'.$this->entityRouteName.'</info>');
        $io->section('Propriedade da Entidade');
        $io->writeln('Nome da Entidade: <info>'.$config['entityName'].'</info>');

        foreach ($config['fields'] as $index => $field) {
            $io->writeln('Nome: <info>'.$field['name'].'</info>');
            $io->writeln('Label: <info>'.$field['label'].'</info>');
            $io->writeln('Incluir na Listagem: <info>'.($field['include']==1?'Sim':'Não').'</info>');
            $io->writeln('Tipo: <info>'.$field['formType'].'</info> - <info>'.$this->formTypes[$field['formType']] .'</info>');
            $io->writeln('Tamanho: <info>'.$field['length'].'</info>');
            if ($field['enumFileRealPath'] != ""){ $io->writeln('Path do Enum: <info>'.$field['enumFileRealPath'].'</info>'); }
            if ($field['foreignKeyEntity'] != ""){ $io->writeln('Chave Estrangeira: <info>'.$field['foreignKeyEntity'].'</info>'); }
            if ($field['foreignKeyShow'] != ""){ $io->writeln('Chave Estrangeira - Mostrar: <info>'.$field['foreignKeyShow'].'</info>'); }
            if ($field['foreignKeyStore'] != ""){ $io->writeln('Chave Estrangeira - Salvar: <info>'.$field['foreignKeyStore'].'</info>'); }
            $io->writeln('');
            $io->writeln('-------------------------------');
        }

        return $config;
    }





//
//     ▄▄▄▄▄▄▄▄▄▄▄  ▄         ▄  ▄▄        ▄  ▄▄▄▄▄▄▄▄▄▄▄  ▄▄▄▄▄▄▄▄▄▄▄  ▄▄▄▄▄▄▄▄▄▄▄  ▄▄▄▄▄▄▄▄▄▄▄  ▄▄        ▄  ▄▄▄▄▄▄▄▄▄▄▄
//    ▐░░░░░░░░░░░▌▐░▌       ▐░▌▐░░▌      ▐░▌▐░░░░░░░░░░░▌▐░░░░░░░░░░░▌▐░░░░░░░░░░░▌▐░░░░░░░░░░░▌▐░░▌      ▐░▌▐░░░░░░░░░░░▌
//    ▐░█▀▀▀▀▀▀▀▀▀ ▐░▌       ▐░▌▐░▌░▌     ▐░▌▐░█▀▀▀▀▀▀▀▀▀  ▀▀▀▀█░█▀▀▀▀  ▀▀▀▀█░█▀▀▀▀ ▐░█▀▀▀▀▀▀▀█░▌▐░▌░▌     ▐░▌▐░█▀▀▀▀▀▀▀▀▀
//    ▐░▌          ▐░▌       ▐░▌▐░▌▐░▌    ▐░▌▐░▌               ▐░▌          ▐░▌     ▐░▌       ▐░▌▐░▌▐░▌    ▐░▌▐░▌
//    ▐░█▄▄▄▄▄▄▄▄▄ ▐░▌       ▐░▌▐░▌ ▐░▌   ▐░▌▐░▌               ▐░▌          ▐░▌     ▐░▌       ▐░▌▐░▌ ▐░▌   ▐░▌▐░█▄▄▄▄▄▄▄▄▄
//    ▐░░░░░░░░░░░▌▐░▌       ▐░▌▐░▌  ▐░▌  ▐░▌▐░▌               ▐░▌          ▐░▌     ▐░▌       ▐░▌▐░▌  ▐░▌  ▐░▌▐░░░░░░░░░░░▌
//    ▐░█▀▀▀▀▀▀▀▀▀ ▐░▌       ▐░▌▐░▌   ▐░▌ ▐░▌▐░▌               ▐░▌          ▐░▌     ▐░▌       ▐░▌▐░▌   ▐░▌ ▐░▌ ▀▀▀▀▀▀▀▀▀█░▌
//    ▐░▌          ▐░▌       ▐░▌▐░▌    ▐░▌▐░▌▐░▌               ▐░▌          ▐░▌     ▐░▌       ▐░▌▐░▌    ▐░▌▐░▌          ▐░▌
//    ▐░▌          ▐░█▄▄▄▄▄▄▄█░▌▐░▌     ▐░▐░▌▐░█▄▄▄▄▄▄▄▄▄      ▐░▌      ▄▄▄▄█░█▄▄▄▄ ▐░█▄▄▄▄▄▄▄█░▌▐░▌     ▐░▐░▌ ▄▄▄▄▄▄▄▄▄█░▌
//    ▐░▌          ▐░░░░░░░░░░░▌▐░▌      ▐░░▌▐░░░░░░░░░░░▌     ▐░▌     ▐░░░░░░░░░░░▌▐░░░░░░░░░░░▌▐░▌      ▐░░▌▐░░░░░░░░░░░▌
//     ▀            ▀▀▀▀▀▀▀▀▀▀▀  ▀        ▀▀  ▀▀▀▀▀▀▀▀▀▀▀       ▀       ▀▀▀▀▀▀▀▀▀▀▀  ▀▀▀▀▀▀▀▀▀▀▀  ▀        ▀▀  ▀▀▀▀▀▀▀▀▀▀▀



    private function selectTemplate(SymfonyStyle $io): string
    {
        $finder = new Finder();
        $finder->files()->in('templates')->name('index.html.twig');

        $templates = [];
        foreach ($finder as $file) {
            $templates[] = $file->getRelativePathname();
        }

        return 'templates/' . $io->choice('Select the index.html.twig file to modify:', $templates);
    }

    /**
     * Check if the field is boolean based on type or specific names.
     */
    private function isBooleanField(string $field): bool
    {
        // Define common boolean field names
        $booleanFieldNames = ['status', 'active', 'appearInHome'];

        // Normalize field name for comparison
        $normalizedField = strtolower($field);

        // Check for specific field names
        if (in_array($normalizedField, $booleanFieldNames, true)) {
            return true;
        }

        // Retrieve metadata for the entity and check field type
        $metadata = $this->entityManager->getClassMetadata($this->entityClass);
        if ($metadata->hasField($field)) {
            $fieldMapping = $metadata->getFieldMapping($field);
            return $fieldMapping['type'] === 'boolean';
        }

        return false;
    }

    /**
     * Retrieve a list of all entity classes in the project.
     */
    private function getAllEntities(): array
    {
        $finder = new Finder();
        $finder->files()->in('src/Entity')->name('*.php');

        $entityClasses = [];
        foreach ($finder as $file) {
            $relativePath = $file->getRelativePathname();
            $className = str_replace('/', '\\', 'App\\Entity\\' . str_replace('.php', '', $relativePath));
            if (class_exists($className)) {
                $entityClasses[] = $className;
            }
        }

        return $entityClasses;
    }

    private function locateSiblingTemplates(string $indexPath): array
    {
        // Diretório onde o index.html.twig está localizado
        $directory = dirname($indexPath);

        // Procurar arquivos irmãos
        $finder = new Finder();
        $finder->files()->in($directory)->name(['_delete_form.html.twig', '_form.html.twig', 'edit.html.twig', 'new.html.twig']);

        $siblings = [];
        foreach ($finder as $file) {
            $siblings[$file->getFilename()] = $file->getRealPath();
        }

        return $siblings;
    }

    private function getEnumNameByRealPath(string $realPath): string{
        // Substitui tudo até "Enum/" por uma string vazia
        $enum = $result = basename($realPath);
        $enum = str_replace('.php','',$enum);
        return $enum;
    }

    /**
     * Função para inserir texto em um ponto específico dentro de um conteúdo.
     *
     * @param string $content O texto original.
     * @param string $novoTexto O texto que será adicionado.
     * @param string $depoisDe A string que indica o início da busca.
     * @param string $antesDe A string que marca onde o texto será inserido.
     * @return string O conteúdo atualizado.
     */
    private function inserirTexto(string $content, string $novoTexto, string $depoisDe, string $antesDe): string
    {
        // Explode o conteúdo em linhas para facilitar a manipulação
        $linhas = explode("\n", $content);
        $novoConteudo = [];
        $encontrouDepoisDe = false;

        foreach ($linhas as $linha) {
            // Verifica se encontrou o marcador "depoisDe"
            if (strpos($linha, $depoisDe) !== false) {
                $encontrouDepoisDe = true;
            }

            // Se encontrou "depoisDe" e a linha atual contém "antesDe"
            if ($encontrouDepoisDe && strpos($linha, $antesDe) !== false) {
                // Insere o novo texto antes da linha atual
                $novoConteudo[] = $novoTexto;
                $encontrouDepoisDe = false; // Reseta o estado para evitar múltiplas inserções
            }

            // Adiciona a linha atual ao novo conteúdo
            $novoConteudo[] = $linha;
        }

        // Retorna o conteúdo atualizado como uma única string
        return implode("\n", $novoConteudo);
    }

    private function showMessageGalery(){
        echo PHP_EOL.PHP_EOL.PHP_EOL;
        $this->io->section('Atenção com as imagens');
        $this->io->writeln('Para que o sistema de galeria de imagens funcione, você precisa respeitar estas regras:');
        $this->io->writeln('');
        $this->io->writeln(' - Deve existir uma <info>Entity Image</info> com os métodos <info>set'.$this->className.'()</info> e <info>get'.$this->className.'()</info>' );
        $this->io->writeln(' - Deve existir uma <info>Entity Image</info> com a propriedade  <info>#[ORM\ManyToOne(inversedBy: \'images\')] private ?'.$this->className.' '.$this->classNameCamelCase.' = null; </info>' );
        $this->io->writeln(' - A Entity <info>'.$this->entityClass.'</info> deve possuir: ' );
        $this->io->writeln('      -     private Collection $images;' );
        $this->io->writeln('      -     com     <info>#[ORM\OneToMany(targetEntity: Image::class, mappedBy: \''.$this->classNameCamelCase.'\', cascade:["persist"])]</info>' );
        $this->io->writeln('      -     public function getImages(): Collection' );
        $this->io->writeln('      -     public function addImage(Image $image): static' );
        $this->io->writeln('      -     public function removeImage(Image $image): static' );


    }

}