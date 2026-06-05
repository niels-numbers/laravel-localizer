# Translated URL Paths

`Route::localize()` keeps the same path in every language. For truly
localized paths (`/about` vs `/de/ueber` vs `/fr/a-propos`), use
`Route::translate()` with `Localizer::url()`:

```php
use NielsNumbers\LaravelLocalizer\Facades\Localizer;

Route::translate(function () {
    Route::get(Localizer::url('about'), AboutController::class)->name('about');
});
```

::: danger Every translated route must have a name
Always add `->name()` to a route inside `Route::translate()`. Translated
routes have locale-specific URIs (`/about` vs. `/ueber`), so the language
switch (`Route::localizedUrl()`) relies on the shared route name to find
the equivalent URL in another locale - there is no way to recover it from
the URI alone. A nameless translated route therefore throws an
`UnnamedTranslatedRouteException` at registration.
:::

Define the translations in `lang/{locale}/routes.php`:

```php
// lang/en/routes.php
return ['about' => 'about'];

// lang/de/routes.php
return ['about' => 'ueber'];
```

Registers one route per supported locale (`/en/about`, `/de/ueber`),
plus a no-prefix variant for the default locale when
`hide_default_locale` is on. Use `route('about')` as usual.

::: warning Lookup keys must match the full URI
`routes.about` translates `/about`. For nested paths use the full path:
`'blog/post/{slug}' => 'artikel/{slug}'`. The translator does not split
paths into segments.
:::

::: warning The closure runs N+1 times
Once per supported locale, plus an additional time for the
`without_locale.` variant when the locale is the default and
`hide_default_locale` is on. Same side-effect rules apply as for
`Route::localize()`.
:::

## Multi-tenant caveat

`Route::translate()` does not work correctly when tenants have
different default locales. The `without_locale.*` variant is baked
against the boot-time default and can't be rewritten by
`setActiveDefaultLocale()` at request time. See
[Multitenancy](/multitenancy#caveat-route-translate-and-per-tenant-defaults).
