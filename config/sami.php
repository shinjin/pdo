<?php
use Sami\Sami;
use Symfony\Component\Finder\Finder;

$iterator = Finder::create()
    ->files()
    ->name('*.php')
    ->in(__DIR__ . '/../src')
;

return new Sami($iterator, array(
    'theme'                => 'default',
    'title'                => 'Shinjin/Pdo API',
    'build_dir'            => __DIR__ . '/../docs/api',
    'cache_dir'            => __DIR__ . '/../build/sami',
    'default_opened_level' => 2
));
