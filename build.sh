#!/bin/bash
php bin/console tailwind:build --minify
php bin/console asset-map:compile
