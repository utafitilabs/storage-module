# Upgrading

## Contents

- [0.3.2 — the settings permission is a grant, not a config value](#032--the-settings-permission-is-a-grant-not-a-config-value)

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
