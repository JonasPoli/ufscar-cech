<?php

ini_set('memory_limit', '512M');

use App\Kernel;
use App\Support\PageCacheFastPath;

// Página pública em cache: responde daqui mesmo, sem dar partida no Symfony. Fora do cache, robôs acima do limite
// por minuto recebem 429 antes de custar uma renderização.
// (o autoload_runtime.php inclui este arquivo de novo para obter o kernel: o atalho roda só na primeira passagem)
if (PHP_SAPI !== 'cli' && !defined('CECH_FAST_PATH_CHECKED')) {
    define('CECH_FAST_PATH_CHECKED', true);
    require_once dirname(__DIR__) . '/src/Support/PageCacheFastPath.php';

    if (PageCacheFastPath::serve(dirname(__DIR__), $_SERVER, $_GET, $_COOKIE)) {
        return;
    }

    if (PageCacheFastPath::throttleBot(dirname(__DIR__), $_SERVER)) {
        PageCacheFastPath::sendTooManyRequests();

        return;
    }
}

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

return function (array $context) {
    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
