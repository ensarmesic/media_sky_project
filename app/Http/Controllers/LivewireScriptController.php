<?php

namespace App\Http\Controllers;

use Livewire\Drawer\Utils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Livewire JS, isti kao iz paketa, uz jednu izmjenu: tekst "<body" je zapisan kao "<\u0062ody" (JS escape za slovo b).
 *
 * Razlog: staging server (Apache) ubacuje HTML trake "STAGING ENVIRONMENT" iza svakog "<body>" koji nađe,
 * i to i u JavaScriptu. Livewire JS taj tekst ima u jednoj poruci, pa bi ubačeni HTML pokvario cijeli fajl
 * (SyntaxError, Livewire i Alpine se ne pokreću). JavaScript taj escape čita kao obično slovo b, pa se ponašanje ne mijenja.
 * Može se ukloniti kad se filter na serveru ograniči samo na text/html.
 */
class LivewireScriptController
{
    public function __invoke(): Response
    {
        $file = base_path('vendor/livewire/livewire/dist/'.(config('app.debug') ? 'livewire.js' : 'livewire.min.js'));
        $content = str_ireplace('<body', '<'.chr(92).'u0062ody', (string) file_get_contents($file));

        return Utils::pretendResponseIsFileFromString($content, filemtime($file), basename($file));
    }
}
