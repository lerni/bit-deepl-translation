<?php

namespace BenkIT\DeepLTranslation;

use LeKoala\CmsActions\CmsInlineFormAction;
use LeKoala\CmsActions\CustomAction;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Controller;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\LiteralField;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Permission;
use SilverStripe\Versioned\Versioned;
use TractorCow\Fluent\Model\Locale;

class DeepLDataObjectExtension extends \SilverStripe\ORM\DataExtension {
    private static $deepl_cms_tab = 'Root.Deepl';
    private static $db = [
        'DeeplTranslationStatus' => "Enum('marked, excluded, translated', null)"
    ];

    public function updateCMSFields(\SilverStripe\Forms\FieldList $fields) {
        parent::updateCMSFields($fields);

        $tab = $this->getCMSTab();

        if (! Permission::check(DataObjectTranslator::PERMISSION_DEEPL_TRANSLATE)) {
            $fields->addFieldsToTab($tab, [
                LiteralField::create('DeeplTargetLocaleHint', "Keine Berechtigung"),
            ]);

            return;
        }

        if (! Versioned::get_stage() == Versioned::DRAFT) {
            $fields->addFieldsToTab($tab, [
                LiteralField::create('DeeplTargetLocaleHint', "Bereits veröffentlichte Daten können nicht übersetzt werden."),
            ]);

            return;
        }

        $fields->addFieldsToTab($tab, [
            LiteralField::create('DeeplTargetLocaleHint', "<div>Zielsprache: <strong>".Locale::getCurrentLocale()->Title."</strong></div>"),
            $statusField = DropdownField::create('DeeplTranslationStatus', 'Status', $this->owner->singleton()->dbObject('DeeplTranslationStatus')->enumValues())
        ]);

        if ($this->owner->DeeplTranslationStatus != 'translated') {
            $statusField->setEmptyString('(bitte wählen');

            $fields->addFieldsToTab($tab, [
                DropdownField::create('DeeplSourceLocale', null, Locale::get()->map('Locale', 'Title'))
                    ->setDisabledItems([Locale::getCurrentLocale()->Locale])
                    ->setEmptyString('(Aktuelle Sprache der Inhalte auswählen'),

                $action = CmsInlineFormAction::create('deeplTranslateObject', 'Übersetzen')
                    ->setPost(true)
            ]);

            $submitSelector = ($this->owner instanceof SiteTree)
                ? '#Form_EditForm_action_save'
                : '#Form_ItemEditForm_action_doSave';
            $action->setSubmitSelector($submitSelector);

        } else {
            $statusField->setDisabled(true);
        }
    }

    protected function getCMSTab() {
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
