<form method="post" action="{{ path('app_admin_%%ENTITY_SNAKE_CASE%%_delete', {'id': %%ENTITY_VAR_SINGULAR%%.id}) }}" onsubmit="return confirm('Tem certeza que deseja excluir este item?');">
    <input type="hidden" name="_token" value="{{ csrf_token('delete' ~ %%ENTITY_VAR_SINGULAR%%.id) }}">
    <sl-button type="submit" variant="danger" outline>
        <sl-icon slot="prefix" name="trash"></sl-icon>
        Excluir
    </sl-button>
</form>
