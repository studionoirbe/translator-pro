<?php

namespace studionoir\translingua\assetbundles\cp;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

/**
 * Styles for the plugin's own control panel screens.
 *
 * Kept separate from {@see FieldTranslateAsset} because that one only loads once
 * an AI connection is verified — the static translation screens need their
 * styling regardless of whether anyone has bought the Plus edition.
 */
class StylesAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';

        $this->depends = [
            CpAsset::class,
        ];

        $this->css = [
            'translingua.css',
        ];

        parent::init();
    }
}
