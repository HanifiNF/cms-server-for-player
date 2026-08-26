# Cloud Media Storage — Integration Requirements dan Handoff

Dokumen ini berisi kebutuhan teknis yang harus disiapkan oleh tim pembuat cloud media storage agar dapat dihubungkan ke **Player CMS**. Dokumen ini dapat digunakan sebagai checklist diskusi, formulir handoff, dan dasar acceptance test.

## 1. Tujuan integrasi

Cloud media storage digunakan untuk menyimpan artefak media milik CMS, terutama:

- film terenkripsi dengan ekstensi `.ldg`;
- poster film;
- revisi film;
- objek sementara yang diperlukan selama upload multipart, bila provider menggunakannya.

Cloud media storage **bukan database CMS**. Metadata film, jadwal, Studio, Location, akun, assignment, status distribusi, dan audit tetap disimpan di PostgreSQL.

## 2. Arsitektur yang digunakan

Alur upload:

```text
Browser Admin/Distributor
        |
        v
CMS menerima file media
        |
        v
CMS mendeteksi metadata dan mengenkripsi media menjadi LDG v1
        |
        v
StorageManager memilih Storage Profile
        |
        v
Storage Driver mengirim objek .ldg ke media storage
```

Alur download Player:

```text
Player
  |
  | GET /api/player/assets/{assetId}/download
  v
CMS mengautentikasi Player dan memeriksa assignment
  |
  v
CMS membaca Storage Profile milik asset
  |
  v
Storage Driver mengambil objek dari media storage
  |
  v
CMS mengirim file/range yang diminta kepada Player
```

Keputusan arsitektur saat ini:

- bucket/container tidak perlu dibuat public;
- credential media storage hanya dimiliki CMS;
- Player tidak menerima credential atau endpoint storage;
- URL download Player tetap melalui CMS;
- enkripsi `.ldg` dilakukan CMS sebelum file masuk storage;
- perubahan default storage hanya berlaku untuk upload baru;
- asset lama tetap terhubung ke storage asalnya dan tidak dipindahkan otomatis;
- HTTP Range dan download resume harus tetap didukung;
- CORS pada storage tidak diperlukan selama browser tidak mengunggah langsung ke storage.

## 3. Protokol storage yang disarankan

Pilihan yang paling fleksibel adalah **S3-compatible API**. Protokol ini dapat digunakan oleh:

- MinIO;
- Amazon S3;
- penyedia object storage S3-compatible lainnya.

Jika storage menggunakan Azure Blob, Google Cloud Storage, atau REST API custom, CMS membutuhkan driver tambahan untuk provider tersebut.

Sebelum integrasi dimulai, pemilik storage harus menyatakan dengan jelas salah satu pilihan berikut:

```text
[ ] S3-compatible / MinIO
[ ] Amazon S3
[ ] Azure Blob Storage
[ ] Google Cloud Storage
[ ] REST API custom
[ ] Lainnya: __________________________
```

## 4. Data koneksi wajib untuk S3-compatible/MinIO

Tim storage harus memberikan data berikut:

| Data | Contoh | Wajib | Keterangan |
|---|---|---:|---|
| Nama provider | MinIO Production | Ya | Nama layanan/provider |
| Endpoint API | `https://storage.example.com` | Ya | Endpoint object API, bukan URL dashboard |
| Bucket | `cinema-media-production` | Ya | Bucket khusus media CMS |
| Region | `ap-southeast-1` | Ya | Nilai region untuk proses signing request |
| Path prefix | `cms-production/` | Disarankan | Membatasi namespace objek milik CMS |
| Access Key ID | diberikan secara rahasia | Ya | Service credential khusus CMS |
| Secret Access Key | diberikan secara rahasia | Ya | Jangan dikirim melalui repository/chat publik |
| Session token | bila digunakan | Kondisional | Diperlukan untuk credential sementara |
| Path-style access | `true`/`false` | Ya | Sering diperlukan oleh instalasi MinIO |
| Signature version | `v4` | Ya | S3 Signature Version 4 disarankan |
| TLS/HTTPS | `enabled` | Produksi | HTTP hanya boleh untuk test lokal terisolasi |
| CA certificate | file `.pem` | Kondisional | Diperlukan jika memakai private/internal CA |
| Port | `443` | Ya | Port endpoint storage |

Contoh konfigurasi yang diharapkan:

```text
Profile name        : Production Media Storage
Protocol            : S3-compatible
Endpoint            : https://storage.example.com
Bucket              : cinema-media-production
Region              : ap-southeast-1
Prefix              : cms-production/
Path-style access   : true
Signature version   : v4
TLS                 : enabled
```

## 5. Service account dan permission minimum

Gunakan service account khusus CMS. Jangan memberikan akun root, owner, atau administrator storage.

Permission minimum yang dibutuhkan:

- `PutObject` — mengunggah objek baru;
- `GetObject` — membaca objek untuk download Player;
- `HeadObject` — memeriksa keberadaan, ukuran, dan metadata objek;
- `DeleteObject` — menghapus objek setelah penghapusan resmi dari CMS;
- `ListBucket` — pemeriksaan prefix, health check, dan rekonsiliasi;
- `CreateMultipartUpload` — memulai upload film besar;
- `UploadPart` — mengirim bagian upload;
- `CompleteMultipartUpload` — menyelesaikan upload;
- `ListMultipartUploadParts` — melanjutkan atau memeriksa upload;
- `AbortMultipartUpload` — membatalkan upload yang gagal.

Scope permission harus dibatasi ke bucket dan prefix CMS, misalnya:

```text
Bucket : cinema-media-production
Prefix : cms-production/*
```

Contoh kebijakan S3 konseptual berikut harus disesuaikan oleh administrator storage:

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Effect": "Allow",
      "Action": [
        "s3:ListBucket",
        "s3:ListBucketMultipartUploads"
      ],
      "Resource": "arn:aws:s3:::cinema-media-production",
      "Condition": {
        "StringLike": {
          "s3:prefix": ["cms-production/*"]
        }
      }
    },
    {
      "Effect": "Allow",
      "Action": [
        "s3:GetObject",
        "s3:PutObject",
        "s3:DeleteObject",
        "s3:AbortMultipartUpload",
        "s3:ListMultipartUploadParts"
      ],
      "Resource": "arn:aws:s3:::cinema-media-production/cms-production/*"
    }
  ]
}
```

Kebijakan aktual dapat berbeda berdasarkan provider. Administrator storage bertanggung jawab memvalidasi nama action yang didukung oleh provider.

## 6. Persyaratan jaringan

Tim storage harus menjawab:

1. Apakah endpoint dapat diakses dari server CMS?
2. Apakah akses membutuhkan VPN, private network, VPC peering, atau tunnel?
3. Apakah endpoint memakai IP allowlist?
4. Jika memakai allowlist, IP publik/egress server CMS mana yang harus didaftarkan?
5. Apakah DNS endpoint dapat di-resolve dari server CMS?
6. Port apa yang harus dibuka?
7. Apakah proxy diperlukan?
8. Apakah ada batas jumlah connection atau request per detik?
9. Apakah endpoint development dapat diakses dari komputer developer?

Persyaratan produksi minimum:

- gunakan HTTPS;
- sertifikat TLS harus valid dan belum kedaluwarsa;
- hostname sertifikat harus sama dengan hostname endpoint;
- TLS verification tidak boleh dimatikan;
- waktu server CMS dan storage harus tersinkronisasi melalui NTP karena signed request sensitif terhadap perbedaan waktu;
- firewall harus mengizinkan koneksi keluar CMS ke storage;
- storage tidak perlu menerima koneksi langsung dari Player.

## 7. Persyaratan upload film besar

Film produksi dapat berukuran beberapa gigabyte. Storage perlu mendukung:

- multipart upload;
- part upload yang dapat diulang secara independen;
- penyelesaian multipart secara atomik;
- abort untuk upload yang gagal;
- pembersihan multipart yang tidak selesai;
- timeout yang cukup untuk file besar;
- object size limit yang lebih besar dari film terbesar;
- verifikasi ukuran objek setelah upload;
- retry dengan exponential backoff;
- idempotency atau upload identifier yang stabil.

Data yang perlu disepakati:

| Parameter | Nilai yang diberikan tim storage |
|---|---|
| Maksimum ukuran satu objek | |
| Ukuran part minimum | |
| Ukuran part maksimum | |
| Maksimum jumlah part | |
| Upload timeout | |
| Idle connection timeout | |
| Retensi multipart tidak selesai | |
| Batas request per detik | |

Catatan: progress upload dari browser ke CMS dan progress CMS ke cloud storage merupakan dua proses yang berbeda. UI CMS nantinya perlu menentukan progress mana yang ditampilkan.

## 8. Persyaratan download dan resume Player

Player sudah mendukung download resume. Integrasi storage tidak boleh menghilangkan kemampuan ini.

Storage/driver perlu mendukung salah satu pendekatan berikut:

1. membaca byte range langsung dari provider; atau
2. menyediakan stream seekable/cache lokal yang dapat digunakan CMS untuk merespons HTTP Range.

CMS harus tetap dapat menghasilkan respons berikut:

```text
Accept-Ranges: bytes
Content-Length: <jumlah byte respons>
Content-Range: bytes <start>-<end>/<total>
ETag: <identifier versi file>
```

Jika Player telah mengunduh sebagian file, CMS harus dapat melanjutkan dari byte terakhir yang valid, bukan mengulang dari awal.

## 9. Integritas dan checksum

CMS saat ini mencatat SHA-256 file `.ldg`. Setelah upload selesai, sistem harus memverifikasi setidaknya:

- objek tersedia melalui `HEAD`/metadata request;
- ukuran objek sama dengan ukuran file sumber;
- checksum CMS tetap tersimpan;
- objek yang diambil kembali menghasilkan SHA-256 yang sama dalam proses audit atau health check.

Jangan menganggap `ETag` multipart sebagai SHA-256. Pada banyak implementasi S3, ETag multipart bukan hash isi file secara langsung.

Metadata objek yang disarankan:

```text
Content-Type        : application/vnd.wirgroup.ldg
asset-public-id     : UUID asset
asset-revision      : nomor revisi
cms-sha256          : SHA-256 objek LDG
encryption-format   : ldg-v1
```

Metadata tersebut bersifat pelengkap. Sumber metadata utama tetap PostgreSQL CMS.

## 10. Enkripsi dan keamanan

### Enkripsi media

- CMS mengenkripsi plaintext menjadi LDG v1 sebelum upload.
- Plaintext film tidak boleh disimpan permanen di cloud media storage.
- Storage hanya menerima file `.ldg`, poster, dan artefak lain yang diizinkan.
- Dekripsi dilakukan oleh Player melalui mekanisme lisensi perangkat.

### Enkripsi storage

Selain enkripsi LDG, server-side encryption storage tetap disarankan:

```text
[ ] Provider-managed encryption
[ ] SSE-S3
[ ] SSE-KMS
[ ] Enkripsi at-rest MinIO
[ ] Lainnya: __________________________
```

### Credential

- credential tidak boleh masuk Git;
- credential tidak boleh dikirim ke Player;
- secret tidak boleh ditampilkan kembali secara utuh di UI;
- credential production dan development harus berbeda;
- credential harus dapat dirotasi;
- rotasi credential tidak boleh mengubah storage key asset;
- log tidak boleh merekam access key, secret key, session token, atau signed URL secara lengkap;
- credential akan disimpan melalui environment secret atau penyimpanan credential terenkripsi CMS.

## 11. Bucket, prefix, dan penamaan objek

Struktur yang disarankan:

```text
cms-production/
├── assets/
│   ├── <asset-uuid>-r1.ldg
│   ├── <asset-uuid>-r2.ldg
│   └── ...
├── posters/
│   ├── <asset-uuid>.jpg
│   └── ...
└── temporary/
    └── multipart-or-staging-objects
```

CMS menyimpan object key, bukan URL publik:

```text
assets/123e4567-e89b-42d3-a456-426614174000-r1.ldg
```

Object key harus:

- stabil;
- unik;
- tidak mengandung absolute filesystem path;
- tidak bergantung pada judul film;
- tidak berubah saat judul/metadata film diedit.

## 12. Lifecycle, versioning, dan penghapusan

Tim storage harus memberikan informasi berikut:

| Kebijakan | Jawaban |
|---|---|
| Bucket versioning aktif | |
| Object Lock aktif | |
| Minimum retention | |
| Auto-delete/lifecycle aktif | |
| Retensi delete marker | |
| Retensi multipart tidak selesai | |
| Backup aktif | |
| Recovery Point Objective (RPO) | |
| Recovery Time Objective (RTO) | |

Aturan penting:

- lifecycle storage tidak boleh menghapus film aktif;
- expiration CMS tetap menjadi sumber aturan distribusi film;
- ketika film expired, CMS melakukan unassign dan memerintahkan penghapusan file Player;
- penghapusan dari cloud storage hanya dilakukan melalui workflow delete asset CMS;
- profile storage yang masih direferensikan asset/revisi tidak dapat dihapus;
- perubahan default profile tidak memindahkan file lama;
- migrasi antar-storage harus menjadi proses khusus dengan progress, checksum verification, dan rollback.

## 13. Environment yang diperlukan

Minimal sediakan dua environment terpisah:

### Development/testing

```text
Bucket : cinema-media-test
Prefix : cms-test/
Account: cms-storage-test
```

### Production

```text
Bucket : cinema-media-production
Prefix : cms-production/
Account: cms-storage-production
```

Jangan menggunakan credential, bucket, atau prefix production untuk development.

Jika tersedia, staging dapat ditambahkan sebagai environment ketiga.

## 14. Monitoring dan operasional

Tim storage sebaiknya menyediakan:

- dashboard penggunaan kapasitas;
- metrik latency dan error rate;
- audit log operasi `PUT`, `GET`, dan `DELETE`;
- alert kapasitas hampir penuh;
- alert error autentikasi;
- alert sertifikat TLS mendekati kedaluwarsa;
- alert multipart upload menumpuk;
- informasi maintenance window;
- kontak penanggung jawab insiden.

CMS akan menyediakan fungsi **Test Connection** untuk memeriksa autentikasi dan write access. Test tidak boleh menghapus atau mengubah asset produksi; gunakan objek probe kecil dengan nama khusus lalu hapus kembali.

## 15. Jika menggunakan REST API custom

Jika storage tidak menyediakan API standar, tim storage harus menyerahkan:

- dokumentasi OpenAPI/Swagger;
- base URL development dan production;
- metode autentikasi;
- proses refresh/rotasi token;
- endpoint memulai upload;
- endpoint multipart/chunk upload;
- endpoint menyelesaikan upload;
- endpoint membatalkan upload;
- endpoint download;
- dukungan HTTP Range;
- endpoint metadata/HEAD;
- endpoint existence check;
- endpoint delete;
- format error dan HTTP status;
- retry policy;
- idempotency mechanism;
- batas ukuran request;
- timeout;
- rate limit;
- contoh request dan response;
- SDK resmi bila tersedia;
- versi API dan kebijakan backward compatibility.

API custom minimal harus mendukung operasi driver CMS berikut:

```text
putFile
materialize/readRange
exists/headObject
delete
testConnection
```

## 16. Data yang tidak perlu diberikan

Tim storage tidak perlu memberikan:

- credential PostgreSQL;
- password operator/admin CMS;
- credential Player;
- kunci dekripsi LDG;
- akses desktop ke PC Player;
- public URL permanen untuk setiap film;
- akun root storage.

## 17. Acceptance test integrasi

Integrasi dianggap berhasil jika seluruh test berikut lulus.

### Koneksi

- [ ] CMS dapat menjangkau endpoint melalui HTTPS.
- [ ] DNS dan TLS certificate valid.
- [ ] Test Connection berhasil dengan service account CMS.
- [ ] Credential dengan permission tidak mencukupi ditolak secara aman.

### Upload

- [ ] CMS dapat mengunggah file `.ldg` kecil.
- [ ] CMS dapat mengunggah film berukuran besar dengan multipart.
- [ ] Progress dapat dipantau.
- [ ] Upload yang terganggu dapat di-retry atau dilanjutkan.
- [ ] Upload gagal tidak menghasilkan asset aktif yang menunjuk ke objek tidak lengkap.
- [ ] Multipart tidak selesai dapat dibatalkan/dibersihkan.

### Integritas

- [ ] Ukuran objek setelah upload sesuai.
- [ ] SHA-256 objek sesuai dengan metadata CMS.
- [ ] Poster dapat disimpan dan dibaca.
- [ ] Revisi film menggunakan object key terpisah.

### Download Player

- [ ] Player dapat mengunduh film melalui endpoint CMS yang sama.
- [ ] Bucket tidak perlu dibuat public.
- [ ] Download resume dari partial file berhasil.
- [ ] HTTP Range menghasilkan byte yang benar.
- [ ] Player tidak memperoleh credential storage.
- [ ] Film `.ldg` dapat dimainkan hanya melalui Player yang berlisensi.

### Lifecycle

- [ ] Penggantian default profile hanya memengaruhi upload baru.
- [ ] Asset lama tetap dapat diunduh dari profile sebelumnya.
- [ ] Delete asset menghapus objek yang benar.
- [ ] Profile yang masih direferensikan tidak dapat dihapus.
- [ ] Film expired mengikuti workflow CMS dan tidak dihapus storage lebih awal oleh lifecycle provider.

### Reliability

- [ ] Timeout storage tidak membuat CMS kehilangan metadata asset.
- [ ] Retry tidak menghasilkan objek duplikat yang tidak terkontrol.
- [ ] Gangguan storage ditampilkan sebagai error yang dapat ditindaklanjuti.
- [ ] Log tidak membocorkan credential.

## 18. Formulir handoff untuk tim storage

Salin dan isi formulir berikut.

```text
CLOUD MEDIA STORAGE HANDOFF

A. INFORMASI UMUM
Nama layanan/provider       :
Penanggung jawab            :
Kontak                      :
Protocol/API                :
Versi API                   :

B. DEVELOPMENT/TESTING
Endpoint API                :
Port                        :
Bucket/container            :
Region                      :
Prefix                      :
Path-style access           : true / false
Signature version           :
Access Key ID               : diserahkan melalui kanal rahasia
Secret/credential           : diserahkan melalui kanal rahasia
Session token expiration    :
CA certificate              :
VPN/private network         :
IP allowlist                :
Proxy                       :

C. PRODUCTION
Endpoint API                :
Port                        :
Bucket/container            :
Region                      :
Prefix                      :
Path-style access           : true / false
Signature version           :
Access Key ID               : diserahkan melalui kanal rahasia
Secret/credential           : diserahkan melalui kanal rahasia
Session token expiration    :
CA certificate              :
VPN/private network         :
IP allowlist                :
Proxy                       :

D. LIMIT DAN MULTIPART
Maksimum ukuran objek       :
Ukuran part minimum         :
Ukuran part maksimum        :
Maksimum jumlah part        :
Upload timeout              :
Idle timeout                :
Rate limit                  :
Retensi multipart gagal     :
HTTP Range support          : ya / tidak

E. KEAMANAN
Server-side encryption      :
Credential rotation policy  :
Audit log                   :
Object Lock                 :
TLS version minimum         :

F. LIFECYCLE DAN BACKUP
Bucket versioning           :
Lifecycle auto-delete       :
Retention                   :
Backup                      :
RPO                         :
RTO                         :
Recovery procedure/document :

G. OPERASIONAL
Monitoring dashboard        :
Capacity alert              :
Incident contact            :
Maintenance window          :
Status page                 :

H. DOKUMENTASI
API documentation URL       :
SDK/library                 :
Example request/response    :
Known limitations           :
```

## 19. Checklist sebelum implementasi driver CMS

Implementasi adapter cloud dimulai setelah item berikut tersedia:

- [ ] provider/protocol telah ditentukan;
- [ ] endpoint development tersedia;
- [ ] bucket development tersedia;
- [ ] service account development tersedia;
- [ ] permission telah diuji;
- [ ] kebutuhan path-style dan region diketahui;
- [ ] TLS/CA selesai dikonfigurasi;
- [ ] jaringan CMS ke storage terbuka;
- [ ] multipart upload dikonfirmasi;
- [ ] HTTP Range dikonfirmasi;
- [ ] object size limit mencukupi;
- [ ] lifecycle dan retention disepakati;
- [ ] metode penyimpanan credential disepakati;
- [ ] acceptance test disetujui kedua tim.

## 20. Status implementasi CMS saat ini

CMS sudah memiliki:

- tabel Storage Profile;
- StorageManager;
- kontrak Storage Driver;
- Local Storage Driver;
- pemilihan default storage untuk upload baru;
- referensi profile per asset dan per revisi;
- halaman admin Storage Settings;
- Test Connection untuk driver lokal;
- perlindungan agar default profile tidak dapat dinonaktifkan/dihapus;
- perlindungan agar profile yang masih digunakan tidak dapat dihapus;
- endpoint download Player yang stabil;
- dukungan HTTP Range dan resume pada alur download saat ini.

CMS sekarang memiliki adapter SFTP konkret dengan mandatory SSH host-key pinning serta adapter FTPS Explicit/Implicit TLS. Keduanya mendukung resumable `.part` transfer, atomic publish, encrypted credentials, connection probe, dan cache untuk Player HTTP Range. Adapter S3, Azure Blob, dan Google Cloud Storage belum dibuat.
