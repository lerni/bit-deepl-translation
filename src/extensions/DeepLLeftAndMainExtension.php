<?php

namespace BenkIT\DeepLTranslation;

use SilverStripe\Control\Controller;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;
use TractorCow\Fluent\Model\Locale;

/**
 * @property \SilverStripe\Admin\LeftAndMain owner
 */
class DeepLLeftAndMainExtension extends \SilverStripe\Admin\LeftAndMainExtension {
    private static $allowed_actions = [
        'deeplTranslateObject'
    ];

    public function deeplTranslateObject() {
        $request  = $this->owner->getRequest();

        if (! Versioned::get_stage() == Versioned::DRAFT)
            return $this->sendMessage(_t('BenkIT\Elemental.COPY_NOT_DRAFT', 'Only possible in draft mode'));

        if (! $locale_from = $request->postVar('DeeplSourceLocale'))
            return $this->sendMessage('Bitte \'Aktuelle Sprache\' wählen');

        $locale_to = Locale::getCurrentLocale()->Locale;

        // GridField forms transport model information differently from PageEditForms
        if (! (($class = $request->postVar('ClassName')) && ($id = $request->postVar('ID')))) {
            $class = str_replace('-', '\\', $request->getVar('ModelClass'));
            $id = $request->getVar('ID');
        }

        try {
            $object = DataObject::get_by_id($class, $id);
            DataObjectTranslator::create($object)
                ->translateObject($locale_from, $locale_to);
            return $this->sendMessage("{$object->singular_name()} #$object->ID $object->Title übersetzt nach $locale_to");

        } catch (\Exception $e) {
            return $this->sendMessage("{$object->singular_name()} #$object->ID $object->Title Übersetzung: {$e->getMessage()}", 500);
        }
    }

    protected function sendMessage($message, $errorCode = null) {
        $response = Controller::curr()->getResponse();
        $response->addHeader('X-Status', rawurlencode($message));

        if ($errorCode)
            $this->owner->httpError($errorCode, $message);

        return $response;
    }
}
