# FlowGIS UAT Issues Tracker (Post GeoServer Microservice v1 Migration)

Dokumen ini melacak temuan UAT, status verifikasi/reproduksi, akar masalah teknis, serta implementasi perbaikan berkas dan panduan uji manual.

---

## Ringkasan Status Tugas A

| ID | Modul | Deskripsi Temuan | Status | Akar Masalah Utama | Berkas yang Diperbaiki |
|---|---|---|---|---|---|
| **USR-012** | User Dashboard / Analysis | Edit tanggal proyek & analisis ulang tidak mengubah layer peta & daftar "Analysis Results". | **RESOLVED (CP3)** | 1. Backend: `$layerName` undefined di `AnalysisController::saveAnalysis()`.<br>2. Frontend: `MapViewer.jsx` mendrop layer karena hanya mencari `layer.wms_layers_param`.<br>3. Hilangnya cache-busting deterministik (`_v`) saat analisis diperbarui. | `AnalysisController.php`, `AstraGisService.php`, `wmsHelper.js`, `MapViewer.jsx`, `Dashboard.jsx` |
| **ADM-005** | Admin / Style | Ubah style layer (mis. Weir) ke ungu dan disimpan, peta tetap hijau. | **RESOLVED (CP3)** | 1. Diskrepansi kontrak API: Laravel mengirim `style_sld`, microservice mengharapkan `sld_xml`.<br>2. GeoServer gagal pasang style, tapi Laravel return `200 OK` (silent failure).<br>3. Leaflet WMS tidak menyertakan parameter `styles` dan versi cache buster. | `AstraGisService.php`, `GeosocialController.php`, `AdminMapLeaflet.jsx`, `AdminDashboard.jsx`, `wmsHelper.js` |
| **ADM-003** | Admin / Geosocial | Preview layer Geosocial tidak menampilkan TIFF baru; TIFF lain baru muncul setelah klik tabel. | **RESOLVED (CP3)** | 1. `publish-raster` mengembalikan `store_name`, bukan `layer_name`, sehingga fallback ke slug salah `geosocial:...`.<br>2. Bbox dict `{left, bottom, right, top}` diproses sebagai array `[0]`.<br>3. State `activeWmsLayer` null saat katalog pertama kali dimuat. | `GeosocialController.php`, `GeosocialForm.jsx`, `AdminDashboard.jsx`, `AdminMapLeaflet.jsx`, `wmsHelper.js` |

---

## Rincian Perubahan Implementasi

### 1. `flowgis-frontend/src/lib/wmsHelper.js` & `__tests__/wmsHelper.test.js`
- Dibuat helper terpusat `wmsHelper` mengikuti prinsip **DRY**:
  - `resolveWmsLayersParam`: Resolusi cerdas untuk layer raster (`workspace:store_name`) maupun vektor (`workspace:layer_name`), `geoserver_name`, dan legacy `wms_layers_param`.
  - `resolveWmsUrl`: Menormalisasi URL GeoServer WMS bebas dari trailing slash atau parameter usang.
  - `resolveWmsStyleParam`: Resolusi nama style (`style_name`, `styles`, atau `style`).
  - `normalizeBbox`: Mengonversi bounding box format array `[minx, miny, maxx, maxy]` maupun dictionary `{left, bottom, right, top}` ke bounds Leaflet `[[south, west], [north, east]]`. Mengamankan koordinat proyeksi meter (e.g. UTM EPSG:32647) agar tidak merusak peta Leaflet.
  - `buildWmsLayerConfig`: Membentuk konfigurasi Leaflet TileLayer WMS yang menyertakan parameter `styles`, `version`, dan cache-busting deterministik `_v` berbasis waktu modifikasi layer (`updated_at` / `_v`).
- Test regresi unit diuji dengan hasil: 100% lulus (5/5 suite).

### 2. `flowgis-business-process`
- **`app/Services/AstraGisService.php`**:
  - Memperbaiki `publishRaster()` dan `publishFromUrl()` agar menerima `$metadata = []`, meneruskan `extra_metadata` (termasuk `project_id`, `aoi_id`, `parameters`), dan menormalisasi respons `store_name` menjadi `layer_name`.
  - Memperbaiki `applyStyle()` agar mengirimkan payload kompatibilitas ganda: `'sld_xml' => $sldXml` dan `'style_sld' => $sldXml`.
- **`app/Http/Controllers/Api/AnalysisController.php`**:
  - Memperbaiki `$layerName` undefined di `saveAnalysis()` (sebelumnya memicu fatal TypeError PHP 8).
  - Memberikan penamaan deterministik: `"ana_p{$project->id}_{$cleanComp}_" . $analysis->id`.
  - Menambahkan metadata relasi proyek (`project_id`, `analysis_id`, `aoi_id`) agar layer terbaca oleh filter `userLayers()`.
- **`app/Http/Controllers/Api/GeosocialController.php`**:
  - Di `upload()`: mendeteksi `store_name` jika `layer_name` null, mengembalikan `bbox` dan `epsg` yang lengkap pada response JSON.
  - Di `updateStyle()`: menghapus *silent failure* (mengembalikan HTTP 422 jika GeoServer gagal menerapkan style, mengembalikan `style_name` dan `updated_at`).

### 3. `flowgis-frontend`
- **`src/components/Map/MapViewer.jsx`**:
  - Mengintegrasikan `buildWmsLayerConfig()` untuk me-render WMS layer secara seragam.
  - Menghapus blokir `if (!layer.wms_layers_param) return;` yang mendrop layer analisis hasil microservice v1.
- **`src/components/AdminComponents/AdminMapLeaflet.jsx`**:
  - Mengintegrasikan `buildWmsLayerConfig()` sehingga Leaflet meminta parameter `styles` yang tepat ke GeoServer.
  - Menggunakan `normalizeBbox()` untuk mencegah crash saat memuat bounding box raster TIFF.
- **`src/components/AdminComponents/GeosocialForm.jsx`**:
  - Menggunakan `resolveWmsLayersParam()` dan `normalizeBbox()` pada layer baru yang diunggah.
- **`src/pages/AdminDashboard.jsx`**:
  - Menambahkan auto-preview layer pertama saat `fetchGeosocialLayers()` selesai jika belum ada layer aktif yang dipilih.
  - Menghubungkan respons `updateStyle` ke `activeWmsLayer` dengan atribut `styles` dan `_v` cache-buster.
- **`src/pages/Dashboard.jsx`**:
  - Menghubungkan pembaruan WMS layer pada alur simpan/analisis ulang proyek dengan penanda versi deterministik `_v`.
  - Menggunakan `normalizeBbox()` pada fungsi zoom ke layer analisis.

---

## Panduan Langkah Uji Manual (Perlu Uji Manual)

> **Catatan Uji Manual**: Karena perubahan mencakup interaksi visual di canvas peta Leaflet dan rendering tile GeoServer, lakukan pengujian berikut di browser.

### Skenario 1: USR-012 (Analisis Ulang Proyek)
1. Buka aplikasi FlowGIS User Dashboard (`http://localhost:5173` atau URL lokal Anda).
2. Pilih sebuah Proyek yang sudah ada atau buat proyek baru.
3. Jalankan analisis risiko banjir (Flood Risk Analysis).
4. Amati bahwa layer WMS muncul di peta dan tercatat di panel **Analysis Results**.
5. Buka pengaturan proyek, ubah rentang tanggal analisis, lalu jalankan analisis ulang.
6. **Ekspektasi**: Peta langsung me-refresh tampilan tile layer dengan visualisasi tanggal baru, legenda menyesuaikan, dan entri di panel "Analysis Results" terbarui tanpa perlu refresh browser penuh (F5).

### Skenario 2: ADM-005 (Perubahan Style Layer Weir ke Ungu)
1. Masuk ke halaman **Admin Dashboard** -> Menu **Geosocial Data Layers**.
2. Cari layer vektor (contoh: *Weir* atau *Administrative Boundaries*).
3. Klik tombol aksi **Styling (Palette icon)** pada baris layer tersebut.
4. Pada modal styling SLD:
   - Pilih warna fill ungu (`#8B5CF6`).
   - Ubah stroke color dan tebal garis jika diinginkan.
5. Klik **Simpan Style**.
6. **Ekspektasi**: Muncul notifikasi sukses "Style layer berhasil diperbarui di GeoServer!". Peta Leaflet di sebelah kiri langsung me-reload WMS tiles dan menampilkan fitur vektor berwarna ungu (tidak lagi hijau).

### Skenario 3: ADM-003 (Auto-preview & Upload TIFF Geosocial)
1. Buka halaman **Admin Dashboard** -> Menu **Geosocial Data Layers**.
2. **Ekspektasi Awal**: Saat halaman pertama kali dimuat, peta Leaflet di sebelah kiri langsung menampilkan preview layer aktif pertama dari katalog secara otomatis (tanpa perlu klik manual baris tabel).
3. Klik tombol **Unggah Layer Spasial Baru** (GeoTIFF / TIFF).
4. Unggah sebuah berkas GeoTIFF raster.
5. Setelah proses upload dan publikasi selesai:
6. **Ekspektasi Baru**: Form upload tertutup, layer langsung terpilih sebagai `activeWmsLayer`, dan peta otomatis melakukan `fitBounds` (zoom) ke area cakupan citra GeoTIFF tersebut dengan visualisasi tile WMS yang jelas.
