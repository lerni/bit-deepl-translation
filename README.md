# Config in a nutshell

```yaml
---
Name: app-deepl-translate
After:
    - '#bit-deepl-translate'
---

# there are no pre-enabled DataObjects
SilverStripe\CMS\Model\SiteTree:
    extensions:
        - BenkIT\DeepLTranslation\DeepLDataObjectExtension

Elements\ElementLinklist:
  deepl_translate:
    - Text #field
    - SomeObject #has_one -> gets translated recursively
    - Links #has_many List -> gets translated recursively

# sometimes you want to remove a previously set field
SomeCustomPage:
    deepl_translate_ignore:
        - Content
```

# ToDo

- SiteConfigExtension
- Readme
