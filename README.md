# Polyfill for Cache::flexible() in Laravel 10 and 11

[![Latest Version on Packagist](https://img.shields.io/packagist/v/spatie/laravel-flexible-cache-polyfill.svg?style=flat-square)](https://packagist.org/packages/spatie/laravel-flexible-cache-polyfill)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/spatie/laravel-flexible-cache-polyfill/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/spatie/laravel-flexible-cache-polyfill/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/spatie/laravel-flexible-cache-polyfill/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/spatie/laravel-flexible-cache-polyfill/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/spatie/laravel-flexible-cache-polyfill.svg?style=flat-square)](https://packagist.org/packages/spatie/laravel-flexible-cache-polyfill)

This package provides a polyfill for the `Cache::flexible()` method, which was introduced in Laravel 11. It brings the [stale while revalidating](https://laravel.com/docs/master/cache#swr) cache pattern to Laravel 10.

When using `Cache::remember`, some users may experience slow response times if the cached value has expired. The flexible method allows stale data to be served while the cached value is recalculated in the background after the response is sent, preventing slow response times for your users.

## Support us

[<img src="https://github-ads.s3.eu-central-1.amazonaws.com/FlexibleCachePolyfill.jpg?t=1" width="419px" />](https://spatie.be/github-ad-click/FlexibleCachePolyfill)

We invest a lot of resources into creating [best in class open source packages](https://spatie.be/open-source). You can support us by [buying one of our paid products](https://spatie.be/open-source/support-us).

We highly appreciate you sending us a postcard from your hometown, mentioning which of our package(s) you are using. You'll find our address on [our contact page](https://spatie.be/about-us). We publish all received postcards on [our virtual postcard wall](https://spatie.be/open-source/postcards).

## Installation

You can install the package via composer:

```bash
composer require spatie/laravel-flexible-cache-polyfill
```

## Usage

Using the `Cache::flexible()` facade:

```php
$value = Cache::flexible('users', [5, 10], function () {
    return DB::table('users')->get();
});
```

The first value (5) is the number of seconds the cache is considered "fresh". The second value (10) defines how long it can be served as stale data before recalculation is necessary.

- 0-5 seconds: Returns cached value immediately
- 5-10 seconds: Returns stale value, refreshes in background after response is sent
- After 10 seconds: Cache expired, recalculates immediately

Support for all stores is available:

```php
$value = Cache::store('redis-2')->flexible(...);
```

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [Alex Vanderbist](https://github.com/alexvanderbist)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
