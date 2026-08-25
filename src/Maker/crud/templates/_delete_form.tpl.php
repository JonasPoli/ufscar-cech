<form method="post" action="{{ path('<?= $route_name ?>_delete', {'<?= $entity_identifier ?>': <?= $entity_twig_var_singular ?>.<?= $entity_identifier ?>}) }}" onsubmit="return confirm('Tem certeza de que quer apagar este registro?');" class="mt-4">
    <input type="hidden" name="_token" value="{{ csrf_token('delete' ~ <?= $entity_twig_var_singular ?>.<?= $entity_identifier ?>) }}">
    <sl-button type="submit" variant="neutral" class="mt-4" outline>Apagar</sl-button>
</form>
