# HTTPS Media Gateway Tanpa Cache Film CMS

## 1. Ringkasan dan keputusan

Tujuan: Player mengunduh film dari penyimpanan SFTP melalui HTTPS, tanpa membuat salinan penuh film dalam cache disk CMS.

Keputusan yang sudah disepakati:

- Gateway berjalan sebagai **proses Node.js terpisah di server CMS yang sama**.
- Gateway tidak digabung dengan realtime gateway port 3001.
- SFTP tetap menjadi penyimpanan permanen.
- CMS tetap mengelola autentikasi, assignment, metadata, dan lisensi LDG.
- Cache film dihapus dari alur SFTP; cache poster tetap tersedia dengan batas kecil.
- Beban awal belum diketahui: default **4 transfer aktif secara keseluruhan**, maksimal **2 per Player**.
- Administrator memiliki akses untuk menyiapkan domain, HTTPS, layanan, dan jaringan.
- Format LDG, master key, Encryption Tool, serta manual side-load tidak berubah.

## 2. Arsitektur dan alur

### Upload melalui CMS

1. Browser mengirim film secara resumable ke `upload-staging`.
2. CMS menghitung SHA-256 dan mengenkripsi menjadi LDG di `storage-staging`.
3. CMS mengunggah LDG ke SFTP menggunakan mekanisme publikasi file sementara yang sudah ada.
4. Setelah publikasi SFTP dan pencatatan katalog berhasil, plaintext serta LDG sementara dibersihkan.
5. CMS **tidak menyalin LDG ke `storage-cache`**.

Jika finalisasi gagal, pertahankan sumber yang diperlukan untuk retry sesuai siklus session. Rencana ini tidak menambahkan kemampuan melanjutkan proses enkripsi yang terputus.

### Unduhan Player

1. Player mengakses endpoint unduhan CMS yang sudah ada, membawa token perangkat.
2. CMS memeriksa perangkat, assignment, status film, dan revision.
3. CMS mengembalikan **HTTP 307** menuju URL gateway dengan tiket sementara.
4. Gateway memvalidasi tiket melalui endpoint internal CMS.
5. Gateway membuka file SFTP dan meneruskan byte yang diminta ke Player menggunakan buffer RAM terbatas.
6. Player menyimpan LDG di disk lokal, memverifikasi SHA-256, lalu memakai lisensi CMS untuk playback lokal.

Tidak ada salinan penuh film pada disk gateway maupun cache CMS. Namun, setiap unduhan tetap menggunakan bandwidth SFTP dan bandwidth server CMS.

HTTPS di sini adalah pengiriman file LDG, bukan perubahan menjadi playback streaming langsung dari internet.

## 3. Perubahan implementasi

### A. Layanan media gateway

Tambahkan layanan `media-gateway` dengan Node.js dan pustaka `ssh2`, menggunakan versi dependensi yang dikunci melalui lockfile.

Konfigurasi awal:

- URL publik: `https://media.layardigi.id`, disiapkan melalui DNS dan sertifikat TLS.
- Listener Node: `127.0.0.1:3002`, dapat dikonfigurasi.
- Proses tunggal dengan systemd dan restart otomatis.
- Jalankan sebagai pengguna non-root, tanpa akses tulis ke workspace media.
- Tidak memiliki akses database, `ldg.masterKey`, atau `storage.credentialsKey`.

Gateway menyediakan:

- `GET /media/download?ticket=...`
- `HEAD /media/download?ticket=...`
- Endpoint health internal, tanpa informasi rahasia.

Perilaku transfer:

- Mendukung unduhan penuh dan satu HTTP byte range, termasuk range terbuka dan suffix.
- Menghasilkan `200`, `206`, dan `416` dengan header ukuran/range yang benar.
- Menggunakan ETag berbasis SHA-256 ciphertext yang sama dengan CMS.
- `If-Range` tidak cocok menghasilkan respons penuh, bukan menggabungkan revision berbeda.
- Multi-range diabaikan dan dilayani sebagai respons penuh; tidak membuat multipart response.
- Terapkan backpressure: pembacaan SFTP mengikuti kemampuan Player menerima data.
- Tutup stream dan koneksi SFTP saat Player terputus atau transfer gagal.
- Tidak ada batas durasi total yang memutus unduhan besar; gunakan timeout koneksi dan timeout tidak ada aktivitas.
- Slot penuh menghasilkan `503` dengan `Retry-After: 30`; tidak menahan antrean HTTP tanpa batas.

Gunakan satu koneksi SFTP per transfer aktif pada tahap awal. Batasi koneksi sebelum membuka SFTP.

### B. Izin unduhan dan kredensial

Pertahankan URL unduhan pada manifest Player. Endpoint CMS yang lama menjadi pemeriksa izin dan penerbit redirect untuk profil SFTP dalam mode gateway.

Tiket:

- Ditandatangani menggunakan HMAC-SHA-256 dengan secret baru khusus tiket.
- Masa berlaku awal **5 menit untuk memulai request**.
- Memuat versi tiket, perangkat, identitas asset/revision, ukuran, SHA-256, waktu penerbitan/kedaluwarsa, dan ID tiket acak.
- Tidak memuat password SFTP, master key, atau path server yang diberikan Player.
- Tiket yang kedaluwarsa tidak menghentikan transfer yang sudah berjalan. Request lanjutan harus memperoleh tiket baru dari CMS.

Tambahkan endpoint internal `POST /internal/media/resolve`:

- Hanya tersedia pada listener loopback melalui konfigurasi web server; ditolak pada virtual host publik.
- Membutuhkan secret autentikasi layanan yang berbeda dari secret tiket.
- Memvalidasi tiket dan memeriksa kembali perangkat, assignment, status asset, serta revision.
- Mengembalikan descriptor file dan kredensial SFTP dari storage profile CMS kepada gateway melalui koneksi lokal.
- Respons tidak boleh dicache atau dicatat beserta kredensialnya.

Gateway hanya mengakses host/root dari konfigurasi CMS yang dipercaya. Validasi path tetap berada di root storage dan verifikasi fingerprint SSH wajib.

URL bertiket adalah **bearer URL sementara**, bukan bukti perangkat yang tidak dapat dibagikan. Lisensi dekripsi tetap mengikuti mekanisme perangkat yang sudah ada. Pencabutan assignment menolak request baru, tetapi tidak menjanjikan penghentian transfer aktif atau playback offline seketika.

Jangan mencatat query tiket, Authorization, atau kredensial di access log maupun error log.

### C. Reverse proxy HTTPS

Pasang reverse proxy menuju port 3002 dengan:

- `proxy_buffering off`
- `proxy_cache off`
- `proxy_max_temp_file_size 0`
- Kompresi respons LDG dinonaktifkan.
- Range, If-Range, Content-Length, Content-Range, dan ETag diteruskan.
- Timeout berbasis aktivitas, bukan durasi seluruh film.
- Tidak ada CDN/cache perantara pada tahap awal.

Ini penting karena buffering reverse proxy dapat menulis respons besar ke file sementara meskipun aplikasi Node tidak membuat cache. Lihat [dokumentasi Nginx](https://nginx.org/en/docs/http/ngx_http_proxy_module.html#proxy_buffering).

### D. Cache, workspace, dan operasi asset

Pisahkan kebijakan penyimpanan berdasarkan **jenis objek yang diberikan pemanggil**, bukan hanya ekstensi:

- Film SFTP: tidak melakukan `seedCache` setelah upload dan tidak menggunakan `materialize` untuk unduhan.
- Poster: tetap memakai cache, dengan batas agregat default **1 GiB** dan TTL **24 jam**.
- Berkas poster yang melebihi anggaran cache tidak boleh membuat batas terlampaui.
- Profil Local dan FTPS mempertahankan perilaku sebelumnya; label UI harus menjelaskan bahwa mode gateway tanpa cache film berlaku untuk SFTP.

Perbaiki seluruh jalur upload, termasuk Add Asset lama dan replacement revision, bukan hanya resumable finalizer.

Progress tahap penyimpanan menghitung byte upload SFTP saja; tidak lagi menambahkan pekerjaan salin-cache. ETA memakai laju tahap aktif.

**Penghapusan asset juga harus diubah:** kode saat ini dapat mengambil file remote ke disk lokal sebelum menghapusnya.

- Untuk objek SFTP, catat pekerjaan penghapusan remote dalam transaksi yang sama dengan penghapusan katalog.
- Worker melakukan penghapusan secara idempoten dan mencoba ulang ketika SFTP gagal.
- Simpan identitas profile, key, status percobaan, dan error; jangan simpan kredensial di antrean.
- Blokir penghapusan storage profile selama masih ada pekerjaan tertunda.
- File remote tidak diunduh sebagai cadangan penghapusan.

### E. Kapasitas upload

Untuk SFTP tanpa cache film, preflight menghitung:

- Sisa plaintext yang belum diterima.
- Perkiraan ukuran LDG sementara, termasuk overhead format.
- Cadangan disk minimal **2 GiB**.
- Kebutuhan yang belum teralokasi dari session aktif lain.

Tambahkan pencatatan reservasi kapasitas per session dengan pemeriksaan atomik agar upload bersamaan tidak sama-sama menganggap ruang kosong tersedia. Hitung hanya kebutuhan tambahan yang belum berada di disk; lepaskan reservasi setelah selesai, dibatalkan, atau kedaluwarsa.

Contoh film **100 GiB** membutuhkan kira-kira **202 GiB ruang kosong pada awal upload**, ditambah overhead kecil LDG dan kebutuhan pekerjaan lain—bukan sekitar 302 GiB karena cache film tidak dibuat lagi.

Workspace tetap bersifat sementara; menghapus cache tidak menghilangkan kebutuhan staging plaintext dan LDG.

### F. Kompatibilitas Player

Kode Player saat ini mendukung redirect, Range, dan penghilangan Authorization ketika pindah origin. Gunakan mekanisme tersebut tanpa mengubah format manifest.

Wajib dibuktikan lewat pengujian:

- Retry dimulai dari URL CMS asli untuk mendapatkan tiket baru.
- File parsial tetap dipertahankan saat gangguan koneksi.
- Bearer token CMS tidak terkirim ke domain media.
- Perubahan revision tidak menghasilkan campuran byte dua film.
- Gateway penuh tidak menyebabkan retry cepat tanpa jeda.

Jika Player terpasang gagal pada skenario tersebut, perbaikan dan distribusi build kompatibilitas menjadi syarat rollout; jangan menganggap semua build lama otomatis kompatibel.

## 4. Deployment, migrasi, dan pemulihan

1. Cadangkan database serta konfigurasi rahasia yang diperlukan.
2. Pastikan server CMS dapat terhubung ke SFTP port 22. Timeout yang sebelumnya terjadi harus diselesaikan terlebih dahulu.
3. Siapkan DNS, TLS, listener internal CMS, systemd, dan reverse proxy.
4. Jalankan migrasi untuk reservasi kapasitas dan antrean penghapusan remote.
5. Deploy kode dengan mode gateway masih nonaktif.
6. Uji satu Player dan satu asset lama melalui gateway.
7. Hentikan sementara penerimaan upload saat pergantian mode; tunggu pekerjaan aktif selesai.
8. Aktifkan mode gateway untuk SFTP dan bypass cache pada upload maupun download.
9. Pantau disk, RAM, transfer aktif, throughput, kegagalan SFTP, dan antrean penghapusan.
10. Bersihkan cache film lama setelah unduhan legacy selesai.

Pembersihan cache menyediakan **dry-run terlebih dahulu**. Hanya hapus file cache yang dapat dipetakan dengan pasti ke objek film SFTP yang tersedia; lewati file tidak dikenal atau sedang digunakan. Jangan menyentuh staging upload, asset Local, poster, atau file SFTP.

Sediakan mode legacy sebagai rollback eksplisit, bukan fallback otomatis. Mengaktifkan kembali legacy berarti cache film dapat tumbuh lagi dan harus melalui pemeriksaan kapasitas.

Saat gateway gagal, kembalikan error retryable tanpa membuat cache film. Player dengan file lokal tetap dapat memutar sesuai lisensi yang berlaku.

## 5. Pengujian dan acceptance

### Fungsional dan kompatibilitas

- Upload baru dan replacement revision: LDG tersedia di SFTP, katalog benar, staging dibersihkan setelah berhasil.
- Tidak ada `seedCache` film pada seluruh jalur upload SFTP.
- Download penuh, resume, If-Range tidak cocok, range tidak valid, dan file hilang.
- Asset LDG lama maupun baru tetap lolos SHA-256 dan playback.
- Login, pairing, assignment, lisensi, poster, Local storage, FTPS, dan side-load tidak mengalami regresi.

### Keamanan dan gangguan

- Tolak tiket palsu/kedaluwarsa, perangkat nonaktif, assignment dicabut, dan revision tidak sesuai.
- Tolak akses publik ke endpoint internal serta path di luar root.
- Tolak fingerprint SSH yang berbeda.
- Uji SFTP terputus, Player terputus, gateway restart, kapasitas transfer penuh, dan CMS tidak tersedia.
- Pastikan respons dan log tidak membocorkan rahasia.

### Kapasitas dan operasi

- Uji file kecil, file dengan chunk terakhir tidak penuh, dan transfer **100 GiB** pada lingkungan yang mencukupi.
- RAM gateway tetap terbatas, tidak tumbuh mengikuti ukuran film.
- Disk CMS dan temporary directory reverse proxy tidak bertambah sebesar film selama download.
- Uji upload bersamaan, reservasi tidak cukup, retry, cancel, expiry, dan pemulihan reservasi setelah proses mati.
- Uji penghapusan asset saat SFTP gagal: katalog dan antrean konsisten, retry tidak membutuhkan salinan lokal.
- Uji cleanup dry-run dan perlindungan file aktif.

**Kriteria selesai:** film SFTP dapat diunggah dan diunduh Player melalui HTTPS tanpa salinan cache film pada CMS, sambil mempertahankan pemeriksaan izin, integritas LDG, resume, dan playback lokal.
