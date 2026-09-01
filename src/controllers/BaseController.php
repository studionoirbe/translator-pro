<?php

namespace studionoir\translingua\controllers;

use craft\web\Controller;
use studionoir\translingua\assetbundles\cp\StylesAsset;

/**
 * Shared setup for the plugin's control panel screens.
 */
abstract class BaseController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        if ($this->request->getIsCpRequest() && !$this->request->getAcceptsJson()) {
            $this->view->registerAssetBundle(StylesAsset::class);
        }

        return true;
    }
}
