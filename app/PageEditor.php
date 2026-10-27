<?php
declare(strict_types=1);

final class PageEditor
{
    public static function render(PDO $db, ?array $page, string $cancelUrl = '/admin/'): string
    {
        $id = $page['id'] ?? '';
        $reserved = in_array($id, ['Home', 'archive'], true);
        $parentOptions = [];
        $descendants = pageDescendants($db, $id);
        foreach (pages($db) as $candidate) {
            if ($candidate['id'] === $id || in_array($candidate['id'], $descendants, true)) {
                continue;
            }
            $parentOptions[] = [
                'id' => $candidate['id'],
                'selected' => $page && $page['parent_id'] === $candidate['id'],
                'label' => $candidate['title'] . ' (' . Content::pagePath($db, $candidate) . ')',
            ];
        }
        return Template::render('admin/page-form', [
            'page' => $page,
            'id' => $id,
            'reserved' => $reserved,
            'parentOptions' => $parentOptions,
            'cancelUrl' => $cancelUrl,
        ]);
    }
}
