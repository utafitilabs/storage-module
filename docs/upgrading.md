# Upgrading

## Contents

- [0.4 — runs on core 0.2](#04--runs-on-core-02)
- [0.3.2 — the settings permission is a grant, not a config value](#032--the-settings-permission-is-a-grant-not-a-config-value)

## 0.4 — runs on core 0.2

The module requires `uhifadhi/uhifadhi` `^0.2`; widen both carets together:

```console
composer require uhifadhi/uhifadhi:^0.2 uhifadhi/storage-module:^0.4
```

Nothing in `config/packages/storage.yaml` changes. What changes is who reaches
the Files section, and it is the core's rule rather than this module's: under
core 0.2 every member of staff belongs to exactly one department, and somebody
with none reaches no module's records. A position that held `files.read`,
`storage.read` or `storage.configure` still holds it; make sure everybody who
relied on it is placed in a department. The pairs are asked with no area, so a
holder opens the Files section wherever they are placed.

## 0.3.2 — the settings permission is a grant, not a config value

`files.settings_permission` no longer exists: the settings screens are guarded by the `storage.configure`
pair, granted per installation in the positions × permissions matrix. An installation created before 0.3.2
carries the old line in `config/packages/storage.yaml` and refuses to boot with "Unrecognized option
settings_permission under storage.files" until the line is removed:

```yaml
storage:
    files:
        # settings_permission: 'module.create'   ← delete this line
```

Then grant `storage.configure` to the positions that used to hold the old permission.
