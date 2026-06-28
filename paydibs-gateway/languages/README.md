# Paydibs Gateway translations

WordPress loads these files from the plugin `languages/` folder (`Text Domain: paydibs-gateway`).
The plugin calls `load_plugin_textdomain()` on `plugins_loaded` (priority 0) so bundled `.mo` files work on all supported WordPress versions.

## Supported locales (Malaysia-focused)

| Locale | File | Language |
|--------|------|----------|
| *(default)* | *(English in PHP source)* | English |
| `ms_MY` | `paydibs-gateway-ms_MY.po` | Bahasa Melayu |
| `zh_CN` | `paydibs-gateway-zh_CN.po` | Chinese (Simplified) |
| `zh_TW` | `paydibs-gateway-zh_TW.po` | Chinese (Traditional) |
| `hi_IN` | `paydibs-gateway-hi_IN.po` | Hindi |
| `ta_IN` | `paydibs-gateway-ta_IN.po` | Tamil |

Set **Settings → General → Site Language** to match the locale you want.

## Compile `.mo` files (after editing `.po`)

```bash
cd languages
for f in paydibs-gateway-*.po; do msgfmt -o "${f%.po}.mo" "$f"; done
```

## Update template

When adding new translatable strings in PHP/JS, update `paydibs-gateway.pot` and sync every `paydibs-gateway-*.po` file.
