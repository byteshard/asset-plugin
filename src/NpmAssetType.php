<?php
/**
 * @copyright  Copyright (c) 2009 Bespin Studios GmbH
 * @license    See LICENSE file that is distributed with this source code
 */

namespace byteShard\AssetPlugin;

enum NpmAssetType: string
{
    case dependencies = 'dependencies';
    case devDependencies = 'devDependencies';
    case scripts = 'scripts';
}