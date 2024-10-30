# Silverstripe Fluent Deepl Translation - WIP
This module, developed by Martin Benkenstein. It offers a straightforward method for translating DataObjects and SiteTree objects via the DeepL API into draft mode if versioned. I have customized it to better suit my requirements so that translated fields are automatically picked up and having just one single button to translate, otherwise just translate form default locale & relay on the language-selection in CMS.

## License
See [License](LICENSE)

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
Add `BenkIT\DeepLTranslation\DeepLDataObjectExtension` to all DataObjects you want to translate. Fields can be ignored by adding to `deepl_translate_ignore` on a global and object level.

```yaml

---
Name: app-deepl-translate
After:
  - '#bit-deepl-translate'
---
deepl_translate_ignore:
  - CanonicalURL

App\Models\ElementPage:
# SilverStripe\CMS\Model\SiteTree:
  extensions:
    - BenkIT\DeepLTranslation\DeepLDataObjectExtension
  deepl_translate_ignore:
    - Content #this field gets generated

SilverStripe\UserForms\Model\EditableFormField:
  extensions:
    - 'BenkIT\DeepLTranslation\DeepLDataObjectExtension'

SilverStripe\UserForms\Model\EditableFormField\EditableOption:
  extensions:
    - 'BenkIT\DeepLTranslation\DeepLDataObjectExtension'

SilverStripe\SiteConfig\SiteConfig:
  extensions:
    - 'BenkIT\DeepLTranslation\DeepLDataObjectExtension'

DNADesign\Elemental\Models\BaseElement:
  extensions:
    - 'BenkIT\DeepLTranslation\DeepLDataObjectExtension'

App\Models\Teaser:
  extensions:
    - 'BenkIT\DeepLTranslation\DeepLDataObjectExtension'
...
```
