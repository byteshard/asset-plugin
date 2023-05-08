<?php
/**
 * @copyright  Copyright (c) 2009 Bespin Studios GmbH
 * @license    See LICENSE file that is distributed with this source code
 */

namespace byteShard\AssetPlugin;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Exception;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

class ComposerScripts implements PluginInterface, EventSubscriberInterface
{
    /**
     * @param NpmAssetType $npmAssetType
     * @param NpmAssets $rootNpmAssets
     * @return array
     */
    protected static function getResource(NpmAssetType $npmAssetType, NpmAssets $rootNpmAssets): array
    {
        return match ($npmAssetType) {
            NpmAssetType::scripts         => $rootNpmAssets->getScripts(),
            NpmAssetType::dependencies    => $rootNpmAssets->getDependencies(),
            NpmAssetType::devDependencies => $rootNpmAssets->getDevDependencies(),
        };
    }

    /**
     * @param Composer $composer
     */
    public static function publishAssets(Composer $composer): void
    {
        $extra     = $composer->getPackage()->getExtra();
        $vendorDir = $composer->getConfig()->get('vendor-dir');

        $publicPath = 'public';
        if (isset($extra['public-path'])) {
            $publicPath = $extra['public-path'];
        }

        $rootDir               = realpath($vendorDir.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR);
        $publicPathFromRootDir = $rootDir.DIRECTORY_SEPARATOR.$publicPath.DIRECTORY_SEPARATOR;
        if (!is_dir($publicPathFromRootDir)) {
            mkdir($publicPathFromRootDir, 0777, true);
        }
        ComposerScripts::copyDir($vendorDir.'/byteshard/ui/src/public', $publicPathFromRootDir, true);
    }

    /**
     * @param string $src
     * @param string $dst
     * @param bool $force - force overwriting
     */
    static function copyDir(string $src, string $dst, bool $force = false): void
    {
        $dir = opendir($src);
        @mkdir($dst);

        // Loop through the files in source directory
        while ($file = readdir($dir)) {
            if (($file != '.') && ($file != '..')) {
                if (is_dir($src.'/'.$file)) {
                    ComposerScripts::copyDir($src.'/'.$file, $dst.'/'.$file, $force);
                } elseif ($force || !file_exists($dst.'/'.$file)) {
                    copy($src.'/'.$file, $dst.'/'.$file);
                }
            }
        }

        closedir($dir);
    }


    /**
     * Installs npm assets
     *
     * @param Composer $composer
     * @param IOInterface $io
     * @throws Exception
     */
    private static function installAssets(Composer $composer, IOInterface $io): void
    {
        $npmAssets = self::collectNpmAssets($composer);
        if (!$npmAssets) {
            return;
        }

        $willSomethingChange = false;
        $filesystem          = new Filesystem();

        if ($filesystem->exists('package.json')) {
            try {
                $packageJsonContent = file_get_contents('package.json');
            } catch (Exception $exception) {
                throw new Exception(
                    'Can not read "package.json" file, '.
                    'make sure the user has permission to read it', 0, $exception
                );
            }
            try {
                $packageJson = json_decode($packageJsonContent, false, 512, JSON_THROW_ON_ERROR);
            } catch (Exception $exception) {
                throw new Exception(
                    'Can not parse "package.json" file, '.
                    'make sure it has valid JSON structure', 0, $exception
                );
            }

            foreach (NpmAssetType::cases() as $assetTypes) {
                $futureResources  = $npmAssets[$assetTypes->value];
                $currentResources = isset($packageJson->{$assetTypes->value}) ? (array)$packageJson->{$assetTypes->value} : [];
                if (self::checkExistingDependencies($currentResources, $npmAssets[$assetTypes->value], $io, $assetTypes) === true) {
                    $willSomethingChange               = true;
                    $packageJson->{$assetTypes->value} = array_merge($currentResources, $futureResources);
                }
            }
        } else {
            $willSomethingChange = true;
            $packageJson         = [
                'description'     => 'Assets for byteShard',
                'scripts'         => $npmAssets[NpmAssetType::scripts->value],
                'dependencies'    => $npmAssets[NpmAssetType::dependencies->value],
                'devDependencies' => $npmAssets[NpmAssetType::devDependencies->value],
                'private'         => true
            ];
        }

        if ($willSomethingChange === true) {
            $filesystem->dumpFile('package.json', json_encode($packageJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
            self::dropPackageLockFile();
            $isVerbose = $io->isVerbose();
            self::npmInstall($io, $isVerbose);
        }
    }

    /**
     * Updates npm assets
     *
     * @throws Exception
     */
    private static function dropPackageLockFile(): void
    {
        $filesystem = new Filesystem();
        $filesystem->remove('package-lock.json');
    }

    /**
     * Collects npm assets from extra.npm section of installed packages.
     *
     * @param Composer $composer
     * @return array
     * @throws Exception
     */
    private static function collectNpmAssets(Composer $composer): array
    {
        $rootPackage = $composer->getPackage();

        // Gets array of installed packages.
        $packages = $composer->getRepositoryManager()->getLocalRepository()->getCanonicalPackages();

        $npmAssets = [];
        foreach (NpmAssetType::cases() as $npmAssetType) {
            $npmAssets[$npmAssetType->value] = [];
        }
        $rootNpmAssets = new NpmAssets($rootPackage->getExtra());

        foreach ($packages as $package) {
            $packageNpm = new NpmAssets($package->getExtra());
            foreach (NpmAssetType::cases() as $npmAssetType) {
                if (!isset($npmAssets[$npmAssetType->value][$package->getName()])) {
                    $packageResource      = self::getResource($npmAssetType, $packageNpm);
                    $rootResource         = self::getResource($npmAssetType, $rootNpmAssets);
                    $conflictingResources = array_diff_key(array_intersect_key($packageResource, $npmAssets), $rootResource);

                    if (!empty($conflictingResources)) {
                        throw new Exception(
                            'There are some conflicting npm resources, type: '.$npmAssetType->value.' resources: '.
                            implode('", "', array_keys($conflictingResources))
                        );
                    }

                    $npmAssets[$npmAssetType->value] = array_merge($npmAssets[$npmAssetType->value], $packageResource);
                }
            }
        }
        foreach (NpmAssetType::cases() as $npmAssetType) {
            $npmAssets[$npmAssetType->value] = array_merge($npmAssets[$npmAssetType->value], self::getResource($npmAssetType, $rootNpmAssets));
            ksort($npmAssets, SORT_STRING | SORT_FLAG_CASE);
        }

        return $npmAssets;
    }

    /**
     * Runs "npm install", updates package-lock.json, installs assets to "node_modules/"
     */
    private static function npmInstall(IOInterface $inputOutput, bool $verbose = false, int $timeout = 60,): void
    {
        $logLevel      = $verbose ? 'info' : 'error';
        $npmInstallCmd = ['npm', 'install', '--no-audit', '--save-exact', '--no-optional', '--loglevel', $logLevel];

        if (self::runProcess($inputOutput, ['which', 'npm'], $timeout) !== 0) {
            $inputOutput->writeError('<warning>npm is not installed, please run "npm install" on your own</warning>');
        } else {
            if (self::runProcess($inputOutput, $npmInstallCmd, $timeout, true) !== 0) {
                throw new RuntimeException('Failed to generate package-lock.json');
            }
        }
    }

    private static function runProcess(IOInterface $inputOutput, array $cmd, int $timeout, bool $output = false): int
    {
        if ($output === true) {
            $inputOutput->write(implode(' ', $cmd));
        }

        $command = new Process($cmd, null, null, null, $timeout);
        $command->run(function ($outputType, string $data) use ($inputOutput, $output) {
            if ($output === true) {
                if ($outputType === Process::OUT) {
                    $inputOutput->write($data, false);
                } else {
                    $inputOutput->writeError($data, false);
                }
            }
        });

        return $command->getExitCode();
    }

    /**
     * @param array $dependencies
     * @param array $npmAssets
     * @param IOInterface $inputOutput
     * @param NpmAssetType $assetType
     * @return bool
     */
    private static function checkExistingDependencies(array $dependencies, array $npmAssets, IOInterface $inputOutput, NpmAssetType $assetType): bool
    {
        $willSomethingChange = false;
        foreach ($npmAssets as $package => $newVersion) {
            if (array_key_exists($package, $dependencies)) {
                $version = $dependencies[$package];
                if ($newVersion !== $version) {
                    $willSomethingChange = true;
                    $inputOutput->write('update field '.$package.' from version '.$version.' to '.$newVersion.' at '.$assetType->value.' in package.json');
                }
            } else {
                $willSomethingChange = true;
                $inputOutput->write('add field '.$package.' with version '.$newVersion.' to '.$assetType->value.' in package.json');
            }
        }
        $filesystem = new Filesystem();

        if (!$filesystem->exists('node_modules')) {
            $willSomethingChange = true;
        }
        return $willSomethingChange;
    }

    public function activate(Composer $composer, IOInterface $io) { }

    public function deactivate(Composer $composer, IOInterface $io)
    {
        // TODO: Implement deactivate() method.
    }

    public function uninstall(Composer $composer, IOInterface $io)
    {
        // TODO: Implement uninstall() method.
    }

    public static function getSubscribedEvents()
    {
        return [
            'post-install-cmd' => 'installOrUpdate',
            'post-update-cmd'  => 'installOrUpdate',
        ];
    }

    public function installOrUpdate(Event $event)
    {
        self::installAssets($event->getComposer(), $event->getIO());
        self::publishAssets($event->getComposer());
    }
}
