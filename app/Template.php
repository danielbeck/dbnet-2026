<?php
declare(strict_types=1);

final class Template
{
    public static function render(string $name, array $variables = []): string
    {
        if (!preg_match('~^[a-zA-Z0-9_-]+(?:/[a-zA-Z0-9_-]+)*$~D', $name)) {
            throw new InvalidArgumentException('Invalid template name: ' . $name);
        }
        $file = dirname(__DIR__) . '/templates/' . $name . '.php';
        if (!is_file($file)) {
            throw new RuntimeException('Template not found: ' . $name);
        }
        return (static function (string $file, array $variables): string {
            extract($variables, EXTR_SKIP);
            ob_start();
            try {
                require $file;
                return ob_get_clean();
            } catch (Throwable $error) {
                ob_end_clean();
                throw $error;
            }
        })($file, $variables);
    }
}
