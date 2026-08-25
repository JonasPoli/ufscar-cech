<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\Mapping\ClassMetadata;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\String\UnicodeString;

class CrudPolisher
{
    private EntityManagerInterface $entityManager;
    private Filesystem $filesystem;
    private string $projectDir;

    public function __construct(EntityManagerInterface $entityManager, ParameterBagInterface $params)
    {
        $this->entityManager = $entityManager;
        $this->filesystem = new Filesystem();
        $this->projectDir = $params->get('kernel.project_dir');
    }

    public function polish(string $entityClass): void
    {
        $entityMetadata = $this->entityManager->getClassMetadata($entityClass);
        $shortName = $entityMetadata->getReflectionClass()->getShortName();
        $listFields = $this->getEntityFields($entityMetadata);
        $formFields = $this->getFormFields($entityMetadata);

        $replacements = [
            '%%ENTITY_HUMAN_NAME_PLURAL%%' => $this->humanize($shortName) . 's',
            '%%ENTITY_HUMAN_NAME_SINGULAR%%' => $this->humanize($shortName),
            '%%ENTITY_SNAKE_CASE%%' => $this->getSnakeCase($shortName),
            '%%ENTITY_VAR_PLURAL%%' => lcfirst($shortName) . 's',
            '%%ENTITY_VAR_SINGULAR%%' => lcfirst($shortName),
            '%%COLSPAN%%' => count($listFields) + 1,
        ];

        $templateDir = $this->projectDir . '/templates/admin/' . $replacements['%%ENTITY_SNAKE_CASE%%'];
        if (!$this->filesystem->exists($templateDir)) {
            $this->filesystem->mkdir($templateDir);
        }

        $this->processIndexTemplate($templateDir, $replacements, $listFields);
        $this->processNewTemplate($templateDir, $replacements);
        $this->processEditTemplate($templateDir, $replacements);
        $this->processShowTemplate($templateDir, $replacements, $listFields);
        $this->processDeleteFormTemplate($templateDir, $replacements);
        $this->processFormTemplate($templateDir, $replacements, $formFields);
        $this->processBaseFormPageTemplate($templateDir, $replacements);
        $this->addLinkToAdminMenu($replacements);
    }

    private function processIndexTemplate(string $templateDir, array $replacements, array $fields): void
    {
        $headers = array_map(fn($field) => sprintf("<th>%s</th>", $this->humanize($field)), $fields);
        $replacements['%%TABLE_HEADERS%%'] = implode("\n                    ", $headers);

        $cells = array_map(function($field) use ($replacements) {
            if (str_contains(strtolower($field), 'imagefile')) {
                return sprintf("<td>{%% if %s.%s %%}<img src=\"{{ vich_uploader_asset(%s, '%s') | imagine_filter('admin_thumb') }}\" alt=\"\">{%% endif %%}</td>", $replacements['%%ENTITY_VAR_SINGULAR%%'], $field, $replacements['%%ENTITY_VAR_SINGULAR%%'], $field);
            }
            return sprintf("<td>{{ %%ENTITY_VAR_SINGULAR%%.%s }}</td>", $field);
        }, $fields);
        $replacements['%%TABLE_BODY%%'] = implode("\n                    ", $cells);

        $this->writeTemplate('index.html.twig', $templateDir, $replacements);
    }

    private function processNewTemplate(string $templateDir, array $replacements): void
    {
        $this->writeTemplate('new.html.twig', $templateDir, $replacements);
    }

    private function processEditTemplate(string $templateDir, array $replacements): void
    {
        $this->writeTemplate('edit.html.twig', $templateDir, $replacements);
    }

    private function processShowTemplate(string $templateDir, array $replacements, array $fields): void
    {
        $rows = array_map(function ($field) use ($replacements) {
            $th = sprintf("<th class='text-left p-2'>%s</th>", $this->humanize($field));
            if (str_contains(strtolower($field), 'imagefile')) {
                $td = sprintf("<td class='p-2'>{%% if %s.%s %%}<img src=\"{{ vich_uploader_asset(%s, '%s') | imagine_filter('admin_thumb') }}\" alt=\"\">{%% endif %%}</td>", $replacements['%%ENTITY_VAR_SINGULAR%%'], $field, $replacements['%%ENTITY_VAR_SINGULAR%%'], $field);
            } else {
                $td = sprintf("<td class='p-2'>{{ %%ENTITY_VAR_SINGULAR%%.%s }}</td>", $field);
            }
            return sprintf("<tr>\n                    %s\n                    %s\n                </tr>", $th, $td);
        }, $fields);
        $replacements['%%SHOW_FIELDS%%'] = implode("\n                ", $rows);

        $this->writeTemplate('show.html.twig', $templateDir, $replacements);
    }

    private function processDeleteFormTemplate(string $templateDir, array $replacements): void
    {
        $this->writeTemplate('_delete_form.html.twig', $templateDir, $replacements);
    }

    private function processFormTemplate(string $templateDir, array $replacements, array $fields): void
    {
        $formFields = array_map(fn($field) => sprintf("<div class='md:col-span-1'>{{ form_row(form.%s) }}</div>", $field), $fields);
        $replacements['%%FORM_FIELDS%%'] = implode("\n        ", $formFields);

        $this->writeTemplate('_form.html.twig', $templateDir, $replacements);
    }

    private function processBaseFormPageTemplate(string $templateDir, array $replacements): void
    {
        $this->writeTemplate('_form_page.html.twig', $templateDir, $replacements);
    }

    private function addLinkToAdminMenu(array $replacements): void
    {
        $baseTemplatePath = $this->projectDir . '/templates/admin/base.html.twig';
        $content = file_get_contents($baseTemplatePath);

        $routeSlug = 'app_admin_' . $replacements['%%ENTITY_SNAKE_CASE%%'] . '_index';
        if (str_contains($content, $routeSlug)) {
            return; // Link already exists
        }

        $entityName = ucfirst($replacements['%%ENTITY_SNAKE_CASE%%']);
        $humanName = $replacements['%%ENTITY_HUMAN_NAME_PLURAL%%'];

        $newLink = <<<TWIG

            {% set is_%%ENTITY_SNAKE_CASE%% = app.current_route starts with 'app_admin_%%ENTITY_SNAKE_CASE%%_' %}
            <a class="py-2 px-4 mx-4 mb-2 block rounded hover:shadow hover:shadowed hover:from-white hover:to-white/60 hover:bg-gradient-to-r hover:via-30% dark:hover:from-white/5 dark:hover:via-white/10 dark:hover:to-white/5 {% if is_%%ENTITY_SNAKE_CASE%% %}shadow bg-gradient-to-r via-30% from-white/40 via-white/90 to-white/50 dark:from-white/10 dark:via-white/20 dark:to-white/10{% endif %}" href="{{ path('$routeSlug') }}">
                <sl-icon name="collection" class="mr-2 align-text-top"></sl-icon>
                $humanName
            </a>
TWIG;
        $newLink = str_replace('%%ENTITY_SNAKE_CASE%%', $replacements['%%ENTITY_SNAKE_CASE%%'], $newLink);

        $position = strpos($content, '> Logout</a>');
        if ($position !== false) {
            $content = substr_replace($content, $newLink, $position, 0);
        }

        $this->filesystem->dumpFile($baseTemplatePath, $content);
    }

    private function writeTemplate(string $templateName, string $templateDir, array $replacements): void
    {
        $skeletonPath = $this->projectDir . '/src/Maker/crud-skeletons/' . $templateName . '.tpl';
        $content = $this->filesystem->exists($skeletonPath) ? file_get_contents($skeletonPath) : '';

        $finalContent = str_replace(array_keys($replacements), array_values($replacements), $content);

        $this->filesystem->dumpFile($templateDir . '/' . $templateName, $finalContent);
    }

    private function getEntityFields(ClassMetadata $entityMetadata): array
    {
        $fields = $entityMetadata->getFieldNames();
        // Exclude VichUploader fields, id, and timestamps from lists/show pages.
        return array_filter($fields, fn($field) => !in_array($field, ['id', 'createdAt', 'updatedAt', 'imageName', 'imageSize']));
    }

    private function getFormFields(ClassMetadata $entityMetadata): array
    {
        $fields = $entityMetadata->getFieldNames();
        // Exclude id and timestamps from forms.
        return array_filter($fields, fn($field) => !in_array($field, ['id', 'createdAt', 'updatedAt', 'imageName', 'imageSize']));
    }

    private function humanize(string $string): string
    {
        $string = (new UnicodeString($string))->snake()->toString();
        return ucfirst(str_replace('_', ' ', $string));
    }

    private function getSnakeCase(string $string): string
    {
        return (new UnicodeString($string))->snake()->toString();
    }

    public function getAvailableEntities(): array
    {
        $entityDir = $this->projectDir . '/src/Entity';
        $finder = new \Symfony\Component\Finder\Finder();
        $finder->files()->in($entityDir)->name('*.php');

        $entities = [];
        foreach ($finder as $file) {
            $className = 'App\\Entity\\' . str_replace('.php', '', $file->getFilename());
            if (class_exists($className)) {
                $entities[] = $className;
            }
        }

        return $entities;
    }
}