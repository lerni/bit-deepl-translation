# Silverstripe Fluent DeepL Translation - WIP
This module, developed by Martin Benkenstein, offers a straightforward method to translate DataObjects and SiteTree objects via the DeepL API into draft mode if versioned is enabled.

I have customized it to my needs; it now just has one single button to only translate from default language into the CMS-selected one and fields to translate are automatically picked up from Fluent config.

## Installation
ATM this module is not registered on packagist, so you have to add the repository to your composer.json
```json
...
    "repositories": [
		{
			"type": "vcs",
			"url": "https://github.com/lerni/bit-deepl-translation"
		}
	],
...
```
```sh
composer require bit/deepl-translation
```

## Requirements
Versions may be a bit vague since this is WIP
- SilverStripe ^4 | ^5
- Fluent *

## Config
Add your DeepL API key to your .env file
```env
DEEPL_API_KEY="your-api-key"
``` 
Add `BenkIT\DeepLTranslation\DeepLDataObjectExtension` to all DataObjects you want to translate. Fields can be ignored by adding to `deepl_translate_ignore` on a global and object level.

```yaml

---
Name: app-deepl-translate
After:
  - '#bit-deepl-translate'
---
# globally ignore fields
deepl_translate_ignore:
  - CanonicalURL

SilverStripe\CMS\Model\SiteTree:
  extensions:
    - 'BenkIT\DeepLTranslation\Extensions\DeepLDataObjectExtension'

App\Models\ElementPage:
  deepl_translate_ignore:
    - Content #this field gets generated

SilverStripe\UserForms\Model\EditableFormField:
  extensions:
    - 'BenkIT\DeepLTranslation\Extensions\DeepLDataObjectExtension'

SilverStripe\UserForms\Model\EditableFormField\EditableOption:
  extensions:
    - 'BenkIT\DeepLTranslation\Extensions\DeepLDataObjectExtension'

SilverStripe\SiteConfig\SiteConfig:
  extensions:
    - 'BenkIT\DeepLTranslation\Extensions\DeepLDataObjectExtension'

DNADesign\Elemental\Models\BaseElement:
  extensions:
    - 'BenkIT\DeepLTranslation\Extensions\DeepLDataObjectExtension'

App\Models\Teaser:
  extensions:
    - 'BenkIT\DeepLTranslation\Extensions\DeepLDataObjectExtension'
...
```
## Task
There is `BenkIT\DeepLTranslation\Tasks\CopyTranslationTask` to localize & translate all objects to a given language but be careful, it's not tested well and may not work as expected. Parameters `confirmLocalize` & `confirmTranslate` need to be set to `true` to actually do something.

## License
See [License](LICENSE)
