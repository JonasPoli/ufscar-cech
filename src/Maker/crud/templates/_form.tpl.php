{{ form_start(form) }}
    <div class="lg:col-span-4 p-6 block rounded shadow bg-white/50 dark:bg-white/10 backdrop-blur mb-4">
        {{ form_widget(form) }}        

        {# <label>foo</label>
        {{ form_widget(form.foo, {'attr': {'class':'w-full p-2 h-10 mb-4 border focus:outline-0 focus:border-slate-400 dark:border-slate-100/30 dark:bg-slate-800 bg-white'}}) }} #}

        <sl-button type="submit" value="list" variant="primary" outline>Salvar</sl-button>
    </div>
{{ form_end(form) }}