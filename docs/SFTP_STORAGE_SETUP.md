# SFTP media storage setup

The CMS supports SFTP as a media-storage profile. Players never receive SFTP
credentials: they continue downloading encrypted LDG objects through the CMS
HTTP Range endpoint.

## Development endpoint

Open **Control Center → Storage → Add Storage**, choose **Company SFTP**, then
enter:

| Field | Development value |
|---|---|
| Profile name | `Development SFTP` |
| Host | `103.165.225.221` |
| Port | `22` |
| Username | `sftpuser` |
| Remote root | `/sftpfiles/Testing(Hanif)` |
| SSH host-key fingerprint | The exact verified `SHA256:...` value from FileZilla/server administration |
| Password | Enter directly in the CMS; never commit it |

The remote root is treated as a literal SFTP path. Parentheses do not need
quotes or escaping in the CMS form.

## Security behavior

- SSH host-key verification is mandatory and happens before credentials are
  sent. An unknown or changed key causes the connection to fail.
- Username and password are encrypted by `StorageCredentialService` before
  being stored in PostgreSQL.
- Production must configure `storage.credentialsKey` as a Base64-encoded
  32-byte random key.
- Passwords and fingerprints are never sent to Players.

## Connection probe

Creating, updating, testing, or making an SFTP profile the default performs a
probe inside `.cms-probe/` below the configured remote root:

1. establish SSH and validate the pinned host key;
2. authenticate the SFTP account;
3. upload a small `.part` object;
4. verify its size;
5. rename it atomically to its final key;
6. materialize/read it through the CMS cache; and
7. delete the remote probe and local cache object.

Only a healthy active profile can become the default. Changing the default
affects new uploads only; existing assets remain bound to their original
storage profile.

## Film transfer behavior

Uploads use resumable `.part` files followed by an atomic rename. The CMS seeds
its bounded local cache after upload. When a Player requests an encrypted film,
the CMS materializes it from SFTP when necessary and serves authenticated HTTP
byte ranges. Asset SHA-256 and LDG encryption behavior are unchanged.
