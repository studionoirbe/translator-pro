<?php

use craft\ecs\SetList;
use Symplify\EasyCodingStandard\Config\ECSConfig;

return ECSConfig::configure()
    ->withRootFiles()
    ->withPaths([__DIR__ . '/src'])
    ->withSets([SetList::CRAFT_CMS_4]);
