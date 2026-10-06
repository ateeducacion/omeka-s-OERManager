# OERManager

[![codecov](https://codecov.io/gh/ateeducacion/omeka-s-OERManager/branch/main/graph/badge.svg)](https://codecov.io/gh/ateeducacion/omeka-s-OERManager)

<a href="https://ateeducacion.github.io/omeka-s-playground/?blueprint=https%3A%2F%2Fraw.githubusercontent.com%2Fateeducacion%2Fomeka-s-OERManager%2Frefs%2Fheads%2Fmain%2Fblueprint.json">
  <img src="https://raw.githubusercontent.com/ateeducacion/omeka-s-OERManager/refs/heads/main/.github/assets/playground-preview-button.svg" alt="Try OERManager in your browser" width="224">
</a><br>
<small><a href="https://ateeducacion.github.io/omeka-s-playground/?blueprint=https%3A%2F%2Fraw.githubusercontent.com%2Fateeducacion%2Fomeka-s-OERManager%2Frefs%2Fheads%2Fmain%2Fblueprint.json">Try in your browser</a></small>

An Omeka S 4.2+ module for curating `lrmi:LearningResource` catalogs, checking
metadata integrity, and reporting catalog statistics. Requires PHP 8.4 or later.
Project requirements and decisions are maintained in [docs](docs/).

## Development and coverage

Install the locked dependencies with `composer install`, then run `make lint`,
`make test`, and `make test-js`.

After `composer install`, `make up` (or `docker compose up`) starts Omeka S on http://localhost:8080
(admin `admin@example.com` / `PLEASE_CHANGEME`) with this repository mounted as `modules/OERManager`.

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
