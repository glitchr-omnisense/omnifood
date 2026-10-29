# Changelog

## [Unreleased]

### Added

- `Http\Redaction`: what the platforms call at their catch of the HTTP client's exception, so
  that a `ProviderException` carries none of the request's secrets - its message with the key's
  parameters, the URL's user part and the request's secrets masked as `***` (`text()`), as
  previous a copy of the client's exception, the same class and code (`copy()`), and no trace
  of the chain keeping its arguments (`withoutArguments()`).

### Fixed

- composer.json requires `ext-mbstring`, which the package calls (`mb_*` functions): a PHP built without it failed with an undefined function.
