<?php

$root = dirname(__DIR__).'/.ai/skills';
$directories = glob($root.'/*', GLOB_ONLYDIR) ?: [];
$failures = [];

foreach ($directories as $directory) {
    $name = basename($directory);
    $skillFile = $directory.'/SKILL.md';

    if (preg_match('/^[a-z0-9-]{1,64}$/', $name) !== 1) {
        $failures[] = "{$name}: invalid directory name";
    }

    if (! is_file($skillFile)) {
        $failures[] = "{$name}: missing SKILL.md";

        continue;
    }

    $content = file_get_contents($skillFile);

    if ($content === false
        || preg_match('/^---\s*\nname:\s*([^\n]+)\ndescription:\s*([^\n]+)\n---\s*\n/s', $content, $matches) !== 1) {
        $failures[] = "{$name}: invalid frontmatter";

        continue;
    }

    if (trim($matches[1]) !== $name || trim($matches[2]) === '') {
        $failures[] = "{$name}: name or description mismatch";
    }

    if (! is_file($directory.'/agents/openai.yaml')) {
        $failures[] = "{$name}: missing agents/openai.yaml";
    }
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures).PHP_EOL);
    exit(1);
}

fwrite(STDOUT, 'Validated '.count($directories)." project skills.\n");
