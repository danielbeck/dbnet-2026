<?php
declare(strict_types=1);

final class Types
{
    public static function all(): array
    {
        return [
            'article' => ['label' => 'Article', 'fields' => [], 'templates' => [
                'detail' => 'pool/article/detail',
                'list' => 'pool/article/list',
                'summary' => 'pool/article/summary',
                'image' => 'pool/article/image',
            ]],
            'photo' => ['label' => 'Photo', 'fields' => [
                'f' => ['label' => 'Aperture', 'type' => 'text'],
                'mm' => ['label' => 'Focal length', 'type' => 'text'],
                'iso' => ['label' => 'ISO', 'type' => 'text'],
                'shutter' => ['label' => 'Shutter speed', 'type' => 'text'],
            ], 'templates' => [
                'detail' => 'pool/photo/detail',
                'list' => 'pool/photo/list',
                'summary' => 'pool/photo/summary',
                'image' => 'pool/photo/image',
            ]],
            'project' => ['label' => 'Project', 'fields' => [
                'url' => ['label' => 'URL', 'type' => 'url'],
                'github_url' => ['label' => 'GithubURL', 'type' => 'url'],
            ], 'templates' => [
                'detail' => 'pool/project/detail',
                'list' => 'pool/project/list',
                'summary' => 'pool/project/summary',
                'image' => 'pool/project/image',
            ]],
        ];
    }

    public static function get(string $type): array
    {
        return self::all()[$type] ?? self::all()['article'];
    }

    public static function templates(string $type): array
    {
        return self::get($type)['templates'] ?? [];
    }

    public static function template(string $type, string $view): string
    {
        $template = self::templates($type)[$view] ?? null;
        if (!is_string($template) || $template === '') {
            throw new InvalidArgumentException('Unknown pool template "' . $view . '" for item type "' . $type . '".');
        }
        return $template;
    }
}
