# FTPS Media Storage Setup

Dokumen ini menjelaskan cara menghubungkan Player CMS ke FTPS perusahaan. Integrasi ini tidak menggunakan Socket.IO dan tidak mengubah kontrak API Player.

## Fitur adapter

- Explicit FTPS (`FTP + AUTH TLS`) dan Implicit FTPS;
- verifikasi TLS dan hostname selalu aktif;
- dukungan CA internal dan public-key pinning;
- passive atau active mode;
- upload yang dilanjutkan dari ukuran file `.part` di remote;
- verifikasi ukuran sebelum publish;
- rename `.part` ke object key final;
- cache lokal dengan batas kapasitas;
- resume download FTPS ke cache;
- file cache seekable untuk HTTP Range Player;
- probe koneksi lengkap: upload, size, rename, read, dan delete;
- username, password, dan private-key password disimpan terenkripsi.

## 1. Siapkan encryption key credential

Generate key yang berbeda dari `ldg.masterKey`:

```powershell
php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"
```

Tambahkan hasilnya ke `.env` tanpa memasukkannya ke Git:

```ini
storage.credentialsKey = 'BASE64_KEY_32_BYTE'
storage.cacheTtlSeconds = 86400
storage.cacheMaxBytes = 53687091200
```

`storage.credentialsKey` wajib pada production. Mengganti key tanpa mengenkripsi ulang credential akan membuat profile FTPS tidak dapat digunakan.

## 2. Siapkan certificate perusahaan

Jika FTPS menggunakan private CA, tempatkan CA bundle di:

```text
cms-server/writable/certificates/company-ca.pem
```

Jika perusahaan menggunakan mutual TLS, tempatkan client certificate dan private key di direktori yang sama:

```text
writable/certificates/cms-client.pem
writable/certificates/cms-client-key.pem
```

Path yang dimasukkan pada UI harus relatif terhadap `writable/certificates`, misalnya `company-ca.pem`. Absolute path dan traversal `..` ditolak.

## 3. Buat profile melalui CMS

1. Login sebagai Admin.
2. Buka **Storage**.
3. Klik **Add Storage**.
4. Pilih **Company FTPS**.
5. Isi data koneksi.
6. Klik **Create & Test Profile**.

Profile tetap tercatat jika test gagal agar konfigurasi dan pesan error dapat diperiksa. Profile yang gagal tidak dapat dijadikan default.

## 4. Arti field

| Field | Keterangan |
|---|---|
| Hostname | Host tanpa `ftps://` dan tanpa remote path |
| Explicit FTPS | Umumnya port 21; koneksi dinaikkan ke TLS |
| Implicit FTPS | Umumnya port 990; TLS dimulai sejak koneksi pertama |
| Remote root | Direktori/chroot khusus CMS, misalnya `/cms-media` |
| Passive mode | Disarankan untuk jaringan perusahaan/firewall |
| Connection timeout | Batas membangun koneksi dan TLS |
| Transfer timeout | Batas total satu operasi transfer |
| Cache lifetime | Waktu sebelum ukuran remote diperiksa kembali |
| Maximum cache | Batas cache per Storage Profile |
| CA bundle | CA internal relatif ke `writable/certificates` |
| Pinned public key | Format libcurl `sha256//Base64Hash` |
| Client certificate/key | Opsional untuk mutual TLS |

## 5. Firewall

Untuk passive FTPS, tim infrastruktur perlu menyediakan control port, passive data port range, IP allowlist untuk egress server CMS, DNS yang dapat di-resolve dari CMS, dan certificate chain lengkap.

FTPS memakai control dan data connection terpisah. Membuka port 21/990 saja belum tentu cukup jika passive data port diblokir.

## 6. Alur upload

```text
CMS LDG staging file
        |
        | lanjut dari remote size jika .part tersedia
        v
<remote-key>.part
        |
        | size verification
        v
atomic rename/publish
        |
        v
<remote-key>
```

Jika remote `.part` lebih besar daripada source, `.part` dibuang dan upload dimulai ulang. Jika ukurannya lebih kecil, transfer dilanjutkan dari offset tersebut. Object key final hanya digunakan setelah ukuran remote sesuai dengan source.

## 7. Alur download Player

```text
Player HTTP Range request
        |
        v
CMS StorageManager
        |
        v
FTPS cache materialization/resume
        |
        v
seekable local cache
        |
        v
HTTP Range response ke Player
```

Player tidak menerima hostname, username, password, certificate, atau akses langsung ke FTPS. Upload baru otomatis menanam salinan ke cache setelah FTPS publish berhasil. Jika cache telah dibersihkan, request pertama akan materialize objek dari FTPS sebelum file dilayani.

## 8. Credential rotation

Gunakan tombol **Configure** pada kartu FTPS. Password dan private-key password yang dikosongkan akan mempertahankan secret lama. Perubahan hanya disimpan setelah probe FTPS lengkap berhasil.

Jika profile sudah direferensikan asset, host, mode, port, dan remote root dikunci untuk mencegah asset lama tiba-tiba menunjuk ke server lain. Credential rotation dan pengaturan timeout/cache tetap dapat dilakukan.

## 9. Penggantian default

Profile FTPS hanya dapat dijadikan default setelah Test Connection berhasil. Upload baru kemudian masuk FTPS, sedangkan asset lama tetap pada profile sebelumnya. Schedule, endpoint Player, dan file lama tidak berubah.

## 10. Catatan operasional

- Jangan gunakan plain FTP.
- Jangan matikan TLS verification.
- Gunakan akun service khusus CMS dan chroot khusus.
- Jangan gunakan akun administrator FTPS.
- Pantau kapasitas remote dan cache CMS.
- Atur cleanup `.part` lama pada server secara hati-hati.
- Pastikan rename dalam filesystem/directory yang sama didukung.
- Lakukan test file besar dan force interruption sebelum production.
- Background transfer worker masih menjadi enhancement yang disarankan untuk upload multi-GB agar tidak bergantung pada batas waktu request web.
