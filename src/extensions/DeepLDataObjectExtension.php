<?php

namespace BenkIT\DeepLTranslation;

use SilverStripe\Forms\FieldList;
use SilverStripe\ORM\DataExtension;
use TractorCow\Fluent\Model\Locale;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Security\Permission;
use SilverStripe\Versioned\Versioned;
use LeKoala\CmsActions\CmsInlineFormAction;
use TractorCow\Fluent\Extension\FluentExtension;
use BenkIT\DeepLTranslation\DataObjectTranslator;

class DeepLDataObjectExtension extends DataExtension
{
    private static $db = [
        'DeeplTranslationStatus' => "Enum('marked, excluded, translated', null)"
    ];

    private static $deepl_cms_tab = 'Root.Deepl';

    public function updateCMSFields(FieldList $fields)
    {
        parent::updateCMSFields($fields);

        $tab = $this->getCMSTab();

        if (!Permission::check(DataObjectTranslator::PERMISSION_DEEPL_TRANSLATE)) {
            $fields->addFieldsToTab($tab, [
                LiteralField::create('DeeplTargetLocaleHint', _t(__CLASS__ . '.MissingPermission', 'Missing permission to translate.')),
            ]);
        }

        if (!Versioned::get_stage() == Versioned::DRAFT) {
            $fields->addFieldsToTab($tab, [
                LiteralField::create('DeeplTargetLocaleHint', _t(__CLASS__ . '.OnlyDraft', 'Published data cannot be translated.')),
            ]);
        }

        if ($this->owner->hasExtension(FluentExtension::class))
        {

            if ($locale = Locale::getCurrentLocale()) {
                $statusField = DropdownField::create('DeeplTranslationStatus', _t(__CLASS__ . '.STATUS', 'Status'), $this->owner->singleton()->dbObject('DeeplTranslationStatus')->enumValues());
                $statusField->setEmptyString(_t(__CLASS__ . '.SelectEmptyString', 'Choose status'));

                $fields->addFieldsToTab($tab, [
                    LiteralField::create('DeeplTargetLocaleHint', _t(__CLASS__ . '.TargetLanguage', 'Target language: {lang}', ['lang' => $locale->Title])),
                    $statusField
                ]);
            }

            // if ($this->owner->DeeplTranslationStatus != 'translated') {

                $fields->addFieldsToTab($tab, [
                    DropdownField::create('DeeplSourceLocale', _t(__CLASS__ . '.DEEPLSOURCELOCALE', 'Source locale'), Locale::get()->map('Locale', 'Title'))
                        ->setDisabledItems([Locale::getCurrentLocale()->Locale])
                        ->setEmptyString(_t(__CLASS__ . '.SelectLanguageCurrentLangEmptyString', 'Select source language')),

                    $action = CmsInlineFormAction::create('deeplTranslateObject', _t(__CLASS__ . '.TranslateAction', 'Translate'))
                        ->setParams(['ModelClass' => $this->owner->ClassName, 'ID' => $this->owner->ID])
                        ->setButtonIcon('translatable')
                        ->setPost(true)
                ]);

                $submitSelector = ($this->owner instanceof SiteTree)
                    ? '#Form_EditForm_action_save'
                    : '#Form_ItemEditForm_action_doSave';
                $action->setSubmitSelector($submitSelector);
            // } else {
            //     $statusField->setDisabled(true);
            // }
        } else {
            $fields->addFieldToTab($tab,
                LiteralField::create(
                    'FluentExtensionMissing',
                    sprintf(
                        '<p class="alert alert-warning">%s</p>',
                        _t(__CLASS__ . '.FluentExtensionMissing', 'FluentExtension is required for DeepLTranslation')
                    )
                )
            );
        }
    }

    protected function getCMSTab()
    {
        return $this->owner->config()->get('deepl_cms_tab');
    }

    // ToDo The part below tries to prevent the implicit "save' button click, which leads to 'has changed' Pages, when they haven't
    // atm it seem to fail
    //    public function updateCMSActions(\SilverStripe\Forms\FieldList $actions) {
    //        parent::updateCMSActions($actions);
    //
    //        $actions->push(CustomAction::create("doCustomAction", "My custom action"));
    //
    //        return $actions;
    //    }
    //
    //    public function doCustomAction() {
    //        return true;
    ////        return 'Done!';
    ////        throw new Exception("Show this error");
    //    }
}
