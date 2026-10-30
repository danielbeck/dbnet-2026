<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/EmbedProxy.php';

$cases = [
    ['/projects/poi-simulator.html', '', null],
    ['/embed/poi', '', 'http://nosebeep.com/poi/'],
    ['/embed/poi/', 'poi-speed=2&showControls=true', 'http://nosebeep.com/poi/?poi-speed=2&showControls=true'],
    ['/embed/poi/simulator.js', '', 'http://nosebeep.com/poi/simulator.js'],
    ['/embed/poi/assets/example.woff2', '', 'http://nosebeep.com/poi/assets/example.woff2'],
];
foreach ($cases as [$path, $query, $expected]) {
    if (EmbedProxy::upstream($path, $query) !== $expected) {
        throw new RuntimeException("Unexpected upstream for {$path}");
    }
}
foreach ([
    '/embed/unknown/', '/embed/poi/../index.php', '/embed/poi/./index.html',
    '/embed/poi/http://example.com/', '/embed/poi/a\\b', '/embed/poi/%2e%2e/',
] as $path) {
    try {
        EmbedProxy::upstream($path);
    } catch (InvalidArgumentException) {
        continue;
    }
    throw new RuntimeException("Unsafe path accepted: {$path}");
}
try {
    EmbedProxy::upstream('/embed/poi/', "x=1\r\nInjected: true");
    throw new RuntimeException('Invalid query accepted.');
} catch (InvalidArgumentException) {
}
echo "Embed proxy routing tests passed.\n";
