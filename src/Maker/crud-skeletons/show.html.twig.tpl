{% extends 'admin/base.html.twig' %}

{% block title %%ENTITY_HUMAN_NAME_SINGULAR%% %}

{% block body %}
    <sl-card>
        <div slot="header">
            <h2 class="text-xl font-bold">Detalhes de %%ENTITY_HUMAN_NAME_SINGULAR%%</h2>
        </div>

        <table class="table-auto w-full">
            <tbody>
                %%SHOW_FIELDS%%
            </tbody>
        </table>

        <div class="flex justify-between mt-6">
            <a href="{{ path('app_admin_%%ENTITY_SNAKE_CASE%%_index') }}">
                <sl-button variant="neutral" outline>
                    <sl-icon slot="prefix" name="arrow-left"></sl-icon>
                    Voltar para a lista
                </sl-button>
            </a>
            <a href="{{ path('app_admin_%%ENTITY_SNAKE_CASE%%_edit', {'id': %%ENTITY_VAR_SINGULAR%%.id}) }}">
                <sl-button variant="neutral">
                    <sl-icon slot="prefix" name="pencil-square"></sl-icon>
                    Editar
                </sl-button>
            </a>
        </div>

    </sl-card>
{% endblock %}
