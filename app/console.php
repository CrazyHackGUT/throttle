#!/usr/bin/env php
<?php

$app = require_once __DIR__ . '/bootstrap.php';

$app->register(new Cilex\Provider\Console\Adapter\Silex\ConsoleServiceProvider(), array(
    'console.name' => 'Throttle',
    'console.version' => '0.0.0',
));

$output = new \Symfony\Component\Console\Output\ConsoleOutput();

if ($app['config'] === false) {
    return $app['console']->renderException(new \Exception('Missing configuration file, please see app/config.base.php'), $output);
}

/** @var \Symfony\Component\Console\Application $console */
$console = $app['console'];

$commands = id(new \FileFinder($app['root'] . '/src/Throttle/Command/'))->withType('f')->withSuffix('php')->find();

foreach ($commands as &$command) {
    if (!strncmp($command, 'Abstract', 8))
    {
        continue;
    }

    $command = '\\Throttle\\Command\\' . substr($command, 0, -4);
    $console->add(new $command);
}

$console->getHelperSet()->set(new \Doctrine\DBAL\Tools\Console\Helper\ConnectionHelper($app['db']), 'db');

$console->addCommands([
    new \Doctrine\DBAL\Migrations\Tools\Console\Command\ExecuteCommand,
    new \Doctrine\DBAL\Migrations\Tools\Console\Command\GenerateCommand,
    new \Doctrine\DBAL\Migrations\Tools\Console\Command\MigrateCommand,
    new \Doctrine\DBAL\Migrations\Tools\Console\Command\StatusCommand,
    new \Doctrine\DBAL\Migrations\Tools\Console\Command\VersionCommand,
]);

$console->run(null, $output);

