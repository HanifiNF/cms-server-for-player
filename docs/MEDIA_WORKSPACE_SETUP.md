# CMS media workspace

The CMS media workspace is server-side scratch space for resumable upload
staging, encrypted LDG staging, and the bounded SFTP/FTPS cache. The remote
storage profile remains the permanent source of truth.

## Configure an environment

Open **Control Center → Storage → Configure Workspace** and enter an absolute
path on the machine running the CMS. Development can use an attached SSD such
as `E:\WIR-CMS-Workspace`. Production should use a persistent server volume,
for example `D:\CMS-Workspace` or `/var/lib/wir-cms-media`.

The CMS validates the workspace and creates these directories:

- `upload-staging`: plaintext resumable uploads;
- `storage-staging`: temporary encrypted LDG output;
- `storage-cache`: bounded SFTP/FTPS cache;
- `php-upload-tmp`: recommended PHP request-temporary directory.

The selected path is stored only in that environment's database. It is not
committed to source control or copied to another deployment.

## PHP temporary uploads

PHP's `upload_tmp_dir` is a system-level setting and cannot be changed safely
by the CMS at runtime. To keep each incoming 64 MiB chunk off the system drive,
set the following in the PHP configuration used by the web server and restart
PHP or the web server:

```ini
upload_tmp_dir="E:\WIR-CMS-Workspace\php-upload-tmp"
upload_max_filesize=65M
post_max_size=70M
```

Use the equivalent production volume path on the server. The Storage page
shows whether PHP is using the recommended directory.

## Changing or reconnecting a workspace

Finish or cancel active uploads before selecting a different workspace. The
CMS does not copy old staging or cache files automatically. Existing assets on
SFTP are not changed and cache entries are rebuilt when needed.

The root contains `.wir-cms-workspace.json`. If Windows assigns a different
drive letter to the same SSD, select its new path. The CMS accepts it as the
same workspace only when the marker and all active staging files are present.

After a workspace has been configured, an unavailable disk or mismatched
marker causes media operations to fail closed. The CMS never silently places
large temporary files back on the system drive.

## Capacity guidance

Before an upload begins, the CMS reserves capacity for the remaining source
upload, encrypted output, remote cache copy, and a 2 GiB safety margin. A
100 GB film therefore needs roughly 302 GB free in the workspace. If the
original 100 GB source is also stored on the same SSD, peak total usage of that
drive can approach 400 GB.

Plaintext upload staging and encrypted staging are removed after successful
catalog finalization. Failed resumable sessions remain available for retry
until cancelled or expired after 24 hours. Cache retention remains controlled
by each remote Storage Profile's lifetime and maximum-size settings.
