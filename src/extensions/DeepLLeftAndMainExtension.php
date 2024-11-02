<?php

namespace BenkIT\DeepLTranslation;

use SilverStripe\ORM\DataObject;
use TractorCow\Fluent\Model\Locale;
use SilverStripe\Control\Controller;
use SilverStripe\Versioned\Versioned;
use SilverStripe\Admin\LeftAndMainExtension;
use TractorCow\Fluent\Extension\FluentExtension;

/**
 * @property \SilverStripe\Admin\LeftAndMain owner
 */
class DeepLLeftAndMainExtension extends LeftAndMainExtension
{
    private static $allowed_actions = [
        'deeplTranslateObject'
    ];

    public function deeplTranslateObject()
    {
        $request  = $this->owner->getRequest();

        if (!Versioned::get_stage() == Versioned::DRAFT)
            return $this->sendMessage(_t(__CLASS__ . '.COPY_NOT_DRAFT', 'Only possible in draft mode'));

        if (!$locale_from = $request->postVar('DeeplSourceLocale'))
            return $this->sendMessage(_t(__CLASS__ . '.MissingCurrentLang', 'Please select "Current language"'));

        $locale_to = Locale::getCurrentLocale()->Locale;

        // GridField forms transport model information differently from PageEditForms
        if (!(($class = $request->postVar('ClassName')) && ($id = $request->postVar('ID')))) {
            $class = $request->getVar('ModelClass');
            $id = $request->getVar('ID');
        }

        $object = DataObject::get_by_id($class, $id);

        if (!$object->hasExtension(FluentExtension::class))
            throw new \Exception('FluentExtension mission on ' . __CLASS__);

        try {
            DataObjectTranslator::create($object)
                ->translateObject($locale_from, $locale_to);
            $message = _t(__CLASS__ . '.Transalted', '{object} #{id} {title} translated from {from-locale} to {to-locale}', [
                'object' => $object->singular_name(),
                'id' => $object->ID,
                'title' => $object->Title,
                'from-locale' => $locale_from,
                'to-locale' => $locale_to
            ]);
            return $this->sendMessage($message);
        } catch (\Exception $e) {
            $message = _t(__CLASS__ . '.Transalted', '{object} #{id} {title} Übersetzung: {error}', [
                'object' => $object->singular_name(),
                'id' => $object->ID,
                'title' => $object->Title,
                'error' => $e->getMessage()
            ]);
            return $this->sendMessage($message, 500);
        }
    }

    protected function sendMessage($message, $errorCode = null)
    {
        $response = Controller::curr()->getResponse();
        $response->addHeader('X-Status', rawurlencode($message));

        if ($errorCode)
            $this->owner->httpError($errorCode, $message);

        return $response;
    }
}
