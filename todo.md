- [x] Distributor role
- UI enhancement
- Vlc Config Panel
- add location and change player to studio (1 location many studio)
- media library panel (melihat semua film dari semua player, lalu masing-masing film card memiliki info tentang player mana saja yang punya)
- perubahan untuk upload asset(film)

details:

1. UI Enhancement: ingin merubah dan mengupgrade ui dan ux, pertama warna, aku ingin sedikit menambahkan sesuatu, saat ini warna base adalah putih dengan secondary merah, aku ingin menambahkan warna ketiga yaitu biru, khususnya biru gelap seperti navy, lalu memperbaiki atau memosisikan ui-ui menjadi lebih proporsional, contoh, sekarang saat user memencet tombol jump to di player, menambahkan ui jump to bar, lebih menjadi pop up.

2. add location and change player to studio: jadi akan ada variable baru yaitu location, seperti contoh bogor, di bogor bisa terdapat banyak studio, nah studio ini adalah player yang dimaksud, bayangkan seperti cabang XXI di bogor, seperti itu, location dapat dibuat di cms, dengan panel baru di navigator, jika ada location yang telah dibuat, maka kolom lokasi di panel player saat pembuatan player (yang nantinya akan diubah menjadi studio) akan berubah menjadi dropdown berisi lokasi yang telah dibuat, lalu di panel location, dapat dibuat kartu untuk masing-masing lokasi, dengan berisi data-data dari lokasi, seperti list studio-studio dengan status studio, apakah offline,online, active, error, semacam itu.

3. media library panel di cms: akan berisikan database asset(film) dengan design kartu yang berisikan data-data penting yang bisa dilihat di dedfault media library panel seperti, genre, expired date, scheduled count, released date, dll, lalu terdapat tombol details, di laman details akan tertera semua informasi dengan tambahan, available di studio apa saja

4. perubahan untuk upload asset(film): aku ingin saat upload film genre merupakan dropdown, dan bisa memilih lebih dari satu, dan dapat dibuat genre baru untuk dropdown tapi hanya admin yang punya hak akses untuk itu, lalu aku ingin menambahkan 'type', terdiri dari 'featured', 'ads', 'trailer'. implementasikan type untuk schedule dan media library panel juga, untuk scheduling, admin bisa mencari film berdasarkan type juga
