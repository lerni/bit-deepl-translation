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
        if (!$this->object instanceof DataObject) {
            throw new \Exception('No DataObject set for ' . __CLASS__);
        }

        // ToDo can this be a problem?
        FluentState::singleton()->withState(function (FluentState $state) use ($locale_from, $locale_to, $recursive) {
            $state->setLocale($locale_to);

            $this->translation_texts = [];
            $this->collectTexts($this->object, $recursive);

            if (empty($this->translation_texts)) {
                throw new \Exception('No translation fields configured for ' . get_class($this->object));
            }

            $this->deeplTranslate($locale_from, $locale_to);

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
        if (!$this->object instanceof DataObject) {
            throw new \Exception('No DataObject set for ' . __CLASS__);
        }

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

        if (empty($translate)) {
            return;
        }

        $record = FluentState::singleton()->withState(function (FluentState $state) use ($object, $lang, $translate, $recursive) {
            $state->setLocale($lang);
            $record = $object->ClassName::get()->byID($object->ID);
            foreach ($translate as $part) {
                $fieldOrObject = $record->obj($part);
                if ($fieldOrObject instanceof DBField && $fieldOrObject->getValue()) {
                    // $this->text_collection[$object->ClassName][$object->ID][$part] = $fieldOrObject->getValue(); // might be useful in the future?

                    $md5 = md5("$record->ClassName--$record->ID--$part");
                    $rawValue = $fieldOrObject->getValue();
                    // HTML fields already have entities encoded; plain text fields need escaping for valid XML
                    $isHtmlField = $fieldOrObject instanceof \SilverStripe\ORM\FieldType\DBHTMLText
                        || $fieldOrObject instanceof \SilverStripe\ORM\FieldType\DBHTMLVarchar;
                    // HTML fields are sent directly with html tag handling (supports <br>, &nbsp;, etc.)
                    // Plain text fields are wrapped in <t> tags for xml tag handling
                    $value = $isHtmlField
                        ? $rawValue
                        : '<t id="' . $md5 . '">' . htmlspecialchars($rawValue, ENT_XML1, 'UTF-8') . '</t>';
                    $this->translation_texts[$md5] = [
                        'class' => $record->ClassName,
                        'id' => $record->ID,
                        'field' => $part,
                        'isHtmlField' => $isHtmlField,
                        'value' => $value,
                    ];
                } elseif ($recursive) {
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
     * Translate all collected texts via DeepL.
     * HTML fields use html tag handling; plain text fields use xml tag handling with <t> wrappers.
     *
     * @throws \DeepL\DeepLException
     */
    protected function deeplTranslate($locale_from, $locale_to)
    {
        if (!$authKey = Environment::getEnv('DEEPL_API_KEY')) {
            throw new \Exception('env DEEPL_API_KEY not set');
        }

        $translator = new \DeepL\Translator($authKey);

        // Source language format is 'de', 'en', etc.
        $locale_from = explode('_', $locale_from)[0];

        // Some target languages need to be specific, see https://developers.deepl.com/docs/resources/supported-languages#target-languages
        if (in_array($locale_to, ['en_GB', 'en_US', 'pt_PT', 'pt_BR'])) {
            $locale_to = str_replace('_', '-', $locale_to);
        } elseif (in_array(explode('_', $locale_to)[0], ['en', 'pt'])) {
            // ToDo non-standard en,pt languages need fallback
        } else {
            $locale_to = explode('_', $locale_to)[0];
        }

        // Split into HTML and plain text batches
        $htmlKeys = [];
        $htmlTexts = [];
        $textKeys = [];
        $textTexts = [];

        foreach ($this->translation_texts as $md5 => $data) {
            if (empty($data['value'])) {
                continue;
            }

            if (!empty($data['isHtmlField'])) {
                $htmlKeys[] = $md5;
                $htmlTexts[] = $data['value'];
            } else {
                $textKeys[] = $md5;
                $textTexts[] = $data['value'];
            }
        }

        // Translate HTML fields — html tag handling preserves <br>, &nbsp;, and all HTML constructs
        if (!empty($htmlTexts)) {
            $htmlResults = $translator->translateText($htmlTexts, $locale_from, $locale_to, [
                TranslateTextOptions::TAG_HANDLING => 'html',
                TranslateTextOptions::PRESERVE_FORMATTING => true,
            ]);
            foreach ($htmlResults as $i => $result) {
                $source = $this->translation_texts[$htmlKeys[$i]];
                $this->translation_results[$source['class']][$source['id']][$source['field']] = $result->text;
            }
        }

        // Translate plain text fields — xml tag handling with <t id="md5"> wrappers
        if (!empty($textTexts)) {
            $textResults = $translator->translateText($textTexts, $locale_from, $locale_to, [
                TranslateTextOptions::TAG_HANDLING => 'xml',
                TranslateTextOptions::PRESERVE_FORMATTING => true,
            ]);
            foreach ($textResults as $i => $result) {
                $pattern = '/<t id="(\w+)">(.*)<\/t>/s';

                if (!preg_match($pattern, $result->text, $matches)) {
                    // Fallback: match by array position if regex fails
                    $source = $this->translation_texts[$textKeys[$i]];
                    $this->translation_results[$source['class']][$source['id']][$source['field']] = $result->text;

                    continue;
                }

                $source = $this->translation_texts[$matches[1]];
                // Decode XML entities back to plain characters
                $translatedText = html_entity_decode($matches[2], ENT_XML1 | ENT_HTML5, 'UTF-8');
                $this->translation_results[$source['class']][$source['id']][$source['field']] = $translatedText;
            }
        }
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
                'category' => _t(__CLASS__ . '.PERMISSION', 'Localisation'),
            ],
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
                if (!$obj->existsInLocale($locale)) {
                    continue;
                }

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
