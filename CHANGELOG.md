# Changelog

All notable changes to Atria Core are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this
project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0](https://github.com/moraisz/AtriaCore/compare/v0.1.0...v1.0.0) (2026-09-29)


### ⚠ BREAKING CHANGES

* **database:** DatabaseConnection requires inTransaction() and lastInsertId(); QueryBuilder requires affected() and transaction(); Migrator takes a Schema as its second constructor argument.

### Features

* **database:** add SQLite and MySQL drivers ([b38b490](https://github.com/moraisz/AtriaCore/commit/b38b49061a4996536008a0872735f9446041546a))
* **database:** max_lifetime db connection ([552c503](https://github.com/moraisz/AtriaCore/commit/552c503d71051dbb3fd61a421a65f5f1f7727a82))
* **database:** override PDO options connection ([bc15443](https://github.com/moraisz/AtriaCore/commit/bc15443cd92a99b7e84aad74bb8d319fb4ad1783))

## [0.1.0](https://github.com/moraisz/AtriaCore/compare/v0.1.0-alpha...v0.1.0) (2026-09-27)


### Miscellaneous Chores

* **release:** prepare 0.1.0 ([e2408d5](https://github.com/moraisz/AtriaCore/commit/e2408d598bd643fde9b0eebb867efb7265edc502))
* **release:** prepare 0.1.0 ([880ed7a](https://github.com/moraisz/AtriaCore/commit/880ed7ab67b63b01d9855f4e9bb23a23dccfc384))

## [0.1.0-alpha](https://github.com/moraisz/AtriaCore/compare/v0.0.1-alpha...v0.1.0-alpha) (2026-09-27)


### Features

* **request:** query string and file support ([dfcd0a6](https://github.com/moraisz/AtriaCore/commit/dfcd0a6cb962425984b8e6c077de9a499e6a6fdc))
* **request:** query string and file support ([a6b3465](https://github.com/moraisz/AtriaCore/commit/a6b3465d6e61b72ed03ebf16a923fc09c05d700b))


### Miscellaneous Chores

* **release:** configure automated releases ([a7c83d0](https://github.com/moraisz/AtriaCore/commit/a7c83d0ad5b71e9dbe0161073649c22123f5050b))

## [0.0.1-alpha] - 2026-09-27

### Added

- Initial public alpha release.
