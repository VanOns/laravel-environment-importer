# Basic usage

You can execute the synchronization command by running:

```bash
php artisan environment:import
```

The command supports the following flags:

| Flag           | Description                                          |
|----------------|------------------------------------------------------|
| `--target`     | The target environment to import                     |
| `--safe`       | Skip all prompts and keep all files after import     |
| `--clean`      | Skip all prompts and clean up all files after import |
| `--skip-db`    | Skip importing the database                          |
| `--skip-files` | Skip importing the files                             |

## Notifications

It is possible to receive email notifications when the import succeeds or fails. To do that, make sure to configure
the `notifications` section in the configuration file.
