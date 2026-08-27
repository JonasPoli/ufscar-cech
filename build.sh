#!/bin/bash
set -e

# Auto-detect PHP binary if not specified
if [ -z "$PHP_BIN" ]; then
    if [ -x "/RunCloud/Packages/php84rc/bin/php" ]; then
        PHP_BIN="/RunCloud/Packages/php84rc/bin/php"
    elif [ -x "/RunCloud/Packages/php83rc/bin/php" ]; then
        PHP_BIN="/RunCloud/Packages/php83rc/bin/php"
    elif [ -x "/RunCloud/Packages/RunCloud-PHP84rc/bin/php" ]; then
        PHP_BIN="/RunCloud/Packages/RunCloud-PHP84rc/bin/php"
    elif [ -x "/RunCloud/Packages/RunCloud-PHP83rc/bin/php" ]; then
        PHP_BIN="/RunCloud/Packages/RunCloud-PHP83rc/bin/php"
    elif command -v php8.4 &> /dev/null; then
        PHP_BIN="php8.4"
    elif command -v php8.3 &> /dev/null; then
        PHP_BIN="php8.3"
    else
        PHP_BIN="php"
    fi
fi

echo "Using PHP: $($PHP_BIN -v | head -n 1)"

$PHP_BIN bin/console tailwind:build --minify
$PHP_BIN bin/console asset-map:compile

