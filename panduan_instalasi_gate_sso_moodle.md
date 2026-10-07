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

Plugin ini dirancang untuk membaca kredensial SSO dan API secara langsung dari *environment variable* global Moodle (`$CFG`).
Tambahkan baris kode berikut ke dalam file `config.php` yang berada di direktori root Moodle Anda:

```php
// ==========================================
// KONFIGURASI GATE SSO
// ==========================================
$CFG->sso_gate_url       = 'https://gate.um-surabaya.ac.id'; 
$CFG->sso_gate_app_key   = 'ISI_APP_KEY_DISINI';
$CFG->sso_gate_secret    = 'ISI_CLIENT_SECRET_DISINI';
$CFG->sso_gate_scopes    = 'basic,email';

// ==========================================
// KONFIGURASI API CEKAL KEUANGAN
// ==========================================
$CFG->cybercampus_api_url            = 'https://api.um-surabaya.ac.id/';
$CFG->cybercampus_api_user           = 'ISI_USER_API_DISINI';
$CFG->cybercampus_api_key            = 'ISI_KEY_API_DISINI';
$CFG->cybercampus_api_endpoint_cekal = 'keuangan/status-cekal'; // Ganti dengan path aslinya jika sudah siap
```

## 5. Menyesuaikan Response API Cekal Keuangan

Plugin ini dilengkapi dengan fitur pengecekan status keuangan sebelum auto-login Moodle dieksekusi (khusus untuk user dengan *group* `mahasiswa`). Semua pengaturan URL dan *credentials* sudah diatur di `config.php`.

Ketika API Cybercampus untuk sistem Cekal Keuangan sudah sepenuhnya siap, Anda mungkin hanya perlu menyesuaikan pembacaan *response* JSON (jika format balikan/key dari server berbeda dari standar). 

Pada file `[ROOT_MOODLE]/auth/gate/auth.php`, tepat di bagian bawah method `check_cekal_keuangan`, terdapat blok kode:
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
4. **Penting:** Pastikan untuk mengganti nilai `ISI_APP_KEY_DISINI` pada script dengan `APP_KEY` yang sebenarnya dari sistem Gate SSO.
5. Simpan perubahan (*Save changes*).

**Kode Script Tombol SSO (Untuk Moodle 5.2):**

```html
<!-- ========================================== -->
<!-- SCRIPT TOMBOL SSO -->
<!-- ========================================== -->
<script type="text/javascript">
    // <![CDATA[
    if (window.location.pathname.indexOf("/login/index.php") !== -1) {
        document.addEventListener("DOMContentLoaded", function () {
            // --- 1. MEMBUAT TOMBOL SSO ---
            var ssoDiv = document.createElement("div");
            ssoDiv.className = "mb-4 pb-4 border-bottom text-center";
            ssoDiv.innerHTML =
                '<div class="alert alert-info py-2 px-3 small mb-3 text-start">' +
                '<i class="fa fa-info-circle mr-1"></i>' +
                "<strong>Informasi:</strong> Mahasiswa dan Dosen bisa langsung login menggunakan <strong>Gate SSO</strong>." +
                "</div>" +
                '<button type="button" onclick="loginWithSSO()" class="btn btn-primary w-100 rounded-pill">' +
                '<i class="fa fa-sign-in mr-2"></i> Login dengan Gate SSO' +
                "</button>";

            var loginForm = document.querySelector("form#login");

            if (loginForm && loginForm.parentNode) {
                loginForm.parentNode.insertBefore(ssoDiv, loginForm);
            }

            // --- 2. MEMBUAT ELEMEN BACKDROP LOADING ---
            var backdrop = document.createElement("div");
            backdrop.id = "sso-loading-backdrop";
            backdrop.style.cssText = "display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.75); z-index: 9999; flex-direction: column; justify-content: center; align-items: center; color: white; backdrop-filter: blur(3px);";
            backdrop.innerHTML = '<i class="fa fa-circle-o-notch fa-spin fa-4x mb-3"></i>' + '<h4 id="sso-loading-title" class="text-white">Menunggu Autentikasi...</h4>' + '<p class="small text-light">Silakan selesaikan login di jendela popup SSO.</p>';
            document.body.appendChild(backdrop);
        });

        // --- 3. FUNGSI PEMBUKA POPUP & TRIGGER BACKDROP ---
        window.loginWithSSO = function () {
            var SSO_URL = "https://" + "gate.um-surabaya.ac.id";
            var SSO_APP_KEY = "ISI_APP_KEY_DISINI"; // Ganti dengan APP_KEY yang valid
            var gateSSOUrl = SSO_URL + "/sso-authorize/" + SSO_APP_KEY;

            var width = 500;
            var height = 600;
            var left = (window.screen.width - width) / 2;
            var top = (window.screen.height - height) / 2;

            var ssoWindow = window.open(gateSSOUrl, "SSOWindow", "width=" + width + ",height=" + height + ",top=" + top + ",left=" + left);
            var backdrop = document.getElementById("sso-loading-backdrop");

            if (!ssoWindow || ssoWindow.closed || typeof ssoWindow.closed == "undefined") {
                alert("Jendela SSO diblokir oleh browser. Silakan izinkan popup untuk situs ini.");
                return;
            }

            if (backdrop) backdrop.style.display = "flex";

            var checkWindowClosed = setInterval(function () {
                if (ssoWindow && ssoWindow.closed) {
                    clearInterval(checkWindowClosed);
                    if (backdrop) backdrop.style.display = "none";
                }
            }, 500);
        };

        // --- 4. LISTENER PENERIMA TOKEN & REDIRECT ---
        window.addEventListener("message", function (event) {
            var gateOrigin = "https://" + "gate.um-surabaya.ac.id";
            if (event.origin !== gateOrigin) return;

            if (event.data && event.data.status === "success" && event.data.token) {
                var title = document.getElementById("sso-loading-title");
                if (title) {
                    title.innerHTML = "Autentikasi Berhasil! Mengarahkan...";
                    title.className = "text-success fw-bold";
                }

                window.location.href = "/login/index.php?token_sso=" + event.data.token;
            }
        });
    }
    // ]]>
</script>
```
