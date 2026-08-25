{% extends 'admin/base.html.twig' %}

{% block title %%PAGE_TITLE%% %}

{% block body %}
    <sl-card>
        <div slot="header">
            <h2 class="text-xl font-bold">%%PAGE_TITLE%%</h2>
        </div>

        {{ include('admin/%%ENTITY_SNAKE_CASE%%/_form.html.twig', {
            'button_label': '%%BUTTON_LABEL%%'
        }) }}

        <div class="mt-4">
            <a href="{{ path('app_admin_%%ENTITY_SNAKE_CASE%%_index') }}">
                <sl-button variant="neutral" outline>
                    <sl-icon slot="prefix" name="arrow-left"></sl-icon>
                    Voltar para a lista
                </sl-button>
            </a>
        </div>
    </sl-card>
{% endblock %}
