{% extends 'admin/base.html.twig' %}

{% block body %}

<div class="lg:col-span-4 p-4 block rounded shadow bg-white/50 dark:bg-white/10 backdrop-blur text-right mb-4"><sl-button href="{{ path('<?= $route_name ?>_new') }}" variant="success" outline>Novo</sl-button></div>

<div class="grid gap-6 lg:grid-cols-4"> 

    <div class="lg:col-span-4 p-6 block rounded shadow bg-white/50 dark:bg-white/10 backdrop-blur">
        <p class="text-lg font-bold mb-4"><?= $entity_class_name ?></p>

        <table class="w-full text-left">
            <thead>
                <tr>
                    <?php foreach ($entity_fields as $field): ?>
                        <th class="py-1 px-2 border-b"><?= ucfirst($field['fieldName']) ?></th>
                    <?php endforeach; ?>
                    <th class="py-1 px-2 border-b"></th>
                </tr>
            </thead>
            <tbody>
                {% for <?= $entity_twig_var_singular ?> in <?= $entity_twig_var_plural ?> %}
                    <tr class="hover:bg-slate-900/5 dark:hover:bg-slate-100/10">
                        <?php foreach ($entity_fields as $field): ?>
                            <td class="py-1 px-2 border-t">{{ <?= $helper->getEntityFieldPrintCode($entity_twig_var_singular, $field) ?> }}</td>
                        <?php endforeach; ?>
                        <td class="py-1 px-2 border-t "><sl-button href="{{ path('<?= $route_name ?>_edit', {'<?= $entity_identifier ?>': <?= $entity_twig_var_singular ?>.<?= $entity_identifier ?>}) }}" size="small" variant="success" outline>Editar</sl-button></td>
                    </tr>
                {% else %}
                    <tr>
                        <td colspan="<?= (count($entity_fields) + 1) ?>">Nenhum registro</td>
                    </tr>
                {% endfor %}
            </tbody>
        </table>

    </div>
</div>

{% endblock %}