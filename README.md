# Panduan Instalasi & Konfigurasi Plugin Auth "Gate SSO UMSURA" untuk Moodle

Dokumen ini berisi panduan teknis bagi Pengembang E-Learning / Administrator Moodle untuk melakukan instalasi dan pengaturan plugin otentikasi kustom **Gate SSO**.

---

## 1. Penempatan File & Direktori Plugin

Berdasarkan struktur komponen Moodle (`auth_gate`), nama direktori plugin harus disesuaikan agar terbaca oleh sistem:
1. Pastikan folder plugin yang saat ini bernama `gate`.
2. Salin atau pindahkan folder `gate` tersebut ke dalam direktori `/auth` di root instalasi Moodle Anda.
   - **Path akhir:** `[ROOT_MOODLE]/auth/gate/`
   - Pastikan isi di dalam folder tersebut terdapat file `auth.php`, `version.php`, dsb.

## 2. Instalasi Plugin

1. Login ke Moodle sebagai pengguna dengan hak akses **Site Administrator**.
2. Secara default, Moodle akan mendeteksi plugin baru saat Anda mengakses dashboard. Jika tidak, masuk ke menu **Site administration > Notifications**.
3. Sistem akan meminta konfirmasi instalasi plugin baru (Authentication plugin: `auth_gate`).
4. Klik tombol **Upgrade Moodle database now** dan tunggu hingga proses instalasi selesai.

## 3. Mengaktifkan Plugin Authentication

1. Masuk ke menu **Site administration > Plugins > Authentication > Manage authentication**.
2. Pada daftar plugin otentikasi, cari plugin bernama **gate** (atau **Gate SSO**).
3. Klik ikon bergambar "Mata" untuk mengaktifkan (*Enable*) plugin tersebut.
4. Anda bisa menaikkan urutan (menggunakan tanda panah atas/bawah) jika ingin memprioritaskan metode login ini.

## 4. Konfigurasi Kredensial SSO (via config.php)

Plugin ini bersifat **general** dan dapat digunakan di semua instalasi Moodle (baik untuk E-Learning maupun Moodle non-E-Learning seperti pelatihan, sertifikasi, uji kompetensi, dll). Konfigurasi dibaca langsung dari variabel global Moodle (`$CFG`).

Tambahkan baris kode berikut ke dalam file `config.php` yang berada di direktori root Moodle Anda:

```php
// ==========================================
// KONFIGURASI WAJIB: GATE SSO (OAuth 2.0 + Single Logout)
// ==========================================
$CFG->sso_gate_url       = 'https://gate.um-surabaya.ac.id'; 
$CFG->sso_gate_app_key   = 'ISI_APP_KEY_DISINI';
$CFG->sso_gate_secret    = 'ISI_CLIENT_SECRET_DISINI';
$CFG->sso_gate_scopes    = 'basic,email,role';

// ==========================================
// KONFIGURASI OPSIONAL: API CEKAL KEUANGAN CYBERCAMPUS
// (Biarkan false atau hapus blok ini untuk Moodle non-E-Learning)
// ==========================================
$CFG->sso_gate_enable_cekal          = false; // Ubah ke true untuk mengaktifkan cek cekal keuangan mahasiswa
$CFG->cybercampus_api_url            = 'https://api.um-surabaya.ac.id/';
$CFG->cybercampus_api_user           = 'ISI_USER_API_DISINI';
$CFG->cybercampus_api_key            = 'ISI_KEY_API_DISINI';
$CFG->cybercampus_api_endpoint_cekal = 'keuangan/status-cekal'; // Ganti dengan path aslinya jika sudah siap
```

> **Catatan:** Secara bawaan (`default`), fitur cekal keuangan bernilai **nonaktif (`false`)** apabila `$CFG->sso_gate_enable_cekal` tidak didefinisikan atau diisi `false` di `config.php`, sehingga untuk Moodle selain E-Learning Anda tidak perlu mengisi kredensial API Cybercampus.

## 5. Mengaktifkan & Menyesuaikan Response API Cekal Keuangan (Opsional)

Jika `$CFG->sso_gate_enable_cekal = true;` diaktifkan pada `config.php`, plugin akan mengecek status keuangan mahasiswa melalui API Cybercampus sebelum auto-login Moodle dieksekusi (khusus untuk user dengan *group* `mahasiswa`).

Apabila format balikan JSON dari API Cybercampus berbeda dari standar, Anda dapat menyesuaikan pengecekan *response* pada file `[ROOT_MOODLE]/auth/gate/auth.php`, tepat di bagian bawah method `check_cekal_keuangan`:
```php
// Evaluasi respon API sesuai struktur JSON balikan
if (is_array($result)) {
    if (isset($result['is_cekal']) && $result['is_cekal'] == true) {
        return true;
    } elseif (isset($result['data']['is_cekal']) && $result['data']['is_cekal'] == true) {
        return true;
    }
}
```
Sesuaikan *key* array di atas (`is_cekal` atau `data['is_cekal']`) dengan format JSON aktual yang dikembalikan oleh API Cybercampus. Jika indikatornya berbeda (misal `status_tunggakan`), ubahlah variabel array di atas agar mengembalikan `true` ketika mahasiswa dinyatakan menunggak.

## 6. Menambahkan Tombol SSO di Halaman Login (via Additional HTML)

Agar pengguna dapat dengan mudah menemukan opsi login SSO, Anda perlu menambahkan tombol Gate SSO ke form halaman login Moodle. Hal ini dapat dilakukan tanpa menyentuh *core code* tema Moodle, yaitu melalui fitur bawaan **Additional HTML**.

**Langkah-langkah:**
1. Login sebagai Administrator.
2. Navigasi ke **Site administration > Appearance > Additional HTML**.
3. Pada kolom **Before BODY is closed**, salin dan tempel kode *script* di bawah ini.
4. Simpan perubahan (*Save changes*).

**Kode Script Tombol SSO & Collapse Form Manual (OAuth 2.0 + PKCE):**

```html
<!-- ========================================== -->
<!-- SCRIPT TOMBOL GATE SSO (OAUTH 2.0 + COLLAPSE FORM MANUAL) -->
<!-- ========================================== -->
<script type="text/javascript">
    // <![CDATA[
    if (window.location.pathname.indexOf("/login/index.php") !== -1) {
        document.addEventListener("DOMContentLoaded", function () {
            var errorEl = document.querySelector("#loginerrormessage, .loginerrors, .alert-danger");
            var isSSOError = errorEl && errorEl.textContent && errorEl.textContent.indexOf("Gate SSO") !== -1;
            var hasManualLoginError = errorEl !== null && !isSSOError;

            // --- 1. MEMBUAT TOMBOL SSO & TOGGLE FORM MANUAL ---
            var ssoDiv = document.createElement("div");
            ssoDiv.className = "mb-3 text-center";
            ssoDiv.innerHTML =
                '<div class="alert alert-info py-2 px-3 small mb-3 text-start text-left" role="alert">' +
                '<i class="fa fa-shield mr-1 me-1"></i>' +
                "<strong>Informasi:</strong> Silakan masuk menggunakan akun <strong>Gate SSO UMSURA</strong> Anda untuk akses terintegrasi." +
                "</div>" +
                '<button type="button" onclick="loginWithSSO()" class="btn btn-primary btn-lg w-100 rounded-pill fw-bold font-weight-bold shadow-sm">' +
                '<i class="fa fa-sign-in mr-2 me-2"></i> Login dengan Gate SSO' +
                "</button>" +
                '<div class="mt-3">' +
                '<button class="btn btn-link btn-sm text-muted text-decoration-none" type="button" data-bs-toggle="collapse" data-toggle="collapse" data-bs-target="#manualLoginFormCollapse" data-target="#manualLoginFormCollapse" aria-expanded="' + (hasManualLoginError ? "true" : "false") + '" aria-controls="manualLoginFormCollapse">' +
                '<i class="fa fa-key mr-1 me-1"></i> Masuk dengan Username &amp; Password <i class="fa fa-angle-down ml-1 ms-1"></i>' +
                "</button>" +
                "</div>";

            var loginForm = document.querySelector("form#login");

            if (loginForm && loginForm.parentNode) {
                loginForm.parentNode.insertBefore(ssoDiv, loginForm);

                // Bungkus form login manual agar tersembunyi (collapse) secara default
                var collapseWrapper = document.createElement("div");
                collapseWrapper.id = "manualLoginFormCollapse";
                collapseWrapper.className = "collapse" + (hasManualLoginError ? " show" : "") + " mt-3 pt-3 border-top";
                loginForm.parentNode.insertBefore(collapseWrapper, loginForm);
                collapseWrapper.appendChild(loginForm);
            }
        });

        // --- 2. FUNGSI REDIRECT KE ALUR OAUTH 2.0 + PKCE ---
        window.loginWithSSO = function () {
            window.location.href = "/login/index.php?auth_gate_sso=1";
        };

        // --- 3. LISTENER FALLBACK PENERIMA TOKEN LEGACY ---
        window.addEventListener("message", function (event) {
            var gateOrigin = "https://" + "gate.um-surabaya.ac.id";
            if (event.origin !== gateOrigin) return;

            if (event.data && event.data.status === "success" && event.data.token) {
                window.location.href = "/login/index.php?token_sso=" + event.data.token;
            }
        });
    }
    // ]]>
</script>
```
