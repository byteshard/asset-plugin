<?php
/**
 * @copyright  Copyright (c) 2009 Bespin Studios GmbH
 * @license    See LICENSE file that is distributed with this source code
 */

namespace byteShard\AssetPlugin;

class NpmAssets
{
    private array $devDependencies = [];
    private array $dependencies    = [];
    private array $scripts         = [];

    public function __construct(array $composerExtra)
    {
        $npmExtra = $composerExtra['npm'] ?? null;
        if (isset($npmExtra) && is_array($npmExtra)) {
            foreach (NpmAssetType::cases() as $assetType) {
                $this->{$assetType->value} = $npmExtra[$assetType->value] ?? [];
            }

            // Legacy npm extra
            foreach ($npmExtra as $key => $item) {
                if (NpmAssetType::tryFrom($key) === null) {
                    $this->dependencies[$key] = $item;
                }
            }
        }
    }

    /**
     * @return array
     */
    public function getDevDependencies(): array
    {
        return $this->devDependencies;
    }

    /**
     * @return array
     */
    public function getDependencies(): array
    {
        return $this->dependencies;
    }

    /**
     * @return array
     */
    public function getScripts(): array
    {
        return $this->scripts;
    }


}