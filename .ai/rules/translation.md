---
paths:
  - 'app/Services/Translation/**'
---

# Translation

## DeepL host must include https scheme
DEEPL_API_HOST must be an absolute URL (https://api-free.deepl.com). A bare hostname like api-free.deepl.com makes TranslateModelJob fail with "URI must include a scheme and host". DeepLTranslator::host() now prepends https:// when the scheme is missing.

## Free DeepL rejects ignore_tags
api-free.deepl.com returns HTTP 400 `Value for 'ignore_tags' not supported` and the following burst of jobs then hits HTTP 429. Send `ignore_tags` only when the host is not the free API. Brand names stay wrapped in `<x>` either way, and the id restores the original spelling.

## LegalPage DeepL uses HTML tag handling
Only LegalPage sets translationUsesHtml() true. TranslateModelJob sanitizes HTML before and after DeepL, and DeepLTranslator::translateMany(..., html: true) must send tag_handling=html so tags, attributes, and classes are not translated. Journal, FAQ, and posts stay plain text.
