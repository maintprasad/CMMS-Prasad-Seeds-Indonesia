# Revision Notes

## 2026-10-18

**Version:** 0.1

**Penyebab revisi:**
Super app CMMS yang lama (`cmms/` v0.7: PHP + SQLite/Turso + Flutter) sudah tidak ada di disk; yang tersisa hanya empat aplikasi sumber (`App_Old_WO-main`, `PM`, `dailymonitoring`, `maintware`). Aplikasi dibangun ulang dari nol dengan keputusan baru: frontend web modular (tanpa Flutter), SQL yang tumbuh bersama modul, menu lama dipertahankan tetapi dibuat bertingkat.

**Metode revisi:**
- Standar MVP: `Database <— Backend (PHP, api/index.php?r=…) —> Frontend (HTML + ES modules, tanpa build)`.
- Database: SQLite untuk XAMPP, driver Turso untuk Vercel (driver disalin dari Daily Monitoring). Skema dikelola migrasi bertahap `api/migrations/NNN_*.sql` (`api/_lib/Migrator.php`); modul berikutnya cukup menambah file migrasi. Kolom `equipment.attrs` (JSON) menampung atribut baru tanpa ubah tabel.
- Equipment Database diimpor dari MySQL XAMPP `equipment_db` (`scripts/import-equipment.php`, ID asli dipertahankan, idempoten). Nama unit: Unit - 1 → Pasuruan 1 (P1), Unit - 2 → Pasuruan 2 (P2), Unit Malang → Malang (MLG).
- Work Order: Open → In Progress (timer berjalan) ⇄ Hold/Continue → Pending Verification (timer berhenti) → Closed. Durasi = jumlah sesi kerja (`wo_sessions`); Hold wajib catatan pekerjaan hari itu dan otomatis masuk Notes. Tab detail: Detail, Parts Used (baca `mw_items` MaintWare, filter unit), Notes, Tasklist (Leader/SPV), Equipment History (apa/kapan/di mana/siapa/bagaimana/kenapa, opsional), Timeline (+ MTTR/MTBF).
- MTTR = rata-rata durasi kerja WO Troubleshooting/Corrective yang selesai; MTBF = rentang antar kerusakan ÷ (jumlah kerusakan − 1).
- Role: admin, spv (Leader/SPV), maintenance (teknisi), user (pemohon; hanya melihat WO sendiri).
- Menu bertingkat: Work Order, Daily Monitoring, Preventive Maintenance, Equipment Database, MaintWare. Semua menu asli tiap aplikasi dipertahankan; menu DM/PM/MaintWare yang modulnya belum dibangun tampil dengan label “segera” dan halaman penjelasan.
- Tes: `scripts/test-e2e.php` (data acak, lewat HTTP ke `php -S`, database SQLite sementara) dan uji UI di Chrome headless (alur user → teknisi → user → leader di desktop dan layar ponsel).

**Hasil revisi:**
- Equipment Database terisi: 3 unit, 37 area, 1309 equipment, 431 sparepart.
- Tes end-to-end API: 56 pemeriksaan lulus, 0 gagal (alur penuh WO, pembatasan role, timer Hold/Continue, MTTR/MTBF, in/out, rating, Equipment History).
- Uji UI browser: seluruh halaman WO + Equipment termuat tanpa error konsol; alur buat WO → Start → Hold → Continue → part → catatan → Selesai → Verifikasi + rating → Equipment History berhasil.
- Belum dikerjakan: modul Daily Monitoring, Preventive Maintenance, dan MaintWare (halaman); impor data MaintWare asli (`mw_items` saat ini hanya data contoh dari `scripts/seed-demo.php`); impor riwayat WO lama; foto/lampiran WO; notifikasi WA; pengaturan notifikasi; uji driver Turso (butuh database Turso kosong khusus uji lewat `--env=.env.turso-test`; jangan pakai kredensial Daily Monitoring produksi).

**Nomor versi terbaru:** 0.1

## 2026-10-18 (2)

**Version:** 0.2

**Penyebab revisi:**
Modul MaintWare belum ada (menu hanya placeholder) padahal Work Order membaca stoknya untuk "Parts Used". Data asli MaintWare ada di Google Sheets (Apps Script), bukan di MySQL `maintware` (kosong).

**Metode revisi:**
- Migrasi `002_maintware.sql`: `mw_items` dilengkapi (brand, type, class, moving, PIC), tabel baru `mw_transactions` (IN/OUT, terhubung ke Equipment DB lewat unit/area/equipment dan ke WO lewat `wo_id`), `mw_ro` + `mw_ro_items` (Purchase Request).
- API `api/modules/MaintWare.php` + halaman `js/modules/maintware/` untuk semua menu asli: Dashboard, All Items, Stock Log, Monthly Report, Reorder List, Purchase Request, Config.
- Integrasi: part yang dipakai di Work Order kini otomatis membuat transaksi OUT (stok berkurang, tercatat per equipment & WO); menghapus part mengembalikan stok; OUT melebihi stok ditolak.
- Impor `scripts/import-maintware.php` (snapshot GET getItems/getStockLog/getRO dari Apps Script ke `database/snapshot/`, opsi `--url=` untuk mengunduh ulang, idempoten). Stok = nilai ITEMS_DB. Log Sheets sebagian bergeser kolom → dipulihkan (lihat komentar skrip): 90 baris normal, 132 baris `SHEETS-R1`, 202 baris `SHEETS-R2` (PIC & catatan asli tidak ada di Sheets), 0 baris dibuang; 5 kode ada di log tetapi tidak di ITEMS_DB → dibuat item stub.
- Role: modul MaintWare untuk admin/SPV/maintenance; status Purchase Request hanya SPV/admin; hapus item hanya admin.

**Hasil revisi:**
- Data masuk: 338 item (+5 stub), 424 transaksi, 8 RO (11 baris item). Stok dan harga identik dengan ITEMS_DB.
- Tes end-to-end API: 84 pemeriksaan lulus, 0 gagal (termasuk potong stok WO, pengembalian stok, kode item per prefix, reorder, RO).
- Uji UI di Chrome (Apache XAMPP): tujuh halaman termuat tanpa error; tambah item, Stock OUT ke equipment, Reorder → buat RO, tampilan ponsel. Bug ditemukan & diperbaiki saat uji: dropdown Area/Equipment di dialog Stock kosong (replaceChildren menerima array).
- Belum dikerjakan: ekspor PPT/XLSX Monthly Report (tersedia CSV + cetak/PDF), min stok inline di tabel, foto/lampiran, notifikasi stok rendah. Catatan: `min_stock` di Sheets mayoritas 0 (aturan lama: bila kosong dianggap 5 di browser), jadi "Low stock" hanya menampilkan item yang min stoknya memang diisi.

**Nomor versi terbaru:** 0.2

## 2026-10-19

**Version:** 0.3

**Penyebab revisi:**
Foto dan lampiran Work Order (ada di aplikasi WO lama: foto Before/After/Verification + dokumen) belum dibangun.

**Metode revisi:**
- Migrasi `003_wo_files.sql`: tabel `wo_files`. File disimpan di database sebagai base64 (bukan disk) agar identik di XAMPP/SQLite dan Vercel/Turso yang tidak punya disk permanen.
- API: `POST wo/file` (unggah), `GET wo/file` (unduh/tampil, wajib login), `POST wo/file/delete`; daftar file ikut di `wo/get` tanpa isinya.
- Tab baru "Foto & Lampiran" di detail WO: Foto Before, Foto After, Foto Verifikasi, Lampiran. Hak unggah: Before & lampiran = pemohon/teknisi/SPV/admin; After = teknisi/SPV/admin; Verifikasi = pemohon/SPV/admin. Hapus = pengunggah atau SPV/admin. WO Closed/Cancelled terkunci. Maks 40 file/WO.
- Keamanan: jenis file ditentukan dari isi (finfo), bukan nama/mime kiriman; foto hanya JPG/PNG/WebP ≤ 2 MB; lampiran gambar/PDF/Word/Excel/TXT/CSV ≤ 3 MB (batas body Vercel ±4,5 MB); nama file disanitasi; unduhan memakai `nosniff`.
- Foto dikompres di browser (maks 1600 px, JPEG 80%) sebelum diunggah.

**Hasil revisi:**
- Tes end-to-end API: 98 pemeriksaan lulus, 0 gagal (termasuk file palsu bertameng .png, .exe bertameng .pdf, foto > 2 MB, hak unggah per role, unduh tanpa login, WO Closed terkunci).
- Uji UI di Chrome: unggah foto 3200×2400 PNG → tersimpan 1600×1200 JPEG ±99 KB; thumbnail, lightbox, unduh PDF, hapus lampiran, file > 3 MB ditolak dengan pesan; tampil di ponsel; tanpa error konsol.
- Belum dikerjakan: kamera langsung (saat ini memakai pemilih file ponsel, yang sudah menawarkan kamera), foto di Equipment History, pembersihan file saat WO dihapus belum relevan karena WO tidak bisa dihapus.

**Nomor versi terbaru:** 0.3

## 2026-10-19 (2)

**Version:** 0.4

**Penyebab revisi:**
Modul Daily Monitoring belum dibangun. Permintaan: semua data DM bertumpu pada Equipment Database; pengaturan parameter (yang di aplikasi lama ada di menu Hierarki dan Konfigurasi) dipindah ke Equipment Database sehingga kedua menu itu tidak ada lagi di Daily Monitoring.

**Metode revisi:**
- Migrasi `004_monitoring.sql`: `equipment.monitor_type`, `equipment_params` (parameter melekat di equipment), `dm_monitor_types` + `dm_param_templates` (pengaturan dasar parameter per tipe), `dm_sessions` + `dm_items` (sesi menunjuk `equipment_id`; label/satuan/seksi disalin saat simpan supaya riwayat utuh walau parameter diubah).
- Equipment Database: kartu "Parameter Monitoring" di detail equipment (tambah/ubah/hapus/urut, terapkan template, salin ke equipment lain), kolom tipe monitoring di form equipment, menu baru "Template Parameter" (tipe + parameter dasar, terapkan ke semua equipment sebuah tipe).
- Daily Monitoring (menu: Dashboard, Monitor, Riwayat, Finding / Alarm — Hierarki & Konfigurasi dihapus): papan Monitor per unit/area (status & umur pembacaan terakhir), form entry dengan pratinjau status langsung, PIC dari daftar teknisi, input angka strict ("28,7" ok; "1.234,5", "11.20.26" ditolak), status dihitung ulang di server (angka: di luar normal = WARNING, di luar batas warn = ALERT; pilihan: opsi pertama OK, terakhir ALERT, tengah WARNING), edit sesi (pembuat/SPV/Admin), hapus (SPV/Admin).
- Finding aktif sampai ada pembacaan lebih baru untuk parameter yang sama atau WO-nya Closed/Cancelled. "Buat WO" dari temuan → Work Order (source `dm`, temuan jadi Tasklist, prioritas High bila ada ALERT).
- Impor `scripts/import-dm.php` dari `dailymonitoring/database/dailymonitoring.db` (idempoten): hierarki dipetakan ke Equipment DB lewat nama, equipment yang belum ada dibuat (atribut `dm_id` menyimpan ID lama), 9 template tipe dari `database/snapshot/dm_templates.json`.
- Sesuai keputusan v0.6 lama: RCA, Perbaikan, dan WO Monitoring tidak dibangun (digantikan alur Work Order). Manajemen user sudah di Admin Panel; notifikasi WhatsApp belum.

**Hasil revisi:**
- Data masuk: 79 equipment (45 cocok dengan Equipment DB, 34 dibuat baru di 3 area baru), 738 parameter, 465 sesi, 637 nilai, 13 PIC → teknisi, 9 tipe dengan 180 baris template. Impor ulang tidak menggandakan data.
- Tes end-to-end API: 140 pemeriksaan lulus, 0 gagal (parameter, template, validasi angka/pilihan, status, finding aktif/resolved, WO dari temuan, edit/hapus per role, dashboard).
- Uji UI di Chrome: semua halaman termuat tanpa error; entry (format salah ditolak di browser dan server → simpan → "Ada temuan" → Buat WO → Tasklist berisi temuan); menambah parameter dari halaman equipment; tampilan ponsel.
- Belum dikerjakan: notifikasi WhatsApp temuan, ekspor laporan bulanan/PPT DM, grafik tren per parameter, jadwal/target frekuensi monitoring per equipment (dashboard memakai batas tetap 7 hari), akun user DM lama (role manager/leader/crew) tidak diimpor.

**Nomor versi terbaru:** 0.4

## 2026-10-20

**Version:** 0.5

**Penyebab revisi:**
Pengelolaan user dan teknisi tersebar (Admin Panel WO punya tabel user, menu Teknisi WO punya tombol tambah/ubah). Diminta satu tempat: menu "Setting" untuk semua user, teknisi, dan akun masing-masing user.

**Metode revisi:**
- Menu baru **Setting** (paling bawah di sidebar): Akun Saya (semua role), User (Admin), Teknisi (Admin/SPV).
- User: tambah/ubah (role, unit, departemen, jabatan, WhatsApp), reset password, nonaktifkan; akun tidak dihapus agar riwayat tetap utuh. Menonaktifkan akun atau mengganti passwordnya otomatis mencabut semua sesi loginnya. Pengaman: akun sendiri tidak bisa dinonaktifkan, minimal satu Admin aktif.
- Teknisi: daftar teknisi/PIC dengan tautan opsional ke akun (satu akun satu teknisi), hapus hanya bila belum tercatat di WO. Teknisi tanpa akun tetap bisa jadi PIC monitoring dan di-assign ke WO.
- Akun Saya: ubah profil (nama, departemen, jabatan, WhatsApp), ganti password, keluar, keluar dari semua perangkat. Tombol nama di pojok kanan atas kini membuka halaman ini.
- Dihapus dari modul lain: tabel/form user di Work Order → Admin Panel (kini hanya pintasan) dan tombol tambah/ubah teknisi di Work Order → Teknisi (kini hanya laporan kinerja + tautan ke Setting). Menu Hierarki/Konfigurasi Daily Monitoring sudah dihapus di v0.4; hierarki unit/area/equipment tetap satu-satunya di Equipment Database.

**Hasil revisi:**
- Tes end-to-end API: 156 pemeriksaan lulus, 0 gagal (pengaman admin, token dicabut, reset password, profil, keluar semua perangkat, tautan teknisi-akun, hak akses per role).
- Uji UI di Chrome: menu Setting per role (Admin 3 item, SPV 2, User 1), halaman User/Teknisi/Akun Saya, profil tersimpan, Admin Panel & Teknisi WO tanpa tombol kelola, ponsel.
- Belum dikerjakan: hapus permanen akun, log aktivitas login, pembatasan akses unit per user (kolom unit hanya informasi), pemulihan password mandiri (lupa password: lewat Admin).

**Nomor versi terbaru:** 0.5

## 2026-10-20 (2)

**Version:** 0.5.1

**Penyebab revisi:**
Permintaan: Equipment Database dijadikan submenu dari menu Setting.

**Metode revisi:**
- Grup sidebar "Equipment Database" dihapus; isinya (Daftar Equipment, Unit & Area, Template Parameter) kini submenu lipat di Setting → Equipment Database. Sidebar mendukung submenu bertingkat (`children` di `js/core/menu.js`); alamat halaman (`#/eq/...`) tidak berubah sehingga semua tautan tetap bekerja. Submenu terbuka otomatis saat berada di salah satu halamannya.
- Hak akses tidak berubah: semua role melihat Daftar Equipment dan Unit & Area, Template Parameter hanya Admin/SPV/Maintenance.

**Hasil revisi:**
- Uji UI di Chrome: Admin melihat 3 halaman di submenu, user biasa 2; menu aktif tersorot benar; submenu bisa dilipat; tampilan ponsel. Tes API tidak terpengaruh (156 lulus).

**Nomor versi terbaru:** 0.5.1

## 2026-10-20 (3)

**Version:** 0.5.2

**Penyebab revisi:**
Masih ada pengaturan/menu teknisi di Work Order (menu "Teknisi"). Diminta teknisi hanya di Setting.

**Metode revisi:**
- Menu Work Order → Teknisi dan halamannya dihapus; kartu pintasan User/Teknisi di WO Admin Panel dihapus.
- Setting → Teknisi memuat ringkasan kinerja (jumlah WO, Closed, Berjalan, jam kerja) yang tadinya di halaman WO; API `GET technicians` menyertakan angka-angkanya.
- Dipertahankan: "Rating Teknisi" di Work Order (laporan penilaian, bukan pengaturan), dan pemilihan teknisi saat Assign di detail WO (memakai daftar dari Setting).

**Hasil revisi:**
- Tes API 156 lulus; uji UI: menu WO tanpa Teknisi, Setting → Teknisi menampilkan kinerja.

**Nomor versi terbaru:** 0.5.2

## 2026-10-20 (4)

**Version:** 0.6

**Penyebab revisi:**
Detail equipment perlu terhubung dengan MaintWare dan menampilkan: (1) Equipment History dari Work Order dan Preventive Maintenance yang bisa ditelusuri, (2) sparepart yang dipakai beserta jumlahnya, (3) penanggung jawab mesin dari Produksi dan Maintenance (wajib).

**Metode revisi:**
- Migrasi `005_equipment_detail.sql`: `equipment.pj_production_id` / `pj_maintenance_id` (ke tabel users) dan `equipment_spareparts.item_id` (tautan ke `mw_items`).
- Detail equipment kini bertab: **Ringkasan** (data, penanggung jawab, keandalan), **Equipment History**, **Sparepart**, **Parameter Monitoring**.
- Equipment History: semua Work Order equipment itu (terbaru dulu) dengan rincian bisa dibuka — masalah, penyelesaian, isian Equipment History SPV (apa/kenapa/bagaimana/siapa/di mana), teknisi, sparepart yang dipakai, tautan ke WO, dan asal Daily Monitoring bila ada. Filter Work Order / Preventive Maintenance; entri PM masuk ke daftar yang sama begitu modul PM ada (`kind = 'PM'`, API `pm_ready`).
- Sparepart: (a) daftar standar mesin yang dapat ditautkan ke item MaintWare (pencarian item, stok & LOW tampil di sini) dan (b) pemakaian nyata — ringkasan per item (total, kali, terakhir, stok kini), part bebas dari WO yang tidak ada di MaintWare, dan rincian transaksi tertaut ke WO. Pembatalan part di WO mengurangi total pemakaian.
- Sisi MaintWare: detail item menampilkan "Dipakai di equipment" (standar + terpakai).
- Penanggung jawab wajib saat tambah/ubah equipment: PJ Produksi = akun role User, PJ Maintenance = akun role Maintenance/SPV (divalidasi server). Daftar equipment menampilkan kedua PJ, filter "PJ belum lengkap", dan "Set PJ massal" (Admin/SPV) untuk hasil filter. PJ juga tampil di detail WO.
- Tautan sparepart ↔ MaintWare sengaja tidak diisi otomatis: nama sparepart lama sangat generik ("Bearing" ×28, "Motor" ×75) sehingga pencocokan nama menebak item yang salah.

**Hasil revisi:**
- Tes API: 176 pemeriksaan lulus, 0 gagal (validasi PJ, PJ massal, history, pemakaian sparepart, tautan MaintWare, sisi MaintWare).
- Uji UI di Chrome: PJ massal, edit tanpa PJ ditolak, tab History (rincian, filter PM), tab Sparepart (tautan, pemakaian, part bebas), detail item MaintWare, ponsel.
- Data dev: seluruh 1343 equipment masih belum punya PJ — harus diisi (Daftar Equipment → "Isi massal" per unit/area, atau per equipment). Sampai terisi, mengedit equipment apa pun akan meminta PJ.
- Belum dikerjakan: entri Preventive Maintenance (menunggu modul PM), notifikasi ke PJ saat WO/temuan DM dibuat, foto WO di history.

**Nomor versi terbaru:** 0.6

## 2026-10-20 (5)

**Version:** 0.7

**Penyebab revisi:**
Diminta menerapkan template website di folder `bosh` (tema "Baosh") ke tampilan CMMS.

**Metode revisi:**
- Template diperiksa (`bosh/baosh pack/baosh`): template PHP statis bertema hitam/putih editorial. Catatan: CSS-nya tidak mendefinisikan variabel `--thm-base`, `--thm-font`, `--thm-font-2`, dan gambarnya placeholder, sehingga bila dibuka apa adanya tampil dengan font bawaan browser. Bahasa desain diambil dari aturan CSS-nya: latar #000, teks #d0c9c9, judul putih, panel #18171c, garis tipis rgba(255,255,255,.08–.2), font DM Sans + Lora (yang memang dimuat template), sudut siku, tombol HURUF BESAR ber-letter-spacing 0.2em, tagline huruf kecil + garis 60px (`.sec-title__tagline`), input transparan-putih 6% dengan border 20%.
- File baru `css/theme.css` (dimuat setelah `app.css`) menimpa token, bentuk, dan komponen: header/sidebar hitam bergaris tipis, judul Lora, kartu tanpa bayangan, tabel hanya bergaris tipis, tombol utama putih solid, badge status tint transparan (warna semantik OK/WARNING/ALERT dipertahankan agar tetap bermakna), tab huruf besar, modal & form gelap, ikon emoji dibuat monokrom.
- Login dibuat dua kolom seperti hero template; tiap halaman mendapat tagline kecil modul ("work order ——") di atas judul. Font diganti ke DM Sans + Lora; grafik memakai seri abu/putih (kecuali IN/OUT stok yang tetap hijau/merah).
- Tidak ada aset/logo "Baosh" yang disalin (itu merek template); wordmark "CMMS" memakai Lora. Folder `bosh` tidak diubah. Mode terang dihapus: tema template hanya gelap. Cetak (print) tetap putih.

**Hasil revisi:**
- Diuji di Chrome: login, WO, dashboard, MaintWare, Daily Monitoring (papan & form), Equipment (daftar & detail), ponsel & menu geser — tanpa error konsol. Tes API tidak terpengaruh (176 lulus).
- Catatan: tabel lebar (mis. All Items) kini bergulir horizontal pada layar < ±1400 px karena padding lebih lega.

**Nomor versi terbaru:** 0.7

## 2026-10-20 (6)

**Version:** 0.8

**Penyebab revisi:**
Tema Baosh (v0.7) dinilai monoton. Diminta mengganti dengan template di folder `Arclon`.

**Metode revisi:**
- `Arclon` = template admin Bootstrap 5 (Coderthemes) berlayar sidebar + topbar mengambang, kartu transparan di atas latar luar angkasa, aksen biru/hijau/kuning/merah, mode gelap & terang. CSS/ikon template disalin apa adanya ke `assets/arclon/` (vendor/app/icons .min.css, font ikon Tabler & Remix woff2, 2 gambar latar); logo "Arclon" tidak dipakai (merek template) — wordmark CMMS memakai ikon Tabler. Folder `Arclon` tidak diubah.
- Shell ditulis ulang mengikuti markup template: `sidenav-menu` + `side-nav` (judul "Modul"/"Pengaturan", induk bisa dilipat dengan panah, submenu bertingkat untuk Setting → Equipment Database), `app-topbar` (tombol ringkas/lebar sidebar, judul halaman, tombol tema gelap/terang yang diingat, dropdown user), `page-content` + footer. Layar ≤ 1140 px memakai sidebar geser dengan backdrop. JS bawaan template (jQuery/Bootstrap) tidak dimuat — perilaku yang dibutuhkan ditulis sendiri sehingga cocok dengan SPA.
- Ikon emoji menu diganti ikon Tabler template. Halaman login memakai tata letak halaman auth template (kartu di tengah latar planet).
- `css/cmms.css` (menggantikan `app.css` dan `theme.css` Baosh) memetakan komponen CMMS ke variabel `--ct-*` template (kartu, tombol, input, tabel, badge, tab, dialog, statistik, tile monitoring), sehingga otomatis ikut mode gelap/terang. Kelas yang bentrok dengan Bootstrap diganti: dialog `.modal` → `.dlg`, `.toast` → `.msg-toast`, `.timeline` → `.tl`.
- Grafik batang memakai biru/hijau template; IN/OUT stok tetap hijau/merah.

**Hasil revisi:**
- Diuji di Chrome: login, WO (alur lengkap buat→teknisi→verifikasi), MaintWare (item, stock OUT, reorder→RO), Daily Monitoring (papan, form), Equipment, Setting, mode terang, ponsel + menu geser — tanpa error konsol. Tes API tidak terpengaruh (176 lulus). Data uji dibersihkan (stok MaintWare dikembalikan lewat impor ulang).
- Font Atkinson Hyperlegible dimuat template dari Google Fonts (perlu internet pertama kali).
- Belum: kolom pencarian global di topbar, notifikasi di topbar, halaman profil bergaya template.

**Nomor versi terbaru:** 0.8

## 2026-10-20 (7)

**Version:** 0.9

**Penyebab revisi:**
Tampilan Arclon dinilai kurang animatif dan kurang responsif.

**Metode revisi:**
- Animasi (CSS, `css/cmms.css` + `js/core/ui.js`): isi halaman masuk berurutan (naik + memudar), kartu/stat/tile muncul bertahap, baris tabel masuk berurutan, hover mengangkat kartu & tile dengan cahaya aksen, tombol menekan saat diklik, grafik batang tumbuh dari bawah, bar persentase memanjang, angka statistik menghitung naik dari 0 (`countUp`), badge ALERT berdenyut, tombol tema berputar, topbar bergaris bayangan saat digulir, ganti tema gelap/terang memudar halus.
- Menu samping membuka/menutup halus (tinggi dianimasikan; menu tidak digambar ulang saat dilipat); dialog muncul dengan skala + latar blur dan keluar dengan animasi; toast meluncur masuk/keluar; kerangka pemuatan (skeleton shimmer) menggantikan teks "Memuat…". Semua animasi mati otomatis bila perangkat memilih `prefers-reduced-motion`.
- Responsif: tabel berubah jadi kartu bertingkat sampai 900 px (label di kiri, nilai di kanan); dialog menjadi bottom-sheet di layar ≤ 768 px; toolbar & judul halaman menyusun ulang; area sentuh ≥ 44 px di perangkat layar sentuh; toast selebar layar di ponsel; padding & ukuran angka statistik menyesuaikan di ≤ 480 px.

**Hasil revisi:**
- Audit otomatis di Chrome: 28 halaman × 5 ukuran layar (360, 768, 1024, 1366, 1920 px) — tidak ada overflow horizontal. (Satu halaman uji menampilkan pesan "equipment belum punya parameter" yang memang diharapkan.) Tes API tidak terpengaruh.
- Belum: pencarian global & notifikasi di topbar, animasi transisi antar-halaman yang menimpa (halaman lama langsung diganti).

**Nomor versi terbaru:** 0.9

## 2026-10-20 (8)

**Version:** 1.0

**Penyebab revisi:**
Modul Preventive Maintenance (menu lama di app `PM/`) belum dibangun — satu-satunya modul yang masih placeholder.

**Metode revisi:**
- Migrasi `006_pm.sql`: `equipment.pm_type`, `pm_types` + `pm_template_items` (checklist per tipe), `pm_schedules`, `pm_takeovers`, `pm_reports` + `pm_report_items` + `pm_report_parts` + `pm_signatures`, dan `mw_transactions.pm_id`. Jadwal & laporan menunjuk `equipment_id` di Equipment Database.
- API `api/modules/Preventive.php` + halaman `js/modules/preventive/` untuk seluruh menu asli: **Dashboard PM, Schedule PM (daftar + kalender bulanan), Input PM, Daftar Laporan, Resume Laporan, Outstanding PM, Takeover PM, Kelola Template PM**.
- Status jadwal dihitung dari PM terakhir: jatuh tempo = PM terakhir + periode (atau tanggal acuan bulan/minggu bila belum pernah); `done` selama belum mendekati jatuh tempo berikutnya, `overdue` bila lewat > 6 hari, selain itu `upcoming`. Aturan app lama ("sudah ada laporan apa pun = selesai") tidak dipakai karena membuat jadwal tahunan tak pernah kembali outstanding.
- Form laporan: checklist dari template tipe PM equipment (Ya/Tidak + keterangan, bisa tambah butir), PPE, teknisi dari daftar Setting → Teknisi, persetujuan Leader/SPV, sparepart yang dipilih dari MaintWare otomatis memotong stok (OUT bertanda PM, tertaut ke laporan & equipment; edit/hapus laporan mengoreksi stok). Tanda tangan Leader & Supervisor digambar di canvas (PNG), tersimpan per laporan. Cetak/PDF lewat jendela cetak browser. Butir "Tidak" dapat dijadikan Work Order (source `pm`, Tasklist = butir bermasalah). Ekspor CSV di Daftar Laporan.
- Tipe PM diatur di Equipment Database (Edit equipment → Tipe PM). Detail equipment menampilkan jadwal PM + tombol Eksekusi, dan Equipment History kini memuat entri PM (checklist, sparepart, tautan laporan & WO). Pemakaian sparepart dari PM ikut tercatat di "Sparepart yang pernah dipakai".
- Impor `scripts/import-pm.php` dari cadangan MySQL `pm_backup` (idempoten): 203 jadwal (ID lama), 233 laporan (1977 butir checklist, 42 sparepart), 35 tipe PM (14 dengan template bawaan app lama/kustom, sisanya tipe tanpa template), 137 equipment baru dibuat di Equipment DB, 153 cocok dengan yang ada.

**Hasil revisi:**
- Tes API: 217 pemeriksaan lulus, 0 gagal (jadwal, status, takeover, form, validasi, stok MaintWare, edit/hapus laporan, tanda tangan, WO dari temuan, dashboard, resume, kalender, history & sparepart equipment).
- Uji UI di Chrome: semua halaman PM, eksekusi PM → laporan → rincian → tanda tangan → entri di Equipment History (laporan uji dihapus & stok dikembalikan); ponsel; tanpa error konsol.
- Catatan data: cadangan `pm_backup` berisi data sampai salinan terakhir dari Turso (1 Okt 2026); laporan yang dibuat di app PM lama setelah itu tidak ikut. Seluruh jadwal lama bertipe Annual dan semua berasal dari Unit 1; laporan Unit 2 tidak punya jadwal.
- Belum: kunci antar-perangkat saat dua orang mengerjakan PM yang sama (lock app lama), ekspor PDF jadwal & Excel khusus, foto pada laporan PM, tanda tangan nama-tersimpan.

**Nomor versi terbaru:** 1.0

## 2026-10-20 (9)

**Version:** 1.1

**Penyebab revisi:**
Permintaan: pencarian global & notifikasi topbar, ekspor Excel/PowerPoint Monthly Report MaintWare, dan satu menu Report untuk menghasilkan laporan WO, MaintWare, PM, Daily Monitoring (satu, kombinasi, atau semuanya sekaligus). Impor riwayat WO lama, notifikasi WhatsApp, dan ganti akun demo ditunda atas permintaan.

**Metode revisi:**
- Menu baru **Report → Generate Report** (`js/modules/report/center.js`): pilih modul (kartu, boleh lebih dari satu / semua), periode (preset bulan ini/lalu/3 bulan/tahun ini atau tanggal bebas), unit; pratinjau per modul (KPI + tabel); unduh **Excel** (sheet Ringkasan + satu sheet per tabel, auto-filter), **PowerPoint** (cover, slide KPI per modul, slide tabel ringkas + grafik batang, slide rujukan data rinci), dan **PDF/Cetak** (dokumen A4 landscape berisi semua baris).
- API `api/modules/Report.php`: `report/build` (laporan generik kpis+tabel per modul, maks. 5.000 baris per tabel, filter unit), `search`, `notifications`. `PreventiveApi::scheduleRows()` dibuka untuk dipakai modul ini.
- Pustaka ekspor disimpan lokal di `assets/vendor/` (SheetJS 0.18.5, PptxGenJS 3.12.0), dimuat hanya saat tombol unduh dipakai — tanpa build step, tetap jalan offline.
- Monthly Report MaintWare: tombol **Excel / PowerPoint** membuka Generate Report terisi (modul MaintWare, bulan terpilih) dan langsung menyusun laporan.
- **Pencarian global** di topbar (Ctrl+K atau "/"): equipment, WO, item MaintWare, laporan PM; hasil bergrup, navigasi panah + Enter. User biasa hanya melihat equipment dan WO miliknya.
- **Notifikasi** (lonceng, diperbarui tiap 2 menit & tiap pindah halaman): WO menunggu verifikasi, WO lewat tenggat, WO Open tanpa teknisi, item di bawah stok minimum, jadwal PM terlambat, temuan PM belum jadi WO, ALERT monitoring 3 hari terakhir tanpa WO; masing-masing menaut ke halaman terkait. User biasa hanya melihat status WO miliknya.

**Hasil revisi:**
- Tes API 230 lulus, 0 gagal (13 pemeriksaan baru: 4 modul/1 modul/kombinasi, validasi, hak akses, filter unit, konsistensi KPI vs baris, pencarian, notifikasi).
- Uji UI di Chrome: lonceng & dropdown, pencarian → buka detail equipment, Generate Report 4 modul → tab per modul, unduhan .xlsx (≈98 KB) dan .pptx (≈356 KB) berhasil, alur dari Monthly Report, tampilan ponsel tanpa overflow, tanpa error konsol.
- Catatan: jumlah pada lonceng = jumlah semua butir (bukan jumlah jenis notifikasi); pengujian Turso/Vercel belum dilakukan.

**Nomor versi terbaru:** 1.1

## 2026-10-20 (10)

**Version:** 1.1.1

**Penyebab revisi:**
Pengguna sering keluar sendiri (sesi berakhir). Log Apache menunjukkan token dari Chrome pengguna ditolak sebagai tidak valid. Dugaan penyebab: token di server terhapus oleh uji UI di database dev yang menjalankan "keluar dari semua perangkat"/simpan user (menghapus semua token akun admin), dan `localStorage` di origin `localhost` dipakai bersama aplikasi lain sehingga bisa hilang.

**Metode revisi:**
- Token kini berlaku 30 hari dan diperpanjang otomatis tiap dipakai (sliding, paling sering sekali per jam).
- Token juga dicadangkan di cookie `cmms_token` (path aplikasi); bila `localStorage` kosong, token dipulihkan dari cookie.
- Respons 401 tidak langsung memutus sesi: klien memeriksa `auth/me` dulu dan mengulang permintaan bila token ternyata masih sah.

**Hasil revisi:**
- Tes API 230 lulus; uji Chrome: tetap login setelah `localStorage` dihapus, token salah tetap mengarah ke login.

**Nomor versi terbaru:** 1.1.1

## 2026-10-20 (11)

**Version:** 1.2

**Penyebab revisi:**
Permintaan: setup Turso/Vercel dan dukungan MySQL agar aplikasi bisa dipasang di server sendiri.

**Metode revisi:**
- Turso: `scripts/db-check.php`, `scripts/copy-db.php` (skema + salin data lokal, verifikasi jumlah baris), `.env.turso.example`, `.vercelignore`, region Vercel `iad1`, koneksi curl dipakai ulang (keep-alive). Database Turso `prasad-cmms` sudah diisi data lokal (7.409 baris); user demo dihapus, hanya `admin`.
- MySQL/MariaDB: `api/_lib/MysqlDriver.php` (PDO, sql_mode ANSI_QUOTES + PIPES_AS_CONCAT, terjemahan ON CONFLICT / INSERT OR IGNORE / GROUP_CONCAT / ESCAPE, DECIMAL → angka, sambung ulang otomatis), migrasi `api/migrations/mysql/` (dibuat dengan `scripts/make-mysql-ddl.php`), Migrator memilih folder sesuai driver dan menolak bila ada migrasi tanpa pasangan MySQL, `copy-db.php`/`db-check.php` mendukung MySQL, `test-e2e.php --mysql`.
- Penyesuaian SQL agar portabel: `HAVING` memakai ekspresi agregat (bukan alias), `IS <kolom>` diganti perbandingan NULL-aman.
- Perbaikan sesi: token 30 hari + sliding, cadangan cookie, 401 diverifikasi ulang; ikon pencarian topbar dirapikan.

**Hasil revisi:**
- Tes e2e 230 lulus di SQLite **dan** di MariaDB 10.4; 31 endpoint dibandingkan SQLite vs MySQL dengan data yang sama — 22 identik, 9 berbeda hanya karena urutan baris bernilai sama (tanpa tie-break) dan pengelompokan label tanpa beda huruf besar/kecil di MySQL.
- Belum diuji: MySQL 8 (bukan MariaDB), deploy Vercel dengan Turso (kode siap, env perlu diisi di Vercel), performa MySQL jarak jauh.

**Nomor versi terbaru:** 1.2

## 2026-10-20 (12)

**Version:** 1.3

**Penyebab revisi:**
Permintaan: CMMS bisa dipasang sebagai PWA dengan ikon dari gambar "Vintage Gear and Screwdriver Sticker".

**Metode revisi:**
- `manifest.webmanifest` (standalone, ikon 192/512 + maskable, shortcut Progress WO / Input PM / Monitor), `sw.js` (service worker: `/api/` tidak pernah di-cache; HTML/JS/CSS jaringan-dulu dengan cadangan cache; aset gambar/font/pustaka cache-dulu), pendaftaran SW di `js/main.js`, tombol **Pasang aplikasi** di menu pengguna (muncul saat browser menganggap aplikasi layak dipasang).
- Ikon dibuat dari gambar sumber (latar disamakan dengan warna tepi gambar #f9f9f7): `assets/icons/icon-192.png`, `icon-512.png`, `icon-maskable-512.png` (skala 74% agar aman dipotong), `apple-touch-icon.png`, `favicon-32.png`/`64`. Gambar sumber (PNG 1254×1254, bukan JPG) tidak ikut repo.
- `.htaccess` dan `vercel.json`: sw.js & manifest tanpa cache panjang, tipe `application/manifest+json`.

**Hasil revisi:**
- Uji Chrome: SW terdaftar & aktif, manifest valid (3 ikon), semua ikon terunduh, tombol "Pasang aplikasi" muncul (browser memicu `beforeinstallprompt` = memenuhi syarat PWA), tanpa error konsol.
- Belum teruji: cache offline — Cache Storage pada profil Chrome headless di komputer ini menolak semua penulisan ("Entry already exists", termasuk `put` data sepele), jadi perilaku offline perlu dicoba di Chrome biasa (matikan jaringan → buka aplikasi).

**Nomor versi terbaru:** 1.3

## 2026-10-21

**Version:** 1.4

**Penyebab revisi:**
Permintaan: setiap material / sparepart MaintWare harus punya foto, ditampilkan di detail item; item baru wajib berfoto.

**Metode revisi:**
- Migrasi `007_mw_photos` (SQLite + MySQL): tabel `mw_item_photos` (satu foto per item, base64) — terpisah dari `mw_items` agar daftar tetap ringan. Sudah terpasang di SQLite lokal, MariaDB lokal, dan Turso.
- API: `mw/item/save` menerima `photo` (base64) + `photo_mime`; **item baru wajib berfoto** (400 bila kosong); validasi JPEG/PNG/WebP lewat magic bytes, maks 600 KB; edit item boleh tanpa foto baru (foto lama dipertahankan). `GET mw/item/photo?id=` mengirim gambar (butuh login staf). Daftar item memuat `has_photo`, filter `photo=none`. Hapus item ikut menghapus fotonya.
- UI: dialog Tambah/Edit item punya kotak foto (ambil kamera / pilih galeri, dikompres di browser maks 900 px JPEG 82%, pratinjau); detail item menampilkan foto di bagian atas (klik = buka ukuran penuh); daftar item punya kolom **Foto** dan chip **Tanpa foto** untuk menelusuri item lama yang belum difoto.

**Hasil revisi:**
- Tes e2e 237 lulus di SQLite dan MariaDB (7 pemeriksaan baru: wajib foto, bukan gambar, terlalu besar, unduh, has_photo, filter, tanpa login).
- Uji UI Chrome: tanpa foto ditolak dengan pesan, pilih foto → pratinjau, simpan, detail menampilkan foto; item uji dihapus.
- Catatan: 343 item lama belum punya foto (tidak dipaksa saat edit); gunakan chip "Tanpa foto" untuk melengkapinya. Foto belum tampil di pemilih sparepart Work Order / PM / Equipment.

**Nomor versi terbaru:** 1.4

## 2026-10-21 (2)

**Version:** 1.5

**Penyebab revisi:**
Permintaan: menu laporan di keempat modul dihapus; semua fungsi laporan dipusatkan di menu Report pada sidepanel. (Sebelumnya juga: wallpaper latar diburamkan, menu Admin Panel WO dihapus, foto item MaintWare dipindah ke sisi kanan detail.)

**Metode revisi:**
- Dikeluarkan dari modul dan dipindah ke grup **Report**: WO · in / out, WO · Equip. History, WO · MTTR / MTBF, WO · Rating Teknisi, PM · Resume Laporan, MW · Monthly Report (halaman & rute tidak berubah, hanya letak menu).
- WO · Reports (daftar WO + ekspor CSV) dihapus — digantikan Generate Report (Excel/PowerPoint/PDF, memuat Daftar WO dan sparepart).
- Tetap di modulnya karena bukan laporan melainkan data kerja: Daftar Laporan PM (laporan inspeksi yang diisi/ditandatangani), Riwayat & Finding Daily Monitoring, Reorder List MaintWare.

**Hasil revisi:**
- Semua halaman yang dipindah terbuka tanpa error dan menandai menu Report sebagai aktif; menu modul kini hanya berisi fungsi kerja.

**Nomor versi terbaru:** 1.5
