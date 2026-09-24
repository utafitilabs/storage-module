# uhifadhi/storage-module

The uhifadhi platform's file-storage machinery: named Flysystem storages, a
private evidence API with a detected-MIME allowlist, thumbnails, and one
authenticated route by which any of it comes back out.

## Contents

- [What it is](#what-it-is)
- [Installation](#installation)
- [Getting started](#getting-started)
- [Uploads: one interface, one Twig line](#uploads-one-interface-one-twig-line)
- [Learn more](#learn-more)
- [License](#license)

## What it is

Mechanism only. The bundle owns no entities, no migrations and no screens of a
module's own: it holds the named storages, the store/stream/delete API, the MIME
allowlist and size cap, thumbnail generation, and the authenticated serving
route. Which record a file hangs off, and who may read it, stays with the module
that wrote the key — see [the charter](docs/charter.md).

It also owns the whole **upload feature** — the component, one endpoint and a
target contract — because a file used to enter the product through five doors
that each drew their own box, their own error and their own missing progress
bar. See [Uploads](#uploads-one-interface-one-twig-line).

On top of that machinery it ships one optional cross-module screen, the **Files
hub** (`/files`), which is a dashboard on the widget machinery `ShellBundle`
ships — which is why the core, `uhifadhi/uhifadhi`, is a hard requirement rather
than a suggestion. The hub itself has no upload: a file arrives by being attached
to a record, never by being put in a folder.

## Installation

```console
composer require uhifadhi/storage-module
```

Then the installation's four commands, the same four after every change to it:

```console
php bin/console cache:clear --no-warmup
php bin/console doctrine:migrations:migrate
php bin/console registry:sync
php bin/console cache:warmup
```

This module ships the migrations for the tables it owns and registers their path itself, so `migrate` runs them and an installation writes no version for them; `registry:sync` then enters the module in the catalogue and gives every area its row, and prints what it added, kept and retired; `doctrine:migrations:diff` stays reserved for the installation's own entities and must report no changes after this. In development AssetMapper serves the module's stylesheets and scripts from source; the production image compiles them.

The recipe registers the bundles and writes `config/packages/storage.yaml` and
`config/routes/storage.yaml`. Without Flex, `config/bundles.php` needs three
lines:

```php
League\FlysystemBundle\FlysystemBundle::class => ['all' => true],
Uhifadhi\Bundle\ShellBundle\ShellBundle::class => ['all' => true],
Uhifadhi\Storage\UhifadhiStorageBundle::class => ['all' => true],
```

The bundle **prepends** its own `flysystem` block, so an installation never
writes `config/packages/flysystem.yaml` to get an evidence store.

### php.ini has to accept what this module accepts

PHP applies its own upload ceiling before a line of this bundle runs, and the
stock production `php.ini` sits well under the 12 MiB the module accepts by
default — so a phone photograph arrives truncated and is refused. Raise both
values, in the ini the web SAPI actually loads, to at least
`storage.evidence.max_bytes`:

```ini
upload_max_filesize = 16M
post_max_size = 20M
```

`post_max_size` carries the whole multipart body, so keep it the larger of the
two. Where the server accepts less than the configured cap, the bundle writes one
`warning` per process naming both numbers, and a file PHP cut short is refused
with the server's limit in the sentence rather than with "did not arrive intact".

`ShellBundle` keeps its widget layouts in two tables of its own, so after
installing run your own `doctrine:migrations:diff` and `migrate`. It answers
`Uhifadhi\Contracts\Entity\UserInterface` from `TeamBundle`, in the same
package, so an installation writes no `resolve_target_entities` line.

### Switching it on

A module is installed but **parked**: every page of it answers 404 in an area that has not taken it. An administrator switches it on per area from that area's module grid, and grants the module's permissions to the positions that need them from the positions screen. Reading needs the module's `read` grant; nothing else is required to see it.

## Getting started

An unconfigured installation already has a working, private, on-disk evidence store.
Three steps put files through it:

**1 · Store and read bytes** from the module that owns the record:

```php
use Uhifadhi\Storage\Service\EvidenceStorage;

$stored = $evidence->store($uploadedFile, 'observation/'.$uuid, $clientUuid);

$stored->key;        // RELATIVE, always — this is what you record on your entity
$stored->mimeType;   // DETECTED, never what the client claimed
$stored->thumbKey;   // the ~400px variant, or NULL
```

**2 · Mount the routes**, so stored bytes can come back out:

```yaml
# config/routes/storage.yaml
storage:
    resource: '@UhifadhiStorageBundle/src/Controller/'
    type: attribute
```

The serving route `storage_evidence_show` (`GET /storage/evidence/{key}`) is
registered only when SecurityBundle is in the kernel.

**3 · Ship a voter** for your key prefix, tagged
`uhifadhi.evidence_access_voter`. Storage cannot know what an observation is, so
it asks the module that wrote the key — and **denies by default** until a voter
claims it and agrees.

Configuration (a different adapter, a narrower allowlist, the Files hub) is
optional and layered on from there.

## Uploads: one interface, one Twig line

A module that wants people to be able to attach a file somewhere does not write
a controller, a route, a stylesheet or a line of JavaScript. It implements
`UploadTargetInterface`, tags it, and writes one line in its template.

```php
final readonly class SightingEvidenceTarget implements UploadTargetInterface
{
    public function kind(): string { return 'sighting'; }                      // the key prefix
    public function accepts(string $targetId): ?object { /* the record, or null */ }
    public function mayUpload(object $record, UserInterface $user): bool { /* … */ }
    public function constraints(object $record): UploadConstraints { /* kinds, size, how many */ }
    public function received(object $record, StoredFile $file, UserInterface $user): UploadReceipt
    {
        /* write your row; say what the file became */
    }
    public function mayRemove(string $key, UserInterface $user): bool { /* … */ }
    public function removed(string $key, UserInterface $user): void { /* unpick your row */ }
}
```

```php
$services->set('sighting.upload_target', SightingEvidenceTarget::class)
    ->args([/* … */])
    ->tag(UploadTargetInterface::TAG);
```

```twig
{{ render_upload('sighting:' ~ sighting.uuid, 'tile') }}
```

Two presentations — `zone`, a dropzone card where receiving the file IS the
step, and `tile`, one cell of a grid of things already attached — one endpoint
(`POST /files/upload`, `DELETE /files/{key}`), and every refusal a sentence
written by whoever refused, naming what the TARGET takes rather than what the
file is.

The shipped `allowed_mime_types` default covers **one of each kind the hub
names** — photographs, `application/pdf`, and a GPX under the three types a
track can arrive as. A deployment may narrow it; see
[docs/configuration.md](docs/configuration.md). The full contract, the worked example, the events and
the `controllers.json` entry are in [docs/uploads.md](docs/uploads.md).

## Learn more

- [docs/charter.md](docs/charter.md) — what belongs in this bundle and what stays in the owning module.
- [docs/configuration.md](docs/configuration.md) — the full `storage.yaml` reference: local and S3-compatible object storage, and why visibility is not a setting.
- [docs/evidence-api.md](docs/evidence-api.md) — `EvidenceStorage`, the key rules, the three exceptions, validation and thumbnails.
- [docs/serving-and-permissions.md](docs/serving-and-permissions.md) — the serving route and the permission contribution point: voters, deny-by-default, and enumeration.
- [docs/uploads.md](docs/uploads.md) — the upload component and `UploadTargetInterface`: the contract, a worked module, the endpoint, the refusal sentences and the events.
- [docs/files-hub.md](docs/files-hub.md) — the cross-module `/files` screens: what an installation wires, the widgets, `FileSourceInterface`, and removal.
- [docs/adopting-in-a-module.md](docs/adopting-in-a-module.md) — step-by-step adoption for patrol-module and incident-module.
- [docs/service-reference.md](docs/service-reference.md) — service ids, classes, the tag and the route.
- [docs/development.md](docs/development.md) — running the suite, and what CI deliberately does without.

## License

**AGPL-3.0-or-later** — see [LICENSE](LICENSE): the same license as the
uhifadhi platform this module is part of. Use, modify and self-host freely; if you
offer a modified version to users over a network, they are entitled to the
source of what they're running.
