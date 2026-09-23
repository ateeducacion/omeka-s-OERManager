# OERManager

[![codecov](https://codecov.io/gh/ateeducacion/omeka-s-OERManager/branch/main/graph/badge.svg)](https://codecov.io/gh/ateeducacion/omeka-s-OERManager)

An Omeka S 4.2+ module for curating `lrmi:LearningResource` catalogs, checking
metadata integrity, and reporting catalog statistics. Requires PHP 8.4 or later.
Project requirements and decisions are maintained in [docs](docs/).

## Development and coverage

Install the locked dependencies with `composer install`, then run `make lint`,
`make test`, and `make test-js`.

With PCOV installed, generate the complete PHP coverage report and enforce the
same 90% line threshold used by CI:

```sh
rm -f coverage.xml
php -d pcov.directory=. -d pcov.exclude='~/(vendor|test)/~' vendor/bin/phpunit -c test/phpunit.xml --coverage-clover coverage.xml --coverage-text
php test/check-coverage.php coverage.xml 90
```

Coverage includes `Module.php` and every PHP class in `src/`, including untested
files. The host test suite uses isolated Omeka boundary doubles, without a live
catalog or paid AI calls. CI uploads Clover to Codecov using GitHub OIDC and sets
both project and patch coverage targets to 90%.
