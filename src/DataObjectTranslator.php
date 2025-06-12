<?php

namespace BenkIT\DeepLTranslation;

use SilverStripe\ORM\DB;
use SilverStripe\ORM\DataList;
use DeepL\TranslateTextOptions;
use SilverStripe\ORM\DataObject;
use SilverStripe\Core\Environment;
use TractorCow\Fluent\Model\Locale;
use SilverStripe\Core\Config\Config;
use SilverStripe\ORM\FieldType\DBField;
use TractorCow\Fluent\State\FluentState;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Security\PermissionProvider;

class DataObjectTranslator implements PermissionProvider
{
    use Injectable;

    protected $object;
    protected $translation_texts;
    protected $translation_results;

    public function __construct(DataObject $object = null)
    {
        $this->object = $object;
    }

    public function translateObject($locale_from, $locale_to, $recursive = false)
    {
        if (!$this->object instanceof DataObject)
            throw new \Exception('No DataObject set for ' . __CLASS__);

        // ToDo can this be a problem?
        FluentState::singleton()->withState(function (FluentState $state) use ($locale_from, $locale_to, $recursive) {
            $state->setLocale($locale_to);

            $this->translation_texts = [];
            $this->collectTexts($this->object, $recursive);

            if (empty($this->translation_texts))
                throw new \Exception('No translation fields configured for ' . get_class($this->object));

            $texts = [];
            foreach ($this->translation_texts as $data) {
                if (!empty($data['value'])) {
                    $texts[] = $data['value'];
                }
            }
            $this->deeplTranslate($texts, $locale_from, $locale_to);

            $this->writeTranslationResult();
        });
    }

    public function getTexts($recursive = false)
    {
        $this->translation_texts = [];
        $this->collectTexts($this->object, $recursive);
        return $this->translation_texts;
    }

    protected function collectTexts(DataObject $object, $recursive = false, $lang = null)
    {
        if (!$this->object instanceof DataObject)
            throw new \Exception('No DataObject set for ' . __CLASS__);

        if (!$lang) {
            $lang = Locale::getDefault()->Locale;
        }

        $fields = $object->getLocalisedTables();
        $translate = [];
        foreach ($fields as $fieldArray) {
            $translate = array_merge($translate, $fieldArray);
        }
        $global_ignore = Config::inst()->get('deepl_translate_ignore') ?: [];
        $ignore = $object->config()->get('deepl_translate_ignore') ?: [];
        $actual_ignore = array_unique(array_merge($global_ignore, $ignore));

        $translate = array_diff($translate, $actual_ignore);

        if (empty($translate))
            return;

        $record = FluentState::singleton()->withState(function(FluentState $state) use ($object, $lang, $translate, $recursive) {
            $state->setLocale($lang);
            $record = $object->ClassName::get()->byID($object->ID);
            foreach ($translate as $part) {
                $fieldOrObject = $record->obj($part);
                if ($fieldOrObject instanceof DBField && $fieldOrObject->getValue()) {
                    // $this->text_collection[$object->ClassName][$object->ID][$part] = $fieldOrObject->getValue(); // might be useful in the future?

                    $md5 = md5("$record->ClassName--$record->ID--$part");
                    $this->translation_texts[$md5] = [
                        'class' => $record->ClassName,
                        'id' => $record->ID,
                        'field' => $part,
                        'value' => "<t id=\"$md5\">{$fieldOrObject->getValue()}</t>"
                    ];
                } else if ($recursive) {
                    if ($fieldOrObject instanceof DataObject && $fieldOrObject->isInDB()) {
                        $this->collectTexts($fieldOrObject, true);
                    } else {
                        try {
                            $list = $object->getComponents($part);
                            foreach ($list as $component) {
                                $this->collectTexts($component, true);
                            }
                        } catch (\Exception $e) {
                        }
                    }
                }
            }
        });
    }

    /**
     * @param string | string[] $text
     * @param string $locale_from e.g. 'de_DE' or 'en_US'
     * @param string $locale_to e.g. 'de_DE' or 'en_US'
     * @return \DeepL\TextResult[]
     * @throws \DeepL\DeepLException
     */
    protected function deeplTranslate($text, $locale_from, $locale_to)
    {
        if (!$authKey = Environment::getEnv('DEEPL_API_KEY'))
            throw new \Exception('env DEEPL_API_KEY not set');

        $translator = new \DeepL\Translator($authKey);

        //source language format is 'de', 'en', etc.
        $locale_from = explode('_', $locale_from)[0];

        // some target languages need to be specific, see https://developers.deepl.com/docs/resources/supported-languages#target-languages
        if (in_array($locale_to, ['en_GB', 'en_US', 'pt_PT', 'pt_BR'])) {
            // DeepL uses en-US instead of en_US
            $locale_to = str_replace('_', '-', $locale_to);
        } else if (in_array(explode('_', $locale_to)[0], ['en', 'pt'])) {
            // ToDo non-standard en,pt languages need fallback
        } else {
            $locale_to = explode('_', $locale_to)[0];
        }

        $options = [
            TranslateTextOptions::TAG_HANDLING => 'xml',
            TranslateTextOptions::PRESERVE_FORMATTING => true
        ];

        $results = $translator->translateText($text, $locale_from, $locale_to, $options);

        foreach ($results as $result) {
            $pattern = '/<t id="(\w+)">(.*)<\/t>/s';
            preg_match($pattern, $result->text, $matches);
            $source = $this->translation_texts[$matches[1]];
            $this->translation_results[$source['class']][$source['id']][$source['field']] = $matches[2];
        }

        return $results;
    }

    /**
     * @param \DeepL\TextResult[] $results
     * @param $locale_to
     * @return void
     */
    protected function writeTranslationResult()
    {
        foreach ($this->translation_results as $class => $ids) {
            foreach ($ids as $objectID => $fields) {
                DataObject::get_by_id($class, $objectID)
                    ->update($fields)
                    ->write();
            }
        }
    }

    public const PERMISSION_DEEPL_TRANSLATE = 'DEEPL_TRANSLATE';

    public function providePermissions()
    {
        return [
            self::PERMISSION_DEEPL_TRANSLATE => [
                //                'name' => _t(__CLASS__ . '.ACCESSALLINTERFACES', 'Access to all CMS sections'),
                //                'category' => _t(Permission::class . '.CMS_ACCESS_CATEGORY', 'CMS Access'),
                //                'help' => _t(__CLASS__ . '.ACCESSALLINTERFACESHELP', 'Overrules more specific access settings.'),
                //                'sort' => -100
                'name' => 'Translate content with DeepL',
                'category' => _t(__CLASS__ . '.PERMISSION', 'Localisation')
            ]
        ];
    }

    /**
     * ToDo This function does not work from CLI. Somehow the elemens of the source pages gets translated. But running from the browser works. Why ??
     * list result might be cached after foreach ? Do something like $list = $list->exclude('ID', -1)
     * @param DataList $list
     * @param $locale_from
     * @param $locale_to
     * @return void
     * @throws \Exception
     */
    public static function translateList(DataList $list, $locale_from, $locale_to)
    {
        FluentState::singleton()->withState(function (FluentState $state) use ($list, $locale_from, $locale_to) {
            $state->setLocale($locale_from);
            $class = $list->dataClass();

            foreach ($list as $obj) {
                if ($obj->existsInLocale($locale_to)) {
                    DB::alteration_message("$class #$obj->ID '$obj->Title' already exists in $locale_to");
                } else {
                    DB::alteration_message("Copying $class #$obj->ID '$obj->Title' to $locale_to");
                    $obj->copyToLocale($locale_to);
                }
            }
        });

        FluentState::singleton()->withState(function (FluentState $state) use ($list, $locale_from, $locale_to) {
            $state->setLocale($locale_to);
            $class = $list->dataClass();

            foreach ($list as $obj) {
                if (!$obj->existsInLocale($locale_to)) {
                    DB::alteration_message("WARNING: $class #$obj->ID '$obj->Title' does not exist in $locale_to");
                    continue;
                }

                DB::alteration_message("Translating $class #$obj->ID '$obj->Title' with DeepL to $locale_to");
                DataObjectTranslator::create($obj)->translateObject($locale_from, $locale_to);
            }
        });
    }

    public static function countCharacters(DataList $list, $locale)
    {
        FluentState::singleton()->withState(function (FluentState $state) use ($list, $locale) {
            $state->setLocale($locale);
            $texts = [];
            $listCount = $charCount = 0;

            /** @var DataObject $obj */
            foreach ($list as $obj) {
                if (!$obj->existsInLocale($locale))
                    continue;

                $translator = DataObjectTranslator::create($obj);
                $listCount++;
                $objCount = 0;

                foreach ($translator->getTexts() as $t) {
                    $texts[] = $t['value'];
                    $objCount += strlen($t['value']);
                }

                $title = ($obj->hasMethod('Breadcrumbs'))
                    ? $obj->Breadcrumbs(5, true, false, true, $delimiter = ' // ')
                    : $obj->getTitle();


                DB::alteration_message("$obj->ClassName #$obj->ID {$title}: $objCount");
                $charCount += $objCount;
            }

            DB::alteration_message("<strong>$listCount {$list->dataClass()}s: $charCount</strong>");

            return $charCount;
        });
    }
}
