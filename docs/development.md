# Development

## Contents

- [Running the suite](#running-the-suite)
- [The test kernel](#the-test-kernel)
- [The authority table](#the-authority-table)
- [What CI does without](#what-ci-does-without)

## Running the suite

```console
composer check     # cs:check + phpstan (max) + phpunit
```

The suite needs a **real Postgres** (`STORAGE_TEST_DATABASE_URL`, see
`phpunit.dist.xml`) and this bundle **owns no entities** — both are true. The
database is the core's: the Files hub is a widget dashboard, one person's
arrangement of one is a stored row, and the bundle that owns the row owns its
schema. Nothing under `src/` maps a table.

## The test kernel

The test kernel boots three of the core's five bundles — `RegistryBundle`,
`ShellBundle` and `TeamBundle`. Team is there for one thing only, the account
class every stored layout is keyed by; it ships dashboards of its own, and
`OnlyThisModulesSurfacesPass` clears their tag so an assertion about the widget
registry stays an assertion about storage rather than about a dependency's
release notes. `AreaBundle` is deliberately absent: storage draws nothing from
an area, so the area contract is answered by a stand-in host area in the suite's
own fixtures rather than by a bundle that brings PostGIS with it.

The integration suite writes real bytes into a temp directory, because a mocked
filesystem would only be testing the mock.

## The authority table

`tests/Functional/AuthorityTableTest.php` calls every route this module mounts
as every kind of person — signed out, deactivated, Staff with no position,
Staff holding the route's pair at Kilimani, Staff holding every other pair,
Staff holding the pair at Tambarare, an Admin, a Super Admin — and compares
the answers with `tests/Functional/authority-table.md`. The base,
`Uhifadhi\Test\AuthorityTableTestCase`, ships with the core; the world it
seeds is the core's, so the table runs in its own kernel,
`tests/Functional/Authority/AuthorityKernel.php`: every core bundle, the
skeleton's security file, and the suite's fixture file source, evidence voter
and upload target. The rows that reach a record's file show storage's floor
under the fixture's answer, not a real module's rule.

A change in who may open what fails the build until it is recorded and its
diff is read:

```console
UHIFADHI_RECORD_AUTHORITY_TABLE=1 vendor/bin/phpunit tests/Functional/AuthorityTableTest.php
```

## What CI does without

CI runs PHP 8.4 and 8.5 with **GD but deliberately without Imagick**, so the
"a HEIC arrived and nothing here can decode it" branch is exercised on every
run rather than only on the machines that happen to lack the extension.
