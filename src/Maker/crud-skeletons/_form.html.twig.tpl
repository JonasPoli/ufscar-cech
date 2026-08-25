{{ form_start(form) }}
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        %%FORM_FIELDS%%
    </div>

    <div class="flex justify-between mt-6">
        <sl-button type="submit" variant="primary">{{ button_label|default('Salvar') }}</sl-button>
        {{ include('admin/%%ENTITY_SNAKE_CASE%%/_delete_form.html.twig') }}
    </div>
{{ form_end(form) }}
