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

    private function update_sso_login_status($historyId, $status)
    {
        global $CFG;

        if (empty($historyId)) return;

        $sso_url       = $CFG->sso_gate_url ?? '';
        $app_key       = $CFG->sso_gate_app_key ?? '';
        $client_secret = $CFG->sso_gate_secret ?? '';

        if (empty($app_key) || empty($client_secret) || empty($sso_url)) return;

        $curl = new curl(array('ignoresecurity' => true));
        $curl->setopt(array('CURLOPT_TIMEOUT' => 5));

        $data = array(
            'app_key'              => $app_key,
            'client_secret'        => $client_secret,
            'sso_login_history_id' => $historyId,
            'status'               => $status
        );

        $curl->post(rtrim($sso_url, '/') . '/sso-update-login-status', $data);
    }

    /**
     * Mencegat halaman login standar Moodle untuk:
     * 1. Inisiasi OAuth 2.0 Authorization Code + PKCE (?auth_gate_sso=1)
     * 2. Callback OAuth 2.0 (?code=...) maupun Fallback Token Legacy (?token_sso=... / ?token=...)
     */
    public function loginpage_hook()
    {
        global $CFG, $SESSION, $DB, $USER;

        $initOAuth  = optional_param('auth_gate_sso', 0, PARAM_INT);
        $code       = optional_param('code', '', PARAM_RAW);
        $state      = optional_param('state', '', PARAM_RAW);
        $oauthError = optional_param('error', '', PARAM_RAW);
        $errorDesc  = optional_param('error_description', '', PARAM_RAW);
        $token      = optional_param('token_sso', '', PARAM_RAW);
        if (empty($token)) {
            $token = optional_param('token', '', PARAM_RAW);
        }

        $sso_url       = rtrim((string) ($CFG->sso_gate_url ?? ''), '/');
        $app_key       = $CFG->sso_gate_app_key ?? '';
        $client_secret = $CFG->sso_gate_secret ?? '';
        $scopes        = $CFG->sso_gate_scopes ?? 'basic,email,role';
        $redirect_uri  = (new moodle_url('/login/index.php'))->out(false);

        // 1. Inisiasi OAuth 2.0 Authorization Code Flow + PKCE (S256)
        if ($initOAuth === 1) {
            if (empty($app_key) || empty($client_secret) || empty($sso_url)) {
                $SESSION->loginerrormsg = 'Gate SSO: Konfigurasi kredensial belum diatur oleh Administrator.';
                redirect(new moodle_url('/login/index.php'));
            }

            $oauthState    = 'moodle_gate_' . bin2hex(random_bytes(16));
            $codeVerifier  = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

            $SESSION->auth_gate_oauth_state   = $oauthState;
            $SESSION->auth_gate_code_verifier = $codeVerifier;

            $params = array(
                'response_type'         => 'code',
                'client_id'             => $app_key,
                'redirect_uri'          => $redirect_uri,
                'scope'                 => 'openid ' . str_replace(',', ' ', $scopes),
                'state'                 => $oauthState,
                'code_challenge'        => $codeChallenge,
                'code_challenge_method' => 'S256',
            );

            // Preflight check agar jika terjadi error konfigurasi di Gate SSO (misal HTTP 400 JSON atau 302 ?error=...),
            // pesan error ditampilkan rapi di halaman depan login Moodle, bukan raw JSON di browser.
            try {
                $preCurl = new curl(array('ignoresecurity' => true));
                $preCurl->setopt(array(
                    'CURLOPT_TIMEOUT'        => 4,
                    'CURLOPT_FOLLOWLOCATION' => false,
                ));
                $preResponse = $preCurl->get($sso_url . '/oauth/authorize', $params);
                $preInfo     = $preCurl->get_info();
                $preCode     = (int) ($preInfo['http_code'] ?? 0);

                if ($preCode >= 400) {
                    $preData = json_decode((string) $preResponse, true);
                    $errMsg  = $preData['error_description'] ?? $preData['message'] ?? ('Layanan Gate SSO menolak permintaan otorisasi (HTTP ' . $preCode . ').');
                    $SESSION->loginerrormsg = (stripos($errMsg, 'Gate SSO') === false ? 'Gate SSO: ' : '') . $errMsg;
                    redirect(new moodle_url('/login/index.php'));
                }

                if ($preCode >= 300 && $preCode < 400) {
                    $headers  = array_change_key_case((array) $preCurl->getResponse(), CASE_LOWER);
                    $location = is_array($headers['location'] ?? null)
                        ? (string) end($headers['location'])
                        : (string) ($headers['location'] ?? '');
                    if ($location !== '') {
                        $queryStr = (string) parse_url($location, PHP_URL_QUERY);
                        if ($queryStr !== '') {
                            parse_str($queryStr, $locQuery);
                            if (!empty($locQuery['error'])) {
                                $errMsg = (string) ($locQuery['error_description'] ?? $locQuery['error']);
                                $SESSION->loginerrormsg = (stripos($errMsg, 'Gate SSO') === false ? 'Gate SSO: ' : '') . $errMsg;
                                redirect(new moodle_url('/login/index.php'));
                            }
                        }
                    }
                }
            } catch (\Throwable $e) {
                // Jika preflight timeout, lanjutkan redirect normal ke Gate SSO
            }

            redirect(new moodle_url($sso_url . '/oauth/authorize', $params));
        }

        if (!empty($oauthError)) {
            $errText = !empty($errorDesc) ? $errorDesc : 'Otorisasi Gate SSO dibatalkan atau gagal.';
            $SESSION->loginerrormsg = (stripos($errText, 'Gate SSO') === false ? 'Gate SSO: ' : '') . $errText;
            redirect(new moodle_url('/login/index.php'));
        }

        if (empty($code) && empty($token)) {
            return;
        }

        if (empty($app_key) || empty($client_secret) || empty($sso_url)) {
            $SESSION->loginerrormsg = 'Gate SSO: Konfigurasi kredensial belum diatur oleh Administrator.';
            redirect(new moodle_url('/login/index.php'));
        }

        $curl = new curl(array('ignoresecurity' => true));
        $userData             = null;
        $sso_login_history_id = null;
        $oauth_id_token       = null;
        $sso_sid              = null;
        $sso_login_time       = time();

        // 2A. Mode 1: Standar OAuth 2.0 Authorization Code + PKCE (Shield JWT Addon)
        if (!empty($code)) {
            $expectedState = $SESSION->auth_gate_oauth_state ?? '';
            $codeVerifier  = $SESSION->auth_gate_code_verifier ?? '';
            unset($SESSION->auth_gate_oauth_state, $SESSION->auth_gate_code_verifier);

            if (!empty($expectedState) && strpos($state, 'moodle_gate_') === 0 && !hash_equals((string) $expectedState, (string) $state)) {
                $SESSION->loginerrormsg = 'Gate SSO: State OAuth 2.0 tidak valid (CSRF protection).';
                redirect(new moodle_url('/login/index.php'));
            }

            $tokenParams = array(
                'grant_type'    => 'authorization_code',
                'code'          => $code,
                'client_id'     => $app_key,
                'client_secret' => $client_secret,
                'redirect_uri'  => $redirect_uri,
            );
            if (!empty($codeVerifier) && $state === $expectedState) {
                $tokenParams['code_verifier'] = $codeVerifier;
            }

            $tokenResponse = $curl->post($sso_url . '/oauth/token', $tokenParams);
            $tokenInfo     = $curl->get_info();
            $tokenData     = json_decode($tokenResponse, true);

            if (($tokenInfo['http_code'] ?? 0) != 200 || empty($tokenData['access_token'])) {
                $errMsg = $tokenData['error_description'] ?? $tokenData['message'] ?? 'Gagal menukar kode otorisasi OAuth 2.0.';
                $SESSION->loginerrormsg = (stripos($errMsg, 'Gate SSO') === false ? 'Gate SSO: ' : '') . $errMsg;
                redirect(new moodle_url('/login/index.php'));
            }

            $accessToken          = $tokenData['access_token'];
            $oauth_id_token       = $tokenData['id_token'] ?? null;
            $sso_sid              = $tokenData['sso_sid'] ?? null;
            $sso_login_time       = (int) ($tokenData['sso_login_time'] ?? time());
            $sso_login_history_id = $tokenData['sso_login_history_id'] ?? null;

            // Ambil profil user dari /oauth/userinfo menggunakan Shield JWT Bearer Token
            $userinfoOptions = array(
                'CURLOPT_HTTPHEADER' => array(
                    'Authorization: Bearer ' . $accessToken,
                    'Accept: application/json',
                ),
            );
            $userinfoResponse = $curl->get($sso_url . '/oauth/userinfo', array(), $userinfoOptions);
            $userinfoInfo     = $curl->get_info();
            $userinfoData     = json_decode($userinfoResponse, true);

            if (($userinfoInfo['http_code'] ?? 0) != 200 || empty($userinfoData['data'])) {
                $errMsg = $userinfoData['message'] ?? 'Gagal mengambil profil pengguna dari Gate SSO.';
                $SESSION->loginerrormsg = (stripos($errMsg, 'Gate SSO') === false ? 'Gate SSO: ' : '') . $errMsg;
                redirect(new moodle_url('/login/index.php'));
            }

            $userData = $userinfoData['data'];
            if (empty($sso_sid) && !empty($userinfoData['sid'])) {
                $sso_sid = (string) $userinfoData['sid'];
            }
        } else {
            // 2B. Mode 2 (Fallback Legacy): Verifikasi Token Broker lama (/sso-verify)
            $options = array(
                'CURLOPT_HTTPHEADER' => array(
                    'Authorization: Bearer ' . $token,
                    'Accept: application/json'
                )
            );

            $data = array(
                'app_key'       => $app_key,
                'client_secret' => $client_secret,
                'scopes'        => $scopes
            );

            $response     = $curl->post($sso_url . '/sso-verify', $data, $options);
            $info         = $curl->get_info();
            $responseData = json_decode($response, true);

            if (($info['http_code'] ?? 0) != 200) {
                $errMsg = $responseData['message'] ?? 'Gagal memverifikasi token ke server SSO.';
                $SESSION->loginerrormsg = (stripos($errMsg, 'Gate SSO') === false ? 'Gate SSO: ' : '') . $errMsg;
                redirect(new moodle_url('/login/index.php'));
            }

            if (isset($responseData['success']) && $responseData['success'] == false) {
                $errMsg = $responseData['message'] ?? 'Akses ditolak oleh Gate SSO.';
                $SESSION->loginerrormsg = (stripos($errMsg, 'Gate SSO') === false ? 'Gate SSO: ' : '') . $errMsg;
                redirect(new moodle_url('/login/index.php'));
            }

            $userData             = $responseData['data'];
            $sso_sid              = $responseData['sso_sid'] ?? null;
            $oauth_id_token       = $responseData['id_token'] ?? $oauth_id_token;
            $sso_login_time       = (int) ($responseData['sso_login_time'] ?? time());
            $sso_login_history_id = $responseData['sso_login_history_id'] ?? null;
        }

        $sso_username = core_text::strtolower($userData['username']);

        // --- Cekal Keuangan (Opsional - Menggunakan API Cybercampus) ---
        // Aktif hanya jika $CFG->sso_gate_enable_cekal = true di config.php dan user tergabung di group mahasiswa
        if ($this->is_cekal_enabled()) {
            $is_mahasiswa = isset($userData['groups']) && (
                (is_array($userData['groups']) && in_array('mahasiswa', $userData['groups'])) ||
                ($userData['groups'] === 'mahasiswa')
            );

            if ($is_mahasiswa && $this->check_cekal_keuangan($sso_username)) {
                $this->update_sso_login_status($sso_login_history_id, 'failed');
                $SESSION->loginerrormsg = 'Gate SSO: Akun Anda sedang ditangguhkan karena masalah administrasi keuangan (Cekal Keuangan). Silakan hubungi bagian keuangan.';
                redirect(new moodle_url('/login/index.php'));
            }
        }

        // 6. Cegah konflik sesi jika user sudah login
        if (isloggedin() && !isguestuser()) {
            if ($USER->username === $sso_username) {
                // Simpan metadata sesi SSO untuk Single Logout (SLO)
                $SESSION->sso_user_id    = (int) ($userData['id'] ?? $userData['sub'] ?? 0);
                $SESSION->sso_login_time = $sso_login_time;
                $SESSION->sso_last_check = time();
                $SESSION->sso_sid        = $sso_sid;
                $SESSION->oauth_id_token = $oauth_id_token;
                // Akun sama, langsung masuk dashboard
                redirect(new moodle_url('/my/'));
            }
            // Akun beda, paksa logout dulu
            require_logout();
        }

        // 7. Cari User di Database Moodle
        $moodle_user = $DB->get_record('user', array('username' => $sso_username, 'deleted' => 0, 'mnethostid' => $CFG->mnet_localhost_id));

        if (!$moodle_user) {
            $this->update_sso_login_status($sso_login_history_id, 'failed');
            $SESSION->loginerrormsg = 'Gate SSO: Akun Anda belum terdaftar atau tidak memiliki akses pada sistem Moodle ini. Silakan hubungi admin helpdesk.';
            redirect(new moodle_url('/login/index.php'));
        }

        if (empty($userData['email_verified'])) {
            $this->update_sso_login_status($sso_login_history_id, 'failed');
            $SESSION->loginerrormsg = 'Gate SSO: Email SSO Anda belum terverifikasi.';
            redirect(new moodle_url('/login/index.php'));
        }

        // 8. Sinkronisasi Data Profil
        $update_data = new stdClass();
        $update_data->id = $moodle_user->id;
        $update_data->email = $userData['email_verified'];

        // Atur format pemetaan nama (default: 'fullname_username', opsi: 'split')
        $nameFormat = (string) ($CFG->sso_gate_name_format ?? 'fullname_username');
        if ($nameFormat === 'split') {
            $nameParts = explode(' ', trim((string) ($userData['fullname'] ?? '')), 2);
            $update_data->firstname = $nameParts[0] !== '' ? $nameParts[0] : $sso_username;
            $update_data->lastname  = (isset($nameParts[1]) && $nameParts[1] !== '') ? $nameParts[1] : $update_data->firstname;
        } else {
            // firstname ambil dari fullname penuh, lastname ambil dari username
            $update_data->firstname = $userData['fullname'];
            $update_data->lastname  = $sso_username;
        }

        // Simpan pembaruan ke DB
        $DB->update_record('user', $update_data);

        // Refresh objek user
        $moodle_user = $DB->get_record('user', array('id' => $moodle_user->id));

        // 9. Eksekusi Login Bypass
        complete_user_login($moodle_user);

        // Simpan metadata sesi SSO untuk Single Logout (SLO)
        $SESSION->sso_user_id    = (int) ($userData['id'] ?? $userData['sub'] ?? 0);
        $SESSION->sso_login_time = $sso_login_time;
        $SESSION->sso_last_check = time();
        $SESSION->sso_sid        = $sso_sid;
        $SESSION->oauth_id_token = $oauth_id_token;

        // Terapkan batasan concurrent login Moodle
        \core\session\manager::apply_concurrent_login_limit($moodle_user->id, session_id());

        // 10. Arahkan ke Dashboard
        redirect(new moodle_url('/my/'));
    }

    /**
     * Hook Single Logout (SLO) saat pengguna klik Logout di Moodle (/login/logout.php)
     */
    public function logoutpage_hook()
    {
        global $CFG, $SESSION, $redirect;

        $ssoUserId = (int) ($SESSION->sso_user_id ?? 0);
        $ssoSid    = trim((string) ($SESSION->sso_sid ?? ''));
        $idToken   = $SESSION->oauth_id_token ?? null;

        unset(
            $SESSION->sso_user_id,
            $SESSION->sso_login_time,
            $SESSION->sso_last_check,
            $SESSION->sso_sid,
            $SESSION->oauth_id_token
        );

        if ($ssoUserId <= 0) {
            return;
        }

        $sso_url       = rtrim((string) ($CFG->sso_gate_url ?? ''), '/');
        $app_key       = $CFG->sso_gate_app_key ?? '';
        $client_secret = $CFG->sso_gate_secret ?? '';

        if (empty($sso_url) || empty($app_key)) {
            return;
        }

        // Back-Channel Logout ke Gate SSO (/sso-logout)
        try {
            $curl = new curl(array('ignoresecurity' => true));
            $curl->setopt(array('CURLOPT_TIMEOUT' => 3));
            $curl->post($sso_url . '/sso-logout', array(
                'app_key'       => $app_key,
                'client_secret' => $client_secret,
                'user_id'       => $ssoUserId,
                'sid'           => $ssoSid,
            ));
        } catch (\Throwable $e) {
            // Abaikan error jaringan pada back-channel
        }

        // Front-Channel Redirect ke /oauth/logout agar cookie sesi di Gate SSO juga terhapus
        $loginUrl = (new moodle_url('/login/index.php'))->out(false);
        $params   = array(
            'client_id'                => $app_key,
            'post_logout_redirect_uri' => $loginUrl,
        );
        if (!empty($idToken)) {
            $params['id_token_hint'] = $idToken;
        }

        $redirect = $sso_url . '/oauth/logout?' . http_build_query($params);
    }

    /**
     * Helper untuk mengeksekusi HTTP Request di APICybercampus
     */
    private function perform_api_request($method, $url, $data = null)
    {
        $curl = new curl(array('ignoresecurity' => true));

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
            $rawToken = is_array($result) && isset($result['token']) ? $result['token'] : $result;
            return is_string($rawToken) ? trim($rawToken, " \t\n\r\0\x0B\"'") : $rawToken;
        }

        return null;
    }

    /**
     * Mengecek apakah fitur validasi Cekal Keuangan Cybercampus diaktifkan melalui config.php.
     * Default: false (nonaktif), sehingga plugin bersifat general untuk semua site Moodle.
     */
    private function is_cekal_enabled(): bool
    {
        global $CFG;

        return !empty($CFG->sso_gate_enable_cekal);
    }

    /**
     * Memeriksa status cekal keuangan ke API Cybercampus (opsional).
     *
     * @param string $username Username/NIM yang akan dicek
     * @return bool True jika dicekal, False jika aman atau fitur cekal dinonaktifkan
     */
    private function check_cekal_keuangan($username)
    {
        global $CFG;

        if (!$this->is_cekal_enabled()) {
            return false;
        }

        $urlAPI  = trim((string) ($CFG->cybercampus_api_url ?? ''));
        $userAPI = trim((string) ($CFG->cybercampus_api_user ?? ''));
        $keyAPI  = trim((string) ($CFG->cybercampus_api_key ?? ''));

        if (
            $urlAPI === '' || $userAPI === '' || $keyAPI === ''
            || $userAPI === 'ISI_USER_API_DISINI' || $keyAPI === 'ISI_KEY_API_DISINI'
        ) {
            return false;
        }

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
     * Gate SSO adalah autentikasi eksternal (OAuth 2.0), sehingga Moodle tidak menyimpan/meminta password lokal.
     */
    public function is_internal()
    {
        return false;
    }

    public function prevent_local_passwords()
    {
        return true;
    }

    public function is_synchronised_with_external()
    {
        return false;
    }

    public function can_change_password()
    {
        return false;
    }

    public function can_reset_password()
    {
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
