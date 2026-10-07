<?php
defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/authlib.php');
require_once($CFG->libdir . '/filelib.php');

class auth_plugin_gate extends auth_plugin_base
{

    public function __construct()
    {
        $this->authtype = 'gate';
    }

    /**
     * Mencegat halaman login standar Moodle jika ada parameter ?token=
     */
    public function loginpage_hook()
    {
        global $CFG, $SESSION, $DB, $USER;

        // 1. Ambil token dari URL
        $token = optional_param('token_sso', '', PARAM_RAW);

        if (empty($token)) {
            return;
        }

        // 2. Ambil konfigurasi SSO dari variabel global $CFG (diset di config.php)
        $sso_url       = $CFG->sso_gate_url ?? '';
        $app_key       = $CFG->sso_gate_app_key ?? '';
        $client_secret = $CFG->sso_gate_secret ?? '';
        $scopes        = $CFG->sso_gate_scopes ?? 'basic,email';

        if (empty($app_key) || empty($client_secret) || empty($sso_url)) {
            $SESSION->loginerrormsg = 'Konfigurasi kredensial Gate SSO belum diatur oleh Administrator.';
            redirect(new moodle_url('/login/index.php'));
        }

        // 3. Proses cURL ke Server SSO
        $curl = new curl();

        // Set Header Authorization
        $options = array(
            'CURLOPT_HTTPHEADER' => array(
                'Authorization: Bearer ' . $token,
                'Accept: application/json'
            )
        );

        // Data payload
        $data = array(
            'app_key'       => $app_key,
            'client_secret' => $client_secret,
            'scopes'        => $scopes
        );

        // Eksekusi request
        $response = $curl->post($sso_url . '/sso-verify', $data, $options);
        $info = $curl->get_info();

        // 4. Evaluasi Response SSO
        if ($info['http_code'] != 200) {
            $SESSION->loginerrormsg = 'Gagal memverifikasi token ke server SSO.';
            redirect(new moodle_url('/login/index.php'));
        }

        $responseData = json_decode($response, true);

        if (isset($responseData['success']) && $responseData['success'] == false) {
            $SESSION->loginerrormsg = $responseData['message'] ?? 'Akses ditolak oleh Gate SSO.';
            redirect(new moodle_url('/login/index.php'));
        }

        // 5. Ambil dan normalisasi data User
        $userData = $responseData['data'];
        $sso_username = core_text::strtolower($userData['username']);

        // --- Cekal Keuangan (Menggunakan API Cybercampus) ---
        // Pengecekan keuangan hanya diberlakukan jika user adalah mahasiswa
        $is_mahasiswa = isset($userData['groups']) && (
            (is_array($userData['groups']) && in_array('mahasiswa', $userData['groups'])) ||
            ($userData['groups'] === 'mahasiswa')
        );

        if ($is_mahasiswa && $this->check_cekal_keuangan($sso_username)) {
            $SESSION->loginerrormsg = 'Gagal Login: Akun Anda sedang ditangguhkan karena masalah administrasi keuangan (Cekal Keuangan). Silakan hubungi bagian keuangan.';
            redirect(new moodle_url('/login/index.php'));
        }

        // 6. Cegah konflik sesi jika user sudah login
        if (isloggedin() && !isguestuser()) {
            if ($USER->username === $sso_username) {
                // Akun sama, langsung masuk dashboard
                redirect(new moodle_url('/my/'));
            }
            // Akun beda, paksa logout dulu
            require_logout();
        }

        // 7. Cari User di Database Moodle
        $moodle_user = $DB->get_record('user', array('username' => $sso_username, 'deleted' => 0, 'mnethostid' => $CFG->mnet_localhost_id));

        if (!$moodle_user) {
            $SESSION->loginerrormsg = 'Akun Anda tidak memiliki akses. Hubungi admin helpdesk.';
            redirect(new moodle_url('/login/index.php'));
        }

        if (empty($userData['email_verified'])) {
            $SESSION->loginerrormsg = 'Email SSO Anda belum terverifikasi.';
            redirect(new moodle_url('/login/index.php'));
        }

        // 8. Sinkronisasi Data Profil
        $update_data = new stdClass();
        $update_data->id = $moodle_user->id;
        $update_data->email = $userData['email_verified'];

        // firstname ambil dari fullname penuh, lastname ambil dari username
        $update_data->firstname = $userData['fullname'];
        $update_data->lastname  = $sso_username;

        // Simpan pembaruan ke DB
        $DB->update_record('user', $update_data);

        // Refresh objek user
        $moodle_user = $DB->get_record('user', array('id' => $moodle_user->id));

        // 9. Eksekusi Login Bypass
        complete_user_login($moodle_user);

        // Terapkan batasan concurrent login Moodle
        \core\session\manager::apply_concurrent_login_limit($moodle_user->id, session_id());

        // 10. Arahkan ke Dashboard
        redirect(new moodle_url('/my/'));
    }

    /**
     * Helper untuk mengeksekusi HTTP Request di APICybercampus
     */
    private function perform_api_request($method, $url, $data = null)
    {
        $curl = new curl();

        if ($method === 'POST') {
            $response = $curl->post($url, $data);
        } else {
            $response = $curl->get($url);
        }

        $info = $curl->get_info();
        if ($info['http_code'] != 200) {
            return [
                'status' => $info['http_code'],
                'message' => 'Unknown Error',
                'data' => []
            ];
        }

        $decoded = json_decode($response, true);
        return ($decoded !== null) ? $decoded : $response;
    }

    /**
     * Helper untuk mengambil token di APICybercampus
     */
    private function get_active_token()
    {
        global $CFG;
        
        $urlAPI = $CFG->cybercampus_api_url ?? 'https://api.um-surabaya.ac.id/';
        $userAPI = $CFG->cybercampus_api_user ?? 'ISI_USER_API_DISINI'; 
        $keyAPI = $CFG->cybercampus_api_key ?? 'ISI_KEY_API_DISINI'; 

        // Pastikan URL diakhiri dengan slash
        $urlAPI = rtrim($urlAPI, '/') . '/';
        $url = $urlAPI . 'token/ambil-token-v2';
        
        $data = [
            'user' => $userAPI,
            'key' => $keyAPI,
        ];

        $result = $this->perform_api_request('POST', $url, $data);

        if ($result !== "Unauthorized") {
            return is_array($result) && isset($result['token']) ? $result['token'] : $result;
        }

        return null;
    }

    /**
     * Memeriksa status cekal keuangan ke API Cybercampus
     * 
     * @param string $username Username/NIM yang akan dicek
     * @return bool True jika dicekal, False jika aman
     */
    private function check_cekal_keuangan($username)
    {
        global $CFG;
        
        $urlAPI = $CFG->cybercampus_api_url ?? 'https://api.um-surabaya.ac.id/';
        $urlAPI = rtrim($urlAPI, '/') . '/';
        
        $endpoint = $CFG->cybercampus_api_endpoint_cekal ?? 'keuangan/dummy-cek-keuangan';
        // Hapus slash di awal endpoint jika ada
        $endpoint = ltrim($endpoint, '/');

        $url = $urlAPI . $endpoint;

        $data = [
            'nim' => $username
        ];

        $data['token'] = $this->get_active_token();

        if (empty($data['token'])) {
            return false; // Jika gagal dapat token, jangan blokir user
        }

        // Eksekusi request API
        $result = $this->perform_api_request('POST', $url, $data);

        // Penerimaan response disamakan. Misalkan API mengembalikan JSON indikasi cekal.
        if (is_array($result)) {
            if (isset($result['is_cekal']) && $result['is_cekal'] == true) {
                return true;
            } elseif (isset($result['data']['is_cekal']) && $result['data']['is_cekal'] == true) {
                return true;
            }
        }

        return false;
    }
    /**
     * Menonaktifkan validasi form manual karena plugin ini hanya untuk bypass SSO
     */
    public function user_login($username, $password)
    {
        return false;
    }
}
