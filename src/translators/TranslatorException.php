<?php

namespace studionoir\translingua\translators;

use yii\base\Exception;

/**
 * Thrown when a provider can't fulfil a translation request.
 */
class TranslatorException extends Exception
{
    public function getName(): string
    {
        return 'Translator error';
    }
}
