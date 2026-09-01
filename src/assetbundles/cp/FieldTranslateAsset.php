<?php

namespace studionoir\translingua\assetbundles\cp;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

/**
 * The translate buttons injected into every control panel page.
 *
 * Only registered once the AI provider connection has been verified.
 */
class FieldTranslateAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';

        $this->depends = [
            CpAsset::class,
            StylesAsset::class,
        ];

        $this->js = [
            'translingua.js',
        ];

        parent::init();
    }
}
