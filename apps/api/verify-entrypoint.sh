#!/bin/sh
set -eu

# A base-image welcome page can return 200 for every path, including /up.
if [ ! -f public/index.php ] || ! grep -Fq "define('LARAVEL_START'," public/index.php || ! grep -Fq 'bootstrap/app.php' public/index.php || ! grep -Fq 'handleRequest' public/index.php; then
    echo 'Laravel entrypoint missing or replaced at public/index.php; refusing this artifact.' >&2
    exit 1
fi
