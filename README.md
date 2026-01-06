# Deuteros

[![CI](https://github.com/deuteros-php/deuteros/actions/workflows/ci.yml/badge.svg)](https://github.com/deuteros-php/deuteros/actions/workflows/ci.yml)

> δεύτερος • (deúteros): second of two

– [Wikitionary](https://en.wiktionary.org/wiki/%CE%B4%CE%B5%CF%8D%CF%84%CE%B5%CF%81%CE%BF%CF%82)

A PHP library providing value-object entity doubles for Drupal unit testing, allowing you to test code that depends on entity/field interfaces without Kernel tests, module enablement, database access, or service container.

## Installation

```bash
composer require --dev deuteros-php/deuteros
```

## Documentation

See [docs/](docs/) for full documentation:

- [Usage Guide](docs/USAGE.md) - Getting started, API reference, and examples
- [Architecture](docs/ARCHITECTURE.md) - For contributors and maintainers

## Requirements

- PHP 8.3+
- Drupal 10.x or 11.x
- PHPUnit 9.0+/10.0+/11.0+ or Prophecy 1.15+

## License

[GPL-2.0 license](LICENSE)
