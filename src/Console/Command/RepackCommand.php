<?php

namespace Castor\Console\Command;

use Castor\FunctionFinder;
use Castor\PathHelper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * @internal
 */
class RepackCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('repack')
            ->addOption('app-name', null, InputOption::VALUE_REQUIRED, 'The name of the phar application', 'my-app')
            ->addOption('app-version', null, InputOption::VALUE_REQUIRED, 'The version of the phar application', '1.0.0')
            ->addOption('os', null, InputOption::VALUE_REQUIRED, 'The targeted OS', 'linux', ['linux', 'macos', 'windows'])
            ->setHidden(true)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (str_starts_with(__FILE__, 'phar:')) {
            throw new \RuntimeException('This command cannot be run from a phar. You must install castor with its sources.');
        }

        $os = $input->getOption('os');
        if (!\in_array($os, ['linux', 'macos', 'windows'])) {
            throw new \RuntimeException('The os option must be one of linux, macos or windows.');
        }

        $finder = new ExecutableFinder();
        $box = $finder->find('box');
        if (!$box) {
            throw new \RuntimeException('Could not find box. Please install it: https://github.com/box-project/box/blob/main/doc/installation.md#installation.');
        }

        $castorSourceDir = PathHelper::realpath(__DIR__ . '/../../..');

        $boxConfigFile = "{$castorSourceDir}/tools/phar/box.{$os}-amd64.json";
        if (!file_exists($boxConfigFile)) {
            throw new \RuntimeException('Could not find the phar configuration.');
        }

        $appName = $input->getOption('app-name');
        $appVersion = $input->getOption('app-version');
        $alias = 'alias.phar';
        $main = <<<PHP
            <?php

            require __DIR__ . '/vendor/autoload.php';

            use Castor\\Console\\ApplicationFactory;
            use Castor\\Console\\Application;

            class RepackedApplication extends Application
            {
                const NAME = '{$appName}';
                const VERSION = '{$appVersion}';
                const ROOT_DIR = 'phar://{$alias}';
            }

            ApplicationFactory::create()->run();
            PHP;

        $boxConfig = json_decode((string) file_get_contents($boxConfigFile), true, 512, \JSON_THROW_ON_ERROR);
        $boxConfig['base-path'] = '.';
        $boxConfig['main'] = '.main.php';
        $boxConfig['alias'] = $alias;
        $boxConfig['output'] = sprintf('%s.%s.phar', $appName, $os);
        // update all paths to point to the castor source
        foreach (['files', 'files-bin', 'directories', 'directories-bin'] as $key) {
            if (!\array_key_exists($key, $boxConfig)) {
                continue;
            }
            $boxConfig[$key] = [
                ...array_map(
                    fn (string $file): string => $castorSourceDir . '/' . $file,
                    $boxConfig[$key] ?? []
                ),
            ];
        }
        // add all files from the FunctionFinder, this is usefull if the file
        // are in a hidden directory, because it's not included by default by
        // box
        $boxConfig['files'] = [
            ...array_map(
                fn (string $file): string => str_replace(PathHelper::getRoot() . '/', '', $file),
                FunctionFinder::$files,
            ),
            ...$boxConfig['files'] ?? [],
        ];

        file_put_contents('.box.json', json_encode($boxConfig, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));
        file_put_contents('.main.php', $main);

        $process = new Process([$box, 'compile', '--config=.box.json']);

        try {
            $process->mustRun(fn ($type, $buffer) => print($buffer));
        } finally {
            unlink('.box.json');
            unlink('.main.php');
        }

        return Command::SUCCESS;
    }
}
