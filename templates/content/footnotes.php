<ol class="footnotes"><?php foreach ($notes as $number => $note): ?><li id="footnote-<?= Content::escape($prefix) ?><?= $number ?>"><span data-footnote-content><?= $note ?></span> <a href="#footnote-ref-<?= Content::escape($prefix) ?><?= $number ?>" aria-label="Back to reference">↩</a></li><?php endforeach; ?></ol>
<template data-footnote-panel>
    <aside class="footnote-popup" popover="auto" role="dialog" tabindex="-1">
        <!-- <button type="button" class="footnote-close" aria-label="Close footnote" autofocus>[ x ]</button> -->
        <div data-footnote-panel-content></div>
    </aside>
</template>
