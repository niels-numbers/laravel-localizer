# Template Helpers

Macros available on the `Route` facade (and one on the `Route` instance)
for use in controllers, Blade templates, and middleware.

## `Route::localizedUrl($locale, $absolute = true)`

The current request's URL in another locale, in **canonical** form
(no prefix when the target is the hidden default). Use for
`<link rel="alternate" hreflang>` tags, canonical URLs, and sitemaps:

```blade
@foreach (config('localizer.supported_locales') as $locale)
    <link rel="alternate"
          hreflang="{{ $locale }}"
          href="{{ Route::localizedUrl($locale) }}" />
@endforeach
```

For an in-page **language switcher**, use
`Route::localizedSwitcherUrl($locale)` instead - it always emits the
prefixed form. See [Language Switcher](/language-switcher).

| Current route | Behavior                               |
|---|----------------------------------------|
| Named (recommended) | Resolved through `route()`.            |
| Unnamed `Route::localize()` | URI prefix swap on the request path.   |
| Unnamed `Route::translate()` | Resolved through `route()`.|
| Outside a request | Throws `LogicException`.               |

The current request's query string is preserved on the localized URL,
so pagination and filter URLs like `/posts?page=2&sort=name` keep the
user on the same page after a locale switch. Route defaults set by
`Route::view()`, `Route::redirect()`, or `->defaults()` are stripped
from the result - they belong to the route's controller, not the URL.

## `Route::hasLocalized($name)` {#has-localized}

True if the name was registered through `Route::localize()` or
`Route::translate()`:

```blade
@if (Route::hasLocalized('about'))
    <a href="{{ route('about') }}">{{ __('About') }}</a>
@endif
```

Use this instead of `Route::has('about')`. The macros never register
the bare base name - they register `with_locale.about`,
`without_locale.about`, and (for `Route::translate()`)
`translated_{$locale}.about`. `Route::has('about')` therefore returns
`false` even though `route('about')` resolves correctly through the
package's URL generator. `Route::hasLocalized()` checks all variants
for you.

## `Route::isLocalized()`

True if the **current** request matched a localizer-managed route.
Useful for showing a switcher only on localized pages:

```blade
@if (Route::isLocalized())
    @include('partials.language-switcher')
@endif
```

## `$route->baseName()` and `Route::currentBaseName()` {#base-name}

Return the route's bare base name with the localizer prefix stripped.
`with_locale.about`, `without_locale.about`, and `translated_de.about`
all collapse to `about`. Foreign-named routes (e.g. `admin.dashboard`)
and unnamed routes pass through unchanged.

Use this whenever you compare against a known name - middleware,
authorization gates, analytics, breadcrumb lookups - so the comparison
keeps working across all locale variants:

```php
// In a custom middleware:
if ($request->route()->baseName() === 'about') {
    // ...
}

// Outside a Route instance, when you only care about the current request:
if (Route::currentBaseName() === 'about') {
    // ...
}
```

`Route::currentBaseName()` returns `null` when called outside a request
(no current route), so it's safe to use without a guard.

The same logic is exposed as `Localizer::baseName($name)` if you need
to strip an arbitrary route name (e.g. one read from logs or a queue
payload).
