<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Mengecek secara berkala (throttled tiap 60 detik) apakah sesi pengguna yang login via Gate SSO
 * masih aktif di server Gate SSO (/sso-check-session).
 * Apabila pengguna telah logout dari Gate SSO atau aplikasi klien lain, sesi Moodle otomatis diakhiri.
 */
function auth_gate_check_sso_session(): void
{
    global $CFG, $SESSION, $SCRIPT;

    if (defined('CLI_SCRIPT') && CLI_SCRIPT) {
        return;
    }
    if (defined('AJAX_SCRIPT') && AJAX_SCRIPT) {
        return;
    }
    if (!function_exists('isloggedin') || !isloggedin() || isguestuser()) {
        return;
    }

    // Jangan jalankan pada halaman login atau logout untuk mencegah redirect loop
    $currentScript = (string) ($SCRIPT ?? ($_SERVER['SCRIPT_NAME'] ?? ''));
    if (strpos($currentScript, '/login/') !== false) {
        return;
    }

    $ssoUserId    = (int) ($SESSION->sso_user_id ?? 0);
    $ssoLoginTime = (int) ($SESSION->sso_login_time ?? 0);
    $ssoSid       = trim((string) ($SESSION->sso_sid ?? ''));

    if ($ssoUserId <= 0 || $ssoLoginTime <= 0) {
        return;
    }

    $lastCheck = (int) ($SESSION->sso_last_check ?? 0);
    if ((time() - $lastCheck) < 60) {
        return;
    }

    $SESSION->sso_last_check = time();

    $sso_url       = rtrim((string) ($CFG->sso_gate_url ?? ''), '/');
    $app_key       = $CFG->sso_gate_app_key ?? '';
    $client_secret = $CFG->sso_gate_secret ?? '';

    if (empty($sso_url) || empty($app_key) || empty($client_secret)) {
        return;
    }

    try {
        require_once($CFG->libdir . '/filelib.php');
        $curl = new curl(array('ignoresecurity' => true));
        $curl->setopt(array('CURLOPT_TIMEOUT' => 3));

        $response = $curl->post($sso_url . '/sso-check-session', array(
            'app_key'           => $app_key,
            'client_secret'     => $client_secret,
            'user_id'           => $ssoUserId,
            'client_login_time' => $ssoLoginTime,
            'sid'               => $ssoSid,
        ));
        $info     = $curl->get_info();
        $httpCode = (int) ($info['http_code'] ?? 0);

        if (($httpCode === 200 || $httpCode === 404) && !empty($response)) {
            $result = json_decode($response, true);
            if (isset($result['active']) && $result['active'] === false) {
                unset(
                    $SESSION->sso_user_id,
                    $SESSION->sso_login_time,
                    $SESSION->sso_last_check,
                    $SESSION->sso_sid,
                    $SESSION->oauth_id_token
                );
                require_logout();
                $SESSION->loginerrormsg = 'Sesi Gate SSO Anda telah berakhir.';
                redirect(new moodle_url('/login/index.php'));
            }
        }
    } catch (\Throwable $e) {
        // Abaikan jika Gate SSO sedang tidak dapat dihubungi agar sesi lokal tidak terputus
    }
}

/**
 * Hook standar Moodle yang dipanggil setelah konfigurasi dan sesi dimuat
 */
function auth_gate_after_config(): void
{
    auth_gate_check_sso_session();
}

/**
 * Fallback hook sebelum HTTP header dikirimkan
 */
function auth_gate_before_http_headers(): void
{
    auth_gate_check_sso_session();
}
