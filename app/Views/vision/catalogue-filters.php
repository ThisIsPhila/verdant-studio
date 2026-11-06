<aside class="vision-filter-column">
    <details class="filter-disclosure vision-filter-disclosure">
        <summary>Filter plants &amp; products</summary>
        <form action="/catalogue" method="get" class="vision-filter-form">
            <div class="vision-results-heading"><h2>Filters</h2><a href="/catalogue">Clear all</a></div>
            <?php foreach ($filterPanel['fields'] ?? [] as $field): ?>
                <div class="vision-filter-group">
                    <?php if (($field['type'] ?? '') === 'select'): ?>
                        <fieldset><legend><?= $e($field['label']) ?></legend>
                            <?php foreach ($field['options'] as $option): ?><label><input type="radio" name="<?= $e($field['name']) ?>" value="<?= $e($option['value']) ?>" <?= (string) $field['value'] === (string) $option['value'] ? 'checked' : '' ?>> <?= $e($option['label']) ?></label><?php endforeach ?>
                        </fieldset>
                    <?php else: $component = $field; require __DIR__ . '/../components/form-field.php'; endif ?>
                </div>
            <?php endforeach ?>
            <?php if (!empty($filters['sort'])): ?><input type="hidden" name="sort" value="<?= $e($filters['sort']) ?>"><?php endif ?>
            <?php if (!empty($filters['q'])): ?><input type="hidden" name="q" value="<?= $e($filters['q']) ?>"><?php endif ?>
            <button type="submit" class="btn-solid">Apply filters</button>
        </form>
    </details>
</aside>
