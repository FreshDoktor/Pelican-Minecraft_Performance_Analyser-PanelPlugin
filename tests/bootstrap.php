<?php

/*
 * Unit tests of the plain PHP classes (Diagnostics, MetricsHistory, SvgChart, FakeBridge::route), without
 * Laravel: the plugin's classes are loaded from src, and trans() is a stub that returns the key and its
 * replacements, so the tests can check which text with which values a rule picks.
 *
 * Run with PHPUnit 12: phpunit -c phpunit.xml.dist
 */

spl_autoload_register(function (string $class) {
    $prefix = 'FreshDoktor\\MinecraftPerformanceAnalyser\\';

    if (str_starts_with($class, $prefix)) {
        $file = dirname(__DIR__).'/src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';

        if (is_file($file)) {
            require $file;
        }
    }
});

if (!function_exists('trans')) {
    /**
     * @param  array<string, scalar>  $replace
     */
    function trans(?string $key = null, array $replace = [], ?string $locale = null): string
    {
        return $key.($replace === [] ? '' : ' '.json_encode($replace, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
