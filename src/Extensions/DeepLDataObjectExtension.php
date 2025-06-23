<?php

namespace BenkIT\DeepLTranslation\Extensions;

use SilverStripe\Forms\FieldList;
use SilverStripe\Control\Director;
use SilverStripe\ORM\DataExtension;
use TractorCow\Fluent\Model\Locale;
use LeKoala\CmsActions\CustomAction;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Security\Permission;
use TractorCow\Fluent\Extension\FluentExtension;
use BenkIT\DeepLTranslation\DataObjectTranslator;

class DeepLDataObjectExtension extends DataExtension
{
    public function updateCMSFields(FieldList $fields)
    {
        parent::updateCMSFields($fields);

        if (!$this->owner->hasExtension(FluentExtension::class))
        {
            $fields->unshift(LiteralField::create(
                'FluentExtensionMissing',
                sprintf(
                    '<p class="alert alert-warning">%s</p>',
                    _t(__CLASS__ . '.FluentExtensionMissing', 'FluentExtension is required for DeepLTranslation')
                )
            ));
        }
    }

    public function updateCMSActions(FieldList $actions)
    {
        if ($traslatable = $this->isMachineTranslatable())
        {

            if (!count($traslatable))
            {
                return $actions;
            }

            $buttonText = _t(__CLASS__ . '.TranslateAction', 'DeepL {from-locale} > {to-locale}', ['from-locale' => strtoupper(strtok($traslatable['from'], '_')), 'to-locale' => strtoupper(strtok($traslatable['to'], '_'))]);

            $actions->push($translateButton = new CustomAction("deeplTranslateObject", $buttonText));
                // $translateButton->setButtonIcon('translatable');
                $translateButton->setConfirmation(_t(__CLASS__ . '.TranslateConfirmation', 'Translate and overwrite content from {from-locale} to {to-locale}', ['from-locale' => $traslatable['from'], 'to-locale' => $traslatable['to']]));
                $translateButton->setShouldRefresh(true);

            return $actions;
        }
    }

    public function isMachineTranslatable(string $locale_from = null, string $locale_to = null): array
    {
        $arr = [];
        if ($currentLocale = Locale::getCurrentLocale())
        {
            $locale_from = $locale_from ?? Locale::getDefault();
            // ToDo: why is this ever needed?
            // on CLI we see a string, in CMS we see an object
            if (is_object($locale_from)) {
                $locale_from = $locale_from->Locale;
            }
            $locale_to = $locale_to ?? $currentLocale->Locale;

            if (
                $locale_to != $locale_from &&
                $locale_to != Locale::getDefault() &&
                $this->owner->existsInLocale($locale_to) &&
                (Director::is_cli() || Permission::check(DataObjectTranslator::PERMISSION_DEEPL_TRANSLATE))
            ) {
                $arr['from'] = $locale_from;
                $arr['to'] = $locale_to;
            }
        }
        return $arr;
    }

    public function deeplTranslateObject()
    {
        if (!$this->owner->hasExtension(FluentExtension::class))
            throw new \Exception('FluentExtension mission on ' . __CLASS__);

        $traslatable = $this->isMachineTranslatable();

        if (count($traslatable))
        {
            try {
                DataObjectTranslator::create($this->owner)
                    ->translateObject($traslatable['from'], $traslatable['to'], false);
                $message = _t(__CLASS__ . '.Translated', '{object} #{id} {title} translated from {from-locale} to {to-locale}', [
                    'object' => $this->owner->singular_name(),
                    'id' => $this->owner->ID,
                    'title' => $this->owner->Title,
                    'from-locale' => $traslatable['from'],
                    'to-locale' => $traslatable['to']
                ]);
                return $message;

            } catch (\Exception $e) {
                $message = _t(__CLASS__ . '.Translated', '{object} #{id} {title} Übersetzung: {error}', [
                    'object' => $this->owner->singular_name(),
                    'id' => $this->owner->ID,
                    'title' => $this->owner->Title,
                    'error' => $e->getMessage()
                ]);
                return false;
            }
        }
    }
}
