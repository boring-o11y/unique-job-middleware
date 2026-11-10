# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the package
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Removed

- Support for PHP 8.1, 8.2 and 8.3. The package now requires PHP 8.4 or later, and CI runs on PHP 8.4 and 8.5.
- Support for Laravel 10 and 11. Both are end-of-life, and Composer refuses to install them because every release has unpatched security advisories. The package now requires Laravel 12 or later, and CI runs on Laravel 12 and 13.
