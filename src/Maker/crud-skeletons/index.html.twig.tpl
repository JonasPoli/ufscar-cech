{% extends 'admin/base.html.twig' %}

{% block title %%ENTITY_HUMAN_NAME_PLURAL%% %}

{% block stylesheets %}
    {{ parent() }}
    <link rel="stylesheet" href="https://cdn.datatables.net/2.0.8/css/dataTables.tailwindcss.css">
{% endblock %}

{% block body %}
    <sl-card>
        <div slot="header" class="flex justify-between items-center">
            <h2 class="text-xl font-bold">{{ '%%ENTITY_HUMAN_NAME_PLURAL%%' }}</h2>
            <a href="{{ path('app_admin_%%ENTITY_SNAKE_CASE%%_new') }}">
                <sl-button variant="primary">
                    <sl-icon slot="prefix" name="plus-lg"></sl-icon>
                    Novo
                </sl-button>
            </a>
        </div>

        <table id="crud-table" class="display" style="width:100%">
            <thead>
                <tr>
                    %%TABLE_HEADERS%%
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody>
            {% for %%ENTITY_VAR_SINGULAR%% in %%ENTITY_VAR_PLURAL%% %}
                <tr>
                    %%TABLE_BODY%%
                    <td>
                        <div class="flex gap-2">
                            <a href="{{ path('app_admin_%%ENTITY_SNAKE_CASE%%_show', {'id': %%ENTITY_VAR_SINGULAR%%.id}) }}"><sl-button variant="neutral" size="small"><sl-icon name="eye"></sl-icon></sl-button></a>
                            <a href="{{ path('app_admin_%%ENTITY_SNAKE_CASE%%_edit', {'id': %%ENTITY_VAR_SINGULAR%%.id}) }}"><sl-button variant="neutral" size="small"><sl-icon name="pencil-square"></sl-icon></sl-button></a>
                        </div>
                    </td>
                </tr>
            {% else %}
                <tr>
                    <td colspan="%%COLSPAN%%">Nenhum registro encontrado.</td>
                </tr>
            {% endfor %}
            </tbody>
        </table>
    </sl-card>
{% endblock %}

{% block javascripts %}
    {{ parent() }}
    <script src="https://cdn.datatables.net/2.0.8/js/dataTables.js"></script>
    <script src="https://cdn.datatables.net/2.0.8/js/dataTables.tailwindcss.js"></script>
    <script>
        $(document).ready(function() {
            new DataTable('#crud-table', {
                layout: {
                    topEnd: {
                        search: {
                            placeholder: 'Buscar...'
                        }
                    }
                },
                "language": {
                    "lengthMenu": "Exibindo _MENU_ resultados por página",
                    "zeroRecords": "Nenhum resultado encontrado",
                    "info": "Exibindo página _PAGE_ de _PAGES_",
                    "infoEmpty": "Nenhum resultado disponível",
                    "infoFiltered": "(filtrado de _MAX_ resultados totais)"
                }
            });
        });
    </script>
{% endblock %}
