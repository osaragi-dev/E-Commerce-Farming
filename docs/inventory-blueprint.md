# Blueprint inventory

Dokumen ini menjelaskan cara kerja inventory pada aplikasi Farming. Gunakan dokumen ini untuk memahami hubungan antar-modul, memeriksa aturan bisnis, dan menyusun skenario QA.

## 1. Tujuan inventory

Inventory menjawab empat pertanyaan utama:

1. Produk apa yang tersedia?
2. Berapa jumlah stoknya?
3. Di warehouse mana stok berada?
4. Transaksi apa yang mengubah jumlah stok tersebut?

Aplikasi tidak menggunakan satu angka stok untuk semua kebutuhan. Aplikasi memisahkan stok menjadi beberapa bucket agar status stok lebih jelas.

## 2. Struktur data utama

| Entitas | Fungsi |
| --- | --- |
| `Product` | Produk utama. |
| `ProductVariant` | Varian yang dijual atau disimpan, termasuk unit, harga, dan stok global. |
| `Unit` | Satuan produk, seperti pcs, kg, liter, atau box. |
| `Warehouse` | Lokasi penyimpanan stok. |
| `WarehouseStock` | Jumlah stok satu `ProductVariant` pada satu `Warehouse`. |
| `StockMutation` | Audit trail setiap perubahan stok. Data ini bersifat append-only. |
| `StockAdjustment` | Transaksi koreksi stok manual. |
| `StockMovement` | Perpindahan stok antar-warehouse. |
| `StockOpnameSession` | Sesi pemeriksaan fisik stok. |
| `ApprovalSetting` | Konfigurasi approval untuk modul inventory. |
| `ApprovalSettingLevel` | Role approver pada setiap level approval. |
| `ApprovalLog` | Riwayat approve atau reject pada transaksi. |

Relasi utamanya adalah:

```text
Product
  -> ProductVariant
       -> Unit
       -> WarehouseStock
            -> Warehouse
       -> StockMutation
       -> StockMovementItem

ApprovalSetting
  -> ApprovalSettingLevel
       -> Role
```

## 3. Bucket stok

Setiap varian memiliki tiga bucket stok utama:

| Field | Arti | Perubahan utama |
| --- | --- | --- |
| `stock_available` | Stok yang dapat dipakai untuk transaksi baru. | Stock adjustment, order, transfer, dan stock opname. |
| `stock_reserved` | Stok yang sudah dialokasikan untuk order, tetapi belum selesai. | Order dibuat, order dibatalkan, atau order selesai. |
| `stock_out` | Stok yang sudah keluar karena order selesai. | Order selesai atau fulfillment. |

Pada `WarehouseStock`, aplikasi menyimpan bucket yang sama untuk setiap kombinasi warehouse dan product variant.

Total stok pada satu warehouse adalah:

```text
total_stock = stock_available + stock_reserved + stock_out
```

`initial_stock` berbeda dari `stock_available`. `initial_stock` adalah nilai awal saat varian dibuat. `stock_available` berubah sepanjang umur varian.

## 4. Aturan dasar stok

- Hanya `stock_available` yang boleh dikoreksi secara manual.
- `stock_reserved` dan `stock_out` berubah melalui alur order.
- Setiap perubahan stok harus memiliki sumber transaksi.
- Perubahan stok harus mencatat nilai sebelum perubahan dan setelah perubahan.
- Stok tidak boleh menjadi negatif.
- Perubahan stok warehouse harus mengunci data yang sedang dihitung agar dua transaksi tidak memakai stok yang sama.
- `StockMutation` tidak boleh diedit setelah dibuat.
- Gunakan `StockMutation::record()` untuk mencatat perubahan stok.

## 5. Sumber perubahan stok

| Source | Kapan digunakan |
| --- | --- |
| `initial_stock` | Saat varian pertama kali dibuat. |
| `order` | Saat stok dialokasikan untuk order. |
| `cancel_order` | Saat reservasi order dibatalkan. |
| `manual_adjustment` | Saat admin melakukan koreksi stok. |
| `transfer` | Saat stok dipindahkan antar-warehouse. |
| `stocktake` | Saat hasil stock opname diterapkan. |
| `order_fulfilled` | Data historis dari alur fulfillment lama. |

## 6. Alur order dan stok

### 6.1 Order dibuat

Saat order dibuat, sistem memeriksa `stock_available` pada warehouse fulfillment.

Jika stok mencukupi:

```text
stock_available -= quantity
stock_reserved  += quantity
```

Jika stok tidak mencukupi, order tidak boleh dibuat.

### 6.2 Order dibatalkan

Saat order dibatalkan, sistem melepas reservasi:

```text
stock_reserved  -= quantity
stock_available += quantity
```

### 6.3 Order selesai

Saat order selesai, sistem memindahkan stok reserved menjadi stock out:

```text
stock_reserved -= quantity
stock_out      += quantity
```

### 6.4 Hal yang perlu diuji QA

- Order dengan stok tepat sama dengan jumlah yang diminta.
- Order dengan stok kurang dari jumlah yang diminta.
- Dua order yang memakai stok varian dan warehouse yang sama.
- Pembatalan order sebelum fulfillment.
- Penyelesaian order setelah stok di-reserve.
- Order dengan warehouse fulfillment yang berbeda.

## 7. Stock Movement

Stock Movement memindahkan stok dari satu warehouse ke warehouse lain.

### 7.1 Data penting

| Data | Keterangan |
| --- | --- |
| `movement_number` | Nomor transaksi otomatis. |
| `from_warehouse_id` | Warehouse sumber. |
| `to_warehouse_id` | Warehouse tujuan. |
| `requested_by` | User yang meminta perpindahan. |
| `movement_date` | Tanggal perpindahan. |
| `approval_status` | Status approval. |
| `approval_level` | Level approval yang sedang aktif. |
| `status` | Status proses perpindahan. |
| `shipped_at` | Waktu stok dikirim. |
| `received_at` | Waktu stok diterima. |
| `StockMovementItem` | Detail varian, jumlah, unit, dan jumlah progress. |

### 7.2 Status proses

```text
Draft -> Requested -> Approved -> Shipped -> Received
  |          |          |
  +----------+----------+--> Cancelled
```

Status approval terpisah dari status proses:

```text
Pending -> Approved
    |
    +----> Rejected
```

### 7.3 Aturan jumlah

Untuk setiap item:

```text
0 <= quantity_shipped <= quantity_requested
0 <= quantity_received <= quantity_shipped
```

Jumlah yang dikirim boleh lebih kecil dari jumlah yang diminta. Jumlah yang dikirim tidak boleh lebih besar dari jumlah yang diminta.

### 7.4 Saat Stock Movement di-ship

Sistem:

1. Memeriksa approval.
2. Memeriksa stok tersedia di warehouse sumber.
3. Mengurangi `stock_available` pada warehouse sumber.
4. Mencatat `StockMutation` dengan source `transfer`.
5. Mengisi `quantity_shipped` dan `shipped_at`.
6. Mengubah status menjadi `Shipped`.

Warehouse tujuan belum menerima stok pada tahap ini.

### 7.5 Saat Stock Movement di-receive

Sistem:

1. Memastikan movement berstatus `Shipped`.
2. Memastikan jumlah diterima tidak melebihi jumlah dikirim.
3. Menambah `stock_available` pada warehouse tujuan.
4. Mencatat `StockMutation` dengan source `transfer`.
5. Mengisi `quantity_received` dan `received_at`.
6. Mengubah status menjadi `Received`.

### 7.6 Direct transfer

`StockMovement::transfer()` adalah jalur transaksi langsung. Jalur ini memindahkan stok dari sumber ke tujuan dalam satu transaksi dan membuat movement berstatus `Received`.

Jalur direct transfer melewati tahap approval karena transaksi dianggap sudah selesai saat dibuat. QA perlu memastikan bahwa jalur ini hanya tersedia untuk role atau proses yang memang boleh melewati approval.

## 8. Setting Approval

Menu `Settings > Approval Settings` mengatur approval per modul.

Modul yang saat ini tersedia:

- `Stock Movement`
- `Stock Opname`

### 8.1 Approval level

Setiap modul dapat memiliki satu atau beberapa level.

Contoh:

| Level | Role |
| --- | --- |
| 1 | Warehouse Supervisor |
| 2 | Warehouse Manager |
| 3 | Operations Manager |

Approval berjalan berurutan. Approver Level 2 tidak dapat menyetujui transaksi yang masih berada di Level 1.

### 8.2 Alur approval

```text
Transaksi dibuat
      |
      v
Pending, Level 1
      |
      v
Level 1 menyetujui
      |
      v
Pending, Level 2
      |
      v
Level terakhir menyetujui
      |
      v
Approved
```

Jika salah satu level menolak:

```text
Pending -> Rejected
```

Setiap keputusan disimpan pada `approval_logs`, termasuk:

- Modul.
- Transaksi.
- Level.
- Action `approved` atau `rejected`.
- User yang melakukan action.
- Catatan rejection.
- Waktu action.

### 8.3 Jika tidak ada level

Jika approval setting aktif tetapi belum memiliki level, sistem tidak mewajibkan approval. Transaksi dapat melanjutkan alur normal.

Aturan ini membuat modul tetap dapat digunakan sebelum tim selesai mengisi konfigurasi approver.

## 9. Stock Opname

Stock Opname membandingkan stok sistem dengan stok fisik di warehouse.

### 9.1 Status sesi

```text
Draft -> Frozen -> Counting -> Submitted -> Approved -> Finalized
  |
  +-------------------------------> Cancelled
```

Status approval disimpan terpisah dari status sesi.

### 9.2 Alur umum

1. Admin membuat sesi Stock Opname.
2. Sistem mengisi item berdasarkan warehouse dan product variant.
3. Sistem membekukan transaksi yang dapat mengubah stok.
4. Checker mengisi jumlah fisik.
5. User mengirim sesi untuk approval.
6. Approver memeriksa perbedaan stok.
7. Approver menyetujui atau menolak sesi.
8. Sistem menerapkan hasil opname melalui source `stocktake`.
9. Sistem membuka kembali transaksi setelah proses selesai.

### 9.3 Rumus selisih

```text
variance = physical_quantity - system_quantity
```

- Nilai positif berarti stok fisik lebih banyak.
- Nilai negatif berarti stok fisik lebih sedikit.
- Nilai nol berarti stok sesuai.

QA perlu memastikan bahwa hanya sesi yang disetujui yang dapat mengubah stok.

## 10. System Freeze

System Freeze melindungi stok selama Stock Opname berjalan.

Saat sistem frozen:

- Transaksi inventory yang mengubah stok harus ditolak.
- Stock adjustment harus ditolak.
- Order yang mengubah stok harus ditolak atau mengikuti aturan freeze yang disepakati.
- Master data dan menu Settings tetap dapat dibuka.
- Proses stocktake tetap dapat berjalan.

Setiap modul yang mengubah stok harus memanggil `SystemFreezeGuard` sebelum menulis perubahan.

## 11. Role dan kewenangan

| Aktivitas | Role yang disarankan |
| --- | --- |
| Melihat stok | Inventory Staff, Supervisor, Manager |
| Membuat Stock Movement | Inventory Staff |
| Approve Level 1 | Warehouse Supervisor |
| Approve Level 2 | Warehouse Manager |
| Ship movement | Warehouse Staff atau user yang ditentukan bisnis |
| Receive movement | Warehouse Staff tujuan |
| Membuat Stock Opname | Inventory Supervisor |
| Mengisi jumlah fisik | Checker |
| Menyetujui Stock Opname | Inventory Manager |
| Melakukan Stock Adjustment | Inventory Manager |
| Mengelola approval setting | System Administrator |

Role di atas adalah rancangan awal. Tim QA dan bisnis perlu mengonfirmasi nama role dan batas akses sebenarnya.

## 12. Aturan bisnis yang harus dijaga

1. Warehouse sumber dan tujuan tidak boleh sama.
2. Jumlah movement harus lebih besar dari nol.
3. Jumlah ship tidak boleh melebihi jumlah request.
4. Jumlah receive tidak boleh melebihi jumlah ship.
5. Stok available tidak boleh menjadi negatif.
6. User tanpa role approver tidak boleh menyetujui level tersebut.
7. Approver harus mengikuti urutan level.
8. Transaksi yang sudah received tidak boleh di-ship ulang.
9. Mutasi stok harus mencatat before, after, quantity change, source, dan actor.
10. Stock Mutation tidak boleh diubah atau dihapus dari UI.
11. Stock Opname harus memakai hasil fisik yang sudah disetujui.
12. Transaksi yang diblokir System Freeze tidak boleh mengubah stok.

## 13. Skenario QA utama

### Stock Movement

- Membuat movement dengan satu item.
- Membuat movement dengan beberapa item.
- Membuat movement tanpa item.
- Memilih warehouse sumber dan tujuan yang sama.
- Mengisi ship quantity lebih besar dari request quantity.
- Mengisi ship quantity lebih kecil dari request quantity.
- Menerima quantity lebih besar dari ship quantity.
- Ship sebelum approval selesai.
- Approve dengan role yang benar.
- Approve dengan role yang salah.
- Approve level 1 lalu level 2.
- Reject dengan alasan.
- Menerima movement yang sudah shipped.
- Mencoba mengubah stok saat system frozen.

### Setting Approval

- Membuka daftar modul.
- Mengaktifkan dan menonaktifkan approval.
- Menambah satu approval level.
- Menambah beberapa approval level.
- Memilih role approver.
- Menghapus approval level.
- Memastikan level berjalan berurutan.
- Memastikan hanya role yang sesuai yang melihat action approval.
- Memastikan approval log menyimpan actor dan waktu.

### Stock Opname

- Membuat sesi untuk warehouse tertentu.
- Mengisi jumlah fisik.
- Menghitung variance positif, negatif, dan nol.
- Menolak perubahan stok saat sesi belum approved.
- Menerapkan stocktake setelah approval.
- Memastikan transaksi lain mengikuti aturan freeze.

## 14. Kondisi implementasi saat ini

Sudah tersedia:

- Stok per product variant.
- Stok per warehouse.
- Bucket available, reserved, dan out.
- Stock Mutation sebagai audit trail.
- Stock Adjustment.
- Stock Movement dengan item, ship, receive, dan reject dispatch.
- Stock Opname dengan sesi, item, freeze, dan approval status.
- Setting Approval untuk Stock Movement dan Stock Opname.
- Approval role-based dan multi-level.
- Approval log.
- Pembatasan ship Stock Movement sebelum approval selesai jika approval level dikonfigurasi.

Perlu dikonfirmasi atau dilanjutkan:

- Apakah direct transfer boleh melewati approval.
- Apakah Stock Opname harus diblokir sampai approval selesai pada tahap finalisasi.
- Apakah approver boleh menyetujui transaksi yang dibuat sendiri.
- Apakah satu level boleh memiliki lebih dari satu role.
- Apakah satu level memerlukan semua approver atau cukup salah satu approver.
- Apakah rejection mengembalikan transaksi ke Draft atau tetap Rejected.
- Apakah approval setting berlaku per warehouse atau hanya per modul.
- Apakah perubahan konfigurasi approval berlaku pada transaksi yang sudah berjalan.

## 15. Prinsip membaca inventory

Saat memeriksa satu perubahan stok, telusuri data dengan urutan berikut:

```text
Transaksi bisnis
  -> StockMutation
      -> WarehouseStock
          -> ProductVariant
```

Contoh pertanyaan diagnosis:

1. Transaksi apa yang mengubah stok?
2. Source mutasinya apa?
3. Warehouse mana yang berubah?
4. Product variant mana yang berubah?
5. Berapa nilai before dan after?
6. Apakah actor dan waktu tercatat?
7. Apakah ada approval atau system freeze yang seharusnya memblokir perubahan?

Jika tujuh pertanyaan ini dapat dijawab, alur inventory biasanya dapat ditelusuri sampai ke sumber masalahnya.

