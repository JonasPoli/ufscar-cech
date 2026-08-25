{% extends 'admin/base.html.twig' %}

{% block body %}
    
        <p class="text-lg font-bold mb-4"><?= $entity_class_name ?></p>
        {{ include('<?= $templates_path ?>/_form.html.twig', {'button_label': 'Update'}) }}
    
    <sl-button href="{{ path('<?= $route_name ?>_index') }}" variant="secondary" class="mt-4" outline>Voltar</sl-button>
    {{ include('<?= $templates_path ?>/_delete_form.html.twig') }}
{% endblock %}
