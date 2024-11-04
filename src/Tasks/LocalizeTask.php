<?php

namespace BenkIT\DeepLTranslation\Tasks;

use SilverStripe\ORM\DB;
use SilverStripe\Dev\BuildTask;
use SilverStripe\Core\ClassInfo;
use SilverStripe\ORM\DataObject;
use TractorCow\Fluent\Model\Locale;
use TractorCow\Fluent\Extension\FluentExtension;
use BenkIT\DeepLTranslation\Extensions\DeepLDataObjectExtension;

class CopyTranslationTask extends BuildTask
{

    protected $title = "Localize Task";
    protected $description = 'Localize every single Fluent Object to a per parameter given locale and also translates it with DeepL but for both confirmations is needed as parameter.';
    private static $segment = 'localize-objects';

    public function run($request)
    {
        if (!class_exists('TractorCow\Fluent\Model\Locale')) {
            DB::alteration_message("The module 'tractorcow/silverstripe-fluent' is not installed.", "error");
            return;
        }

        $confirmLocalize = $request->getVar('confirmLocalize');
        $confirmLocalize = filter_var($confirmLocalize, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
        $confirmTranslate = $request->getVar('confirmTranslate');
        $confirmTranslate = filter_var($confirmTranslate, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;

        $locale_from = Locale::getDefault()->Locale;
        $locale_to = Locale::getCurrentLocale()->Locale;

        DB::alteration_message("Locale from is always default locale: $locale_from");
        DB::alteration_message("Locale can be set a parameter (?l=en_US): $locale_to");
        DB::alteration_message("To actually write/translate set parameter 'confirmLocalize' or 'confirmTranslate' to true");

        if ($locale_from == $locale_to) {
            DB::alteration_message("local_from needs to differ to locale_to", "error");
            return;
        }

        $fluentExtensionOn = ClassInfo::classesWithExtension(FluentExtension::class, DataObject::class);
        if (!empty($fluentExtensionOn) && $confirmLocalize) {
            foreach ($fluentExtensionOn as $class => $value) {
                $value::get()->each(function ($do) use ($locale_to, $confirmLocalize) {
                    if (!$do->existsInLocale($locale_to)) {
                        // we check this late, because we want to print the message also on dry runs
                        if ($confirmLocalize == true) {
                            $do->copyToLocale($locale_to);
                        }
                        DB::alteration_message("$do->ClassName #$do->ID $do->Title' copied to $locale_to");
                    }
                });
            }
        }

        $deeplExtensionOn = ClassInfo::classesWithExtension(DeepLDataObjectExtension::class, DataObject::class);
        if (!empty($deeplExtensionOn)) {
            foreach ($deeplExtensionOn as $class => $value) {
                $value::get()->each(function ($do) use ($locale_from, $locale_to, $confirmTranslate) {
                    if ($do->existsInLocale($locale_to)) {
                        // we check this late, because we want to print the message also on dry runs
                        if ($confirmTranslate == true) {
                            $do->deeplTranslateObject($locale_from, $locale_to);
                        }
                        DB::alteration_message("$do->ClassName #$do->ID $do->Title' translated to $locale_to");
                    }
                });
            }
        }
    }
}
