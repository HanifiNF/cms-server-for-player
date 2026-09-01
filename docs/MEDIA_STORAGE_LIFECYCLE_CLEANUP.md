# Media Storage Lifecycle Cleanup

## Status dokumen

- Status: planned, belum diimplementasikan.
- Cakupan: file media CMS, poster, revisi asset, file sementara, orphan object,
  serta koordinasi penghapusan salinan terkelola pada Player.
- Storage awal: SFTP perusahaan, tetapi kebijakan harus bekerja melalui
  `StorageManager` agar dapat digunakan oleh adapter lain di masa depan.
- Dokumen ini bukan izin untuk menjalankan penghapusan langsung pada storage
  produksi.

## Latar belakang

Upload distributor saat ini diproses sampai selesai sebelum administrator
melakukan review. CMS mendeteksi durasi, mengenkripsi film menjadi LDG v1,
mengunggah file terenkripsi dan poster ke storage default, kemudian mencatat
asset sebagai `draft`. Approval hanya mengubah izin distribusi menjadi
`active`; distributor tidak perlu online kembali.

Alur tersebut dipertahankan karena approval dapat dilakukan secara asynchronous
dan file sudah dapat diverifikasi sebelum dinyatakan aktif. Konsekuensinya,
`draft`, `rejected`, revisi lama, dan kegagalan parsial dapat mengonsumsi storage.
Lifecycle cleanup diperlukan agar pertumbuhan storage terkendali tanpa
menghapus media yang masih dibutuhkan.

## Tujuan

1. Membatasi umur asset yang tidak pernah disetujui atau diperbaiki.
2. Membersihkan upload sementara dan object yatim secara aman.
3. Mempertahankan file aktif, revision history yang diwajibkan, dan file yang
   masih dipakai assignment atau schedule.
4. Membuat setiap keputusan penghapusan dapat dijelaskan dan diaudit.
5. Menyediakan dry-run, grace period, retry, dan recovery operasional.
6. Menjaga cleanup CMS terpisah dari cleanup salinan media pada Player.
7. Mendukung SFTP tanpa mengikat desain lifecycle pada protokol tersebut.

## Bukan tujuan

- Cleanup tidak menggantikan backup atau disaster recovery.
- Cleanup tidak boleh menjadi cara tersembunyi untuk menghapus audit record.
- Cleanup storage CMS tidak langsung membuktikan bahwa file di seluruh Player
  sudah terhapus.
- Penghapusan file aktif tidak boleh dilakukan hanya karena `last_accessed_at`
  sudah lama.
- Job tidak boleh menelusuri dan menghapus path di luar root storage profile
  yang telah divalidasi.

## Istilah status yang direkomendasikan

Status `draft` saat ini secara bisnis lebih tepat disebut `pending_review`,
karena upload sudah lengkap. Migrasi nama dapat dilakukan terpisah. State yang
direkomendasikan untuk desain akhir:

- `uploading`: transfer belum lengkap dan belum boleh direview.
- `processing`: hashing, duration probe, enkripsi, atau verifikasi berlangsung.
- `pending_review`: file lengkap di storage dan menunggu administrator.
- `active`: disetujui dan boleh didistribusikan.
- `rejected`: ditolak dan masih berada dalam masa perbaikan.
- `expired`: melewati masa lisensi/validity dan tidak boleh didistribusikan.
- `deletion_pending`: sudah memenuhi kebijakan, menunggu grace period atau
  konfirmasi dependency.
- `deleted`: object media telah dihapus, tetapi tombstone/audit metadata tetap
  dipertahankan.
- `deletion_failed`: penghapusan gagal dan perlu retry atau tindakan admin.

Implementasi awal boleh mempertahankan nama database `draft`, tetapi UI dan
policy engine harus memperlakukannya sebagai upload lengkap yang menunggu review.

## Kebijakan retensi awal

Nilai berikut adalah default rancangan dan harus dapat dikonfigurasi per
environment. Keputusan final perlu disetujui pemilik produk dan tim compliance.

| Kategori | Kandidat retensi | Aksi setelah retensi |
| --- | ---: | --- |
| Upload `.part`/temporary yang tidak selesai | 24 jam | Hapus jika tidak memiliki upload session aktif |
| `draft`/`pending_review` tanpa aktivitas | 30 hari | Tandai `deletion_pending`, beri grace period 7 hari |
| `rejected` tanpa resubmission | 30 hari | Tandai `deletion_pending`, beri grace period 7 hari |
| Revisi lama yang tidak aktif | 90 hari | Hapus binary jika tidak dipin dan tidak dibutuhkan audit |
| Poster yang tidak direferensikan | 7 hari sejak terdeteksi | Hapus setelah dua scan konsisten |
| Object media orphan | 7 hari sejak terdeteksi | Hapus setelah dua scan konsisten dan verifikasi prefix |
| Cache materialisasi CMS | Berdasarkan ukuran/TTL | Evict LRU; bukan menghapus source storage |
| Asset `active` | Tidak otomatis berdasarkan umur | Hanya melalui workflow eksplisit/expiry yang aman |
| Tombstone dan audit cleanup | Minimal sesuai kebijakan audit | Jangan mengikuti TTL binary secara otomatis |

`created_at` saja tidak cukup untuk menghitung inactivity. Aktivitas yang dapat
memperbarui retention anchor meliputi edit metadata, resubmission, review, serta
aksi administrator yang secara eksplisit memperpanjang retensi.

## Aturan keselamatan utama

Object hanya boleh menjadi kandidat penghapusan jika seluruh syarat berikut
terpenuhi:

1. Storage profile dan storage key masih dapat diidentifikasi secara pasti.
2. Key berada di bawah prefix media/poster yang diizinkan dan lolos normalisasi
   traversal path.
3. Asset tidak `active` dan tidak sedang diproses.
4. Tidak ada assignment aktif atau `removal_pending` yang masih memerlukan
   koordinasi Player.
5. Tidak ada schedule aktif/mendatang yang mereferensikan revisi tersebut.
6. Revisi bukan current approved revision dan tidak diberi legal/audit hold.
7. Grace period sudah selesai.
8. Kandidat telah terlihat dalam setidaknya dua reconciliation scan terpisah.
9. Job mempunyai idempotency key dan belum mencatat sukses untuk object yang
   sama.
10. Penghapusan database final hanya dilakukan setelah adapter mengonfirmasi
    object tidak ada atau berhasil dihapus.

Jika dependency tidak dapat dipastikan, hasilnya harus `blocked`, bukan tetap
dihapus.

## Pemisahan jenis cleanup

### 1. Temporary upload cleanup

- Berlaku untuk file PHP temp yang masih dikelola aplikasi, temporary LDG,
  `.part`, dan probe object.
- File yang sedang mempunyai lock/upload session tidak boleh disentuh.
- Startup recovery dapat menandai session yang tidak selesai; scheduled job
  membersihkannya setelah TTL.
- Prefix `.cms-probe` hanya boleh dibersihkan sesuai kontrak adapter dan tidak
  boleh menyebabkan profile sehat dianggap rusak.

### 2. Pending review dan rejected cleanup

- Kirim peringatan kepada distributor sebelum grace period berakhir.
- Tampilkan tanggal penghapusan terjadwal dan tombol `Keep / extend retention`
  untuk admin.
- Saat deadline tercapai, ubah status menjadi `deletion_pending` terlebih dulu.
- Jangan menghapus langsung di request web; queue worker melakukan operasi
  storage dan mencatat hasilnya.
- Jika distributor melakukan resubmit sebelum worker mengeksekusi, kandidat
  dibatalkan secara atomik.

### 3. Revision cleanup

- `assets` menunjuk current revision; `asset_versions` menyimpan revision
  history.
- Binary revisi lama dapat dihapus terpisah dari metadata revision.
- Current approved binary tidak boleh dihapus.
- Revisi yang masih direferensikan schedule snapshot, assignment, investigation,
  atau audit hold harus dipin.
- Metadata seperti hash, ukuran, submitter, reviewer, alasan reject, dan waktu
  review dapat dipertahankan walaupun binary lama sudah dihapus.

### 4. Orphan reconciliation

Ada dua arah orphan yang harus dibedakan:

- Storage object tanpa record database: kemungkinan upload gagal setelah
  `putFile`, transaksi rollback, atau penghapusan record manual.
- Record database tanpa storage object: insiden integritas; jangan menghapus
  record secara otomatis. Tandai `missing`, buat alert, dan cari backup.

Reconciliation tidak boleh melakukan recursive delete terhadap folder yang
dihitung dari input bebas. Job hanya boleh membandingkan key hasil listing
adapter dengan key database yang telah dinormalisasi. Folder judul yang kosong
boleh dihapus setelah seluruh child object yang diketahui terhapus dan adapter
mengonfirmasi folder kosong.

### 5. Poster cleanup

- Poster current asset dipertahankan selama asset record masih ada.
- Saat poster diganti, poster lama menjadi kandidat orphan setelah transaksi
  metadata berhasil.
- Poster tidak boleh dihapus sebelum memastikan tidak ada asset lain yang
  mereferensikan key yang sama.

### 6. Player managed-media cleanup

Storage cleanup CMS dan file Player adalah dua workflow berbeda:

1. Admin unassign dan memilih file removal.
2. CMS membuat `removal_pending` serta menaikkan asset revision perangkat.
3. Player menerima event/snapshot, memastikan file tidak sedang diputar,
   menghapus `.ldg` dan folder film kosong, lalu mengirim acknowledgment.
4. CMS menutup assignment setelah acknowledgment.
5. Source binary CMS baru boleh dihapus jika lifecycle asset juga mengizinkan.

CMS tidak boleh menganggap Player offline telah menghapus file. Player yang
hilang permanen diselesaikan melalui revoke/decommission Studio dan audit event,
bukan dengan acknowledgment palsu.

## Kandidat perubahan data

Struktur final perlu dirancang melalui migration terpisah. Kandidat field/tabel:

- `assets`
  - `retention_expires_at`
  - `deletion_status`
  - `deletion_requested_at`, `deletion_requested_by`
  - `deleted_at`
  - `retention_hold_until`, `retention_hold_reason`
- `asset_versions`
  - `binary_status` (`present`, `deletion_pending`, `deleted`, `missing`)
  - `binary_deleted_at`
  - `retention_expires_at`
- `storage_cleanup_candidates`
  - resource type, storage profile ID, normalized storage key
  - reason/policy version, first seen, eligible after
  - state, attempts, last error, idempotency key
- `storage_cleanup_runs`
  - mode (`dry_run`, `execute`), policy version, counters, initiator
  - start/end timestamps dan summary
- `storage_orphans`
  - direction (`storage_only`, `database_only`), first/last seen, resolution
- Audit event untuk mark, cancel, extend, delete, failure, retry, dan restore.

Credential storage, plaintext DEK, dan password SFTP tidak boleh disalin ke tabel
cleanup atau log.

## Arsitektur job

Lifecycle sebaiknya dijalankan sebagai CLI/worker, bukan request halaman:

1. `scan`: menghasilkan kandidat tanpa mutasi.
2. `mark`: menyimpan kandidat dan waktu `eligible_after`.
3. `notify`: mengirim peringatan sebelum deadline.
4. `execute`: mengunci kandidat, memeriksa ulang dependency, lalu memanggil
   adapter delete.
5. `verify`: memastikan object tidak ada dan memperbarui binary/tombstone state.
6. `reconcile`: membandingkan database dan storage secara berkala.

Gunakan database advisory lock atau lease row agar hanya satu worker menjalankan
scope profile yang sama. Setiap operasi harus idempotent: `not found` setelah
retry dapat dianggap sukses jika key dan kandidatnya benar.

Batch harus dibatasi berdasarkan jumlah object dan total byte agar tidak
membebani SFTP. Terapkan exponential backoff, maximum attempts, dan dead-letter
state untuk kegagalan permanen.

## Dukungan adapter dan SFTP

Kontrak adapter lifecycle minimal perlu menyediakan:

- delete satu object berdasarkan normalized key;
- existence/stat check;
- bounded listing berdasarkan prefix dengan pagination/cursor bila tersedia;
- penghapusan direktori kosong yang opsional;
- error classification: not found, permission, transient network, host-key,
  authentication, dan invalid path.

SFTP tidak memiliki lifecycle rules server-side seperti object storage tertentu,
sehingga CMS worker bertanggung jawab atas scan dan deletion. Host-key pinning,
root directory restriction, serta permission account tetap wajib. Cleanup tidak
boleh mengikuti symlink keluar dari storage root.

## UI administrasi yang direncanakan

- Ringkasan kapasitas per profile, status, dan kategori asset.
- Filter `Pending cleanup`, `Deletion failed`, `On hold`, dan `Orphan`.
- Estimasi jumlah object dan byte yang akan dibebaskan.
- Detail alasan policy serta dependency yang memblokir penghapusan.
- Aksi admin: extend retention, cancel pending deletion, retry, dan place hold.
- Dry-run report yang dapat diunduh sebelum execute mode diaktifkan.
- Distributor hanya melihat asset miliknya dan deadline; tidak dapat mengubah
  system policy atau melihat storage key/credential.

## Observability dan audit

Metric minimum:

- candidate count/bytes per reason dan profile;
- deleted count/bytes;
- blocked, failed, retry, dan orphan count;
- oldest pending candidate;
- scan dan execution duration;
- SFTP/API error rate.

Log tidak boleh memuat credential, plaintext key, signed download URL, atau
device token. Storage key dapat disamarkan pada log umum dan tetap tersedia pada
audit admin yang aksesnya dibatasi.

Alert diperlukan jika active asset hilang, database-only orphan ditemukan,
cleanup failure terus berulang, kapasitas melewati threshold, atau job tidak
berjalan sesuai jadwal.

## Rollout bertahap

1. Implementasikan scan dan dry-run report tanpa delete.
2. Jalankan pada development menggunakan test profile dan fixture storage.
3. Tambahkan candidate table, grace period, serta UI read-only.
4. Aktifkan cleanup hanya untuk temporary file yang sangat aman.
5. Aktifkan rejected/pending review policy dengan notifikasi.
6. Tambahkan revision cleanup setelah dependency pinning diuji.
7. Aktifkan orphan deletion terakhir, setelah minimal dua reconciliation scan.
8. Uji restore dari backup dan prosedur emergency stop sebelum produksi.

Feature flag terpisah diperlukan untuk `scan`, `mark`, dan `execute`. Default
produksi harus `execute=false` sampai dry-run ditinjau dan disetujui.

## Skenario pengujian minimum

- Upload gagal setelah media berhasil dikirim tetapi sebelum transaksi database
  commit; object menjadi orphan dan ditemukan scanner.
- Draft diedit tepat sebelum deadline; kandidat dibatalkan/diperpanjang.
- Draft disetujui ketika deletion worker menunggu; active check mencegah delete.
- Rejected asset di-resubmit dengan dan tanpa replacement binary.
- SFTP offline, permission denied, host key mismatch, dan delete timeout.
- Worker mati setelah remote delete tetapi sebelum database update; retry tetap
  idempotent.
- Schedule mendatang, assignment aktif, dan removal pending memblokir delete.
- Poster diganti dan poster lama dibersihkan tanpa menyentuh poster baru.
- Folder hybrid `assets/judul-id/hash.ldg` dihapus hanya jika kosong.
- Player offline tidak menghasilkan acknowledgment penghapusan palsu.
- Dua worker bersamaan tidak menghapus atau menghitung kandidat dua kali.
- Dry-run tidak mengubah database bisnis maupun storage.

## Kriteria penerimaan awal

- Tidak ada active/current approved binary yang dapat menjadi kandidat otomatis.
- Setiap delete mempunyai policy reason, actor/job, timestamp, dan idempotency
  key.
- Dry-run dan execute menghasilkan perhitungan kandidat yang konsisten.
- Penghapusan gagal tidak menghapus metadata referensi dan dapat di-retry.
- SFTP path traversal, root deletion, recursive broad delete, serta symlink
  escape ditolak.
- Admin dapat melihat dan membatalkan deletion selama grace period.
- Cleanup Player tetap menggunakan removal acknowledgment yang sudah dirancang.
- Seluruh policy dapat dinonaktifkan tanpa menghentikan upload, approval,
  assignment, schedule, atau playback.

## Keputusan yang masih diperlukan

- Retention final untuk pending review, rejected, revision lama, dan audit.
- Apakah ada legal/compliance hold dan siapa yang boleh menetapkannya.
- Apakah distributor memperoleh quota byte, jumlah asset, atau keduanya.
- Kanal notifikasi dan siapa penerimanya.
- Backup/restore SLA sebelum binary permanen dihapus.
- Apakah revisi lama wajib disimpan penuh atau metadata/hash saja.
- Jadwal worker di development, staging, dan production.

