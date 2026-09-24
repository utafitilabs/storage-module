# The Files hub

A **cross-module screen at `/files`**: every photograph, document and track the
organization holds, across every module and every area, in one place — each one
shown with the record it belongs to.

**A file is OWNER-BOUND.** It belongs to a record in a module: an observation's
photograph, an incident's evidence, later a permit's document. It has no life of
its own — it is created by being attached, it is seen by whoever may see its
record, it is removed only when that record allows, and it dies when the record
dies. Two consequences run through every line of this feature:

- **There is no upload control anywhere on the hub**, and that is a rule rather
  than an omission. A file arrives by being attached to a record, on that
  record's own page — through the [upload component](uploads.md), which this
  bundle also owns and which the hub deliberately does not draw.
- **Every tile, row and list entry carries its owner as a link.** A file card
  with no owner tag would be a lie about the model.

The hub adds exactly the two facts no module page knows — which named place the
bytes are in, and whether the one ~400px picture was made. It knows nothing
about observations or incidents and cannot: the files on it were handed over by
the modules that own them, through [one interface](#putting-a-modules-files-on-the-hub).

## Contents

- [What an installation wires](#what-an-installation-wires)
- [The sidebar row](#the-sidebar-row)
- [The screens](#the-screens)
- [The filter contract](#the-filter-contract)
- [Putting a module's files on the hub](#putting-a-modules-files-on-the-hub)
- [Removal — remove, never delete](#removal--remove-never-delete)
- [The design this hub is a port of](#the-design-this-hub-is-a-port-of)

## What an installation wires

The screens register themselves where **SecurityBundle** and **TwigBundle** are
both present, and are simply absent otherwise — an installation without one of
them gets no half-working dashboard.

The **widget machinery is not a condition**: it ships in `ShellBundle`, inside
the core this package requires, so it is present wherever this bundle is. The hub
IS a widget dashboard — the layout, the presets and the library are the screen
rather than a decoration on it — so there is no useful half of it to register.

**1 · Mount the routes** (the same file that mounts the serving route):

```yaml
# config/routes/storage.yaml
storage:
    resource: '@UhifadhiStorageBundle/src/Controller/'
    type: attribute
```

**2 · Grant the settings pair.** The hub is open to anybody signed in; the
settings screens — what a file may be, where the bytes go, moving them — ask for
`storage.configure`, one of the two pairs this module declares to the core's
grants matrix (`storage.read` · `storage.configure`, organization-wide). Grant
it to the positions that keep the organization's files, from the positions
screen. Name the place while you are here:

```yaml
# config/packages/storage.yaml
storage:
    files:
        storage_label: 'Hetzner'                # what YOU call the place files go
        storage_location: 'Falkenstein, Germany'
        # enabled: false                        # to ship the storage without the screens
```

`storage_label` is the one place a proper noun belongs, and it comes from the
deployment: unset, the hub says *Object storage* or *This server* according to
the adapter, and never invents a vendor.

**3 · Nothing else.** The stylesheet and the behaviour script ship from the
bundle's own `public/` — AssetMapper serves them as
`bundles/uhifadhistorage/files.css` and `…/files.js`, content-versioned, no
`assets:install` — and are loaded only by this bundle's own `base.html.twig`, so
an installation's `app.css` never references storage. The widget chrome
(`.w-grid`, `.w-cell`, `.w-span-N`) comes from `ShellBundle`'s widget
stylesheet, which `base.html.twig` links for the same reason.

Optionally, one line in `assets/app.js` arms the library's **preview** and the
picker's live filtering, for every widget surface in the installation at once.
The page is whole without it — every action is a plain form post — and naming an
AssetMapper namespace belongs to `importmap.php`, the one file a bundle may not
write:

```js
import { initWidgetLibrary } from '@uhifadhi/shell-bundle/widgets.js';
initWidgetLibrary();
```

## The sidebar row

**Nothing to wire.** The module contributes its own row through
`ShellBundle`'s navigation contribution point
(`Uhifadhi\Storage\Shell\FilesNavigation`, tagged `shell.nav_section`), so it
appears when the module is installed and leaves when it is removed. There is
nothing to type into an application's own layout, and nothing for an
installation to redo.

The row is **absent for a stranger, never hidden**, and offered to everyone else:
being signed in is the hub's whole gate, because every file is shown with its
owner and every original is permission-checked on its way out, so the hub shows
*less* to some people rather than being closed to them.

**Why System and not Observatory** (the design left this open): the hub is
org-wide, it is not an area tab, and it administers as much as it observes.
Moving it to Observatory beside Performance is one constant
(`FilesNavigation::SECTION`) if the ruling goes the other way.

## The screens

| Screen | Route | Who |
|---|---|---|
| The hub | `GET /files` | anyone signed in |
| Widget library | `GET /files/widgets` (+ 8 POSTs) | anyone signed in |
| A file's own page | `GET /files/f/{key}` | anyone signed in |
| Remove a file | `POST /files/f/{key}/remove` | whoever the owning record says |
| Where files go | `GET /files/settings` | `storage.configure` |

The hub is a **widget dashboard on `ShellBundle`'s widget machinery**: thirteen widgets in
five headed sections, and all five design directions ship as built-in presets
(`a` contact sheet, `b` owner first, `c` the ledger, `d` by the day it was
taken, `e` where the bytes are) beside the direction-neutral composition the
platform ships with. A person adopts one, copies it, and mixes a widget from
another into their copy, exactly as they do on departments or team.

Two deviations from the design's own URL sketch, both deliberate:

- A file page is `/files/f/{key}`, not `/files/{key}`. A key is a **path**, and
  a `.+` placeholder at the top level would swallow `/files/widgets` whole.
- The hub's **overlay** shows a file's facts and links to its page; it does not
  repeat the guard. What may be done to a file is a per-person answer the owning
  record gives, and it is rendered where it is authoritative — an overlay that
  cached a permission would eventually repeat it wrongly.

## The filter contract

**One GET drives the whole surface.** The filter row is eight query parameters and
nothing more, so the grid, the list and the count can never disagree and a
narrowed hub is a URL somebody can send:

```
GET /files?module=&area=&kind=&day=&backend=&thumb=&q=&view=
```

| Parameter | Control | Values | Narrows by |
|---|---|---|---|
| `module` | dropdown chip | a module slug | the owning module |
| `area` | dropdown chip | an area slug | the area the record belongs to |
| `day` | dropdown chip | `YYYY-MM-DD` | the day the **handset** took it, never the day it uploaded |
| `backend` | dropdown chip | a named storage's id | where the bytes are |
| `kind` | pill | `photo` · `document` · `track` | the **detected** type, never the file's name |
| `thumb` | pill | `made` · `!made` | whether the one small picture exists |
| `q` | search box | free text | the file name and the owning record's reference — never the caption, which is the record's |
| `view` | shape toggle | `grid` · `list` | nothing — one result set in two shapes |

**Four dropdowns and two pill runs, and the difference is a rule.** Module, area,
day and backend are things a deployment DEFINES and they grow without bound, so
each collapses into one chip carrying its own counts — the platform's
grouped-dropdown filter convention. Kind and thumbnail are fixed sets of three or
four a person clicks constantly, so they stay open as pills. The design draws the
pills without counts and they carry none.

**A panel's counts respect every other chip.** Each option is counted by running
the whole filter with that one key replaced, so a panel never promises files
another choice has already excluded. An option that would leave nothing reads `0`
rather than vanishing — a filter row that rearranges itself as you use it is one
nobody can learn.

**Every option is an ordinary link, and nothing in the row is scripted.** A panel
option, a pill and the shape toggle are all `<a href>` carrying the merged query,
and a chip is the shell's `<details class="i-dd">`, which the browser opens by
itself — so the row filters on a page whose JavaScript never arrived. The
`files-filters` controller that used to open the panels is a deprecated no-op
kept only so an installation's `assets/controllers.json` still resolves; it is
removed in the next release. An unreadable parameter is
IGNORED rather than an error: a filter from a stale bookmark must narrow the hub
or leave it alone, never take it down.

**`view` is not a narrowing.** It rides in the query so a list survives pressing a
chip, but it is absent from `FileFilter::isEmpty()` and never decides which files
answer.

**Where the bytes are is answered ABOUT a file, not BY it.** A `FileEntry` has no
idea; the installation's module→storage map does, so `FilesSurface` hands that map
to `FileRegistry::filter()` beside the filter. A deployment with one configured
storage draws one option, which is the honest answer rather than an invented
second.

**One row, one copy.** `templates/files/_filters.html.twig` is the row; any widget
that draws filters includes it and none types a second, because two rows drift
into two different filter bars.

## Putting a module's files on the hub

One interface, one tag, and **zero** knowledge of your module in this bundle:

```php
use Uhifadhi\Storage\Registry\FileSourceInterface;

final class PatrolFileSource implements FileSourceInterface
{
    public function moduleSlug(): string  { return 'patrols'; }
    public function moduleLabel(): string { return 'Patrols'; }
    public function attachesTo(): string  { return 'an observation’s photographs · a patrol’s own track'; }

    public function claimsKey(string $key): bool { return str_starts_with($key, 'patrols/'); }

    /** @return iterable<FileEntry> */
    public function files(): iterable { /* yield one FileEntry per photo row */ }

    public function guard(string $key, ?UserInterface $user): FileGuard { /* your rule, in your words */ }
}
```

```php
$services->set('patrol.file_source', PatrolFileSource::class)
    ->args([service(ObservationPhotoRepository::class), service('router')])
    ->tag(FileSourceInterface::TAG);   // 'storage.file_source'
```

The tag is applied by hand because a reusable bundle is not autoconfigured; a
an **application's** own service carries
`#[AutoconfigureTag(FileSourceInterface::TAG)]` on its own class instead.

`FileEntry` is what a module hands over, and the fields are the contract:

| Field | Meaning |
|---|---|
| `key` | the storage key — the file's identity everywhere, including its URL |
| `name` | what to print; the file's own name |
| `mimeType`, `byteSize` | the **detected** type, and the original's size |
| `ownerLabel`, `ownerUrl` | "OBS-0214" and its page. `null` url ⇒ named, not linked |
| `moduleSlug`, `moduleLabel` | "patrols" / "Patrols" |
| `areaSlug`, `areaLabel` | the area filter; `null` for something org-wide |
| `takenAt` | the **handset's** clock. `null` for a document, which has no such moment |
| `arrivedAt` | when it reached us — the only ordering that answers "has it synced" |
| `thumbKey` | the ONE ~400px picture, or `null` |
| `thumbState` | pass it where you track a queue (`Waiting`/`Failed`); derived otherwise |
| `caption` | the record's caption, shown here and never edited here |

`kind` (photo / document / track) is read off the detected mime type, never off
the name.

A module that ships no source simply does not appear: **the hub grows by
modules, never by folders.** A module that ships a source but holds nothing is
still listed, because "we have that and it is empty" is a different fact from
"we do not have it". A source that throws is skipped rather than fatal — one
module having a bad day must not hide every other module's files.

## Removal — remove, never delete

The design promises that removing a file is a **recorded event on the owning
record**. Storage cannot keep that promise alone: it has no records to write a
trail line onto. So removal is a second, optional interface, implemented by the
same module:

```php
final class PatrolFileSource implements FileSourceInterface, FileRemovalInterface
{
    public function remove(string $key, ?UserInterface $user, ?string $reason): void
    {
        // 1. write the removal onto the OBSERVATION's own trail
        // 2. then drop the bytes (EvidenceStorage::delete)
    }
}
```

The hub offers the control only where **both** the guard allows it and the
module ships this hook — a module that has not written its trail line yet does
not offer removal, which is the safe way round. The guard is asked **again** on
the way in, so a page's state is never the authority, and the token is minted per
file so one file's page cannot remove another's.

The four guard answers, each in the module's own words:

| `GuardStateEnum` | Means | Removal offered |
|---|---|---|
| `Locked` | the record will not let go of this file | no |
| `Reason` | yes, with a reason the record will keep | yes, reason required |
| `Allowed` | yes | yes |
| `Denied` | removable by somebody, but not by this person | no |

A key **no** installed module claims answers `Locked`: nothing here can
authorise removing a file nothing here owns.

## The design this hub is a port of

The settled design lives in the design workspace (`uhifadhi-web`): `files/`
(index, widgets, detail, settings), `files/files.widgets.js` (the surface
declaration — five groups, thirteen widgets, five presets), `presets/files/`
(the gallery and its coverage manifest), `files.css` and `assets/files.js`.

**Static-twin discipline.** Every `_w_<id>.html.twig` in `templates/files/` is
the twin of the matching `html` entry in `files.widgets.js`, and each partial's
header says so; `public/files.css` is the twin of the design's `files.css`.
Change one and change the other, or the library's preview stops being the
widget. `FilesWidgetsTest` fails if a partial loses that header, and
`WidgetLibraryContractTest` fails if the library and the hub stop rendering the same
partial with the same context.

**Open questions the design left, answered as defaults** — each overridable
without touching a template:

| Question | Shipped answer |
|---|---|
| Where the hub lives in the sidebar | System, above Alerts |
| A per-area `/areas/{uuid}/files` tab | not in v1 — area is a filter on the hub |
| Files on a map | not in v1 — no map layer |
| Who may open the hub | anyone signed in; originals stay voter-gated |
| Removal wording | **remove**, with the record keeping the trail line |
