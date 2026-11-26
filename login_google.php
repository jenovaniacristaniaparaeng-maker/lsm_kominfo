<?php
session_start();
include 'koneksi.php';

$tabel_akun = 'users';

// Kosongkan jika ingin mengizinkan semua akun Google aktif & terverifikasi,
// atau isi domain resmi bila ingin membatasi domain tertentu, contoh: ['gmail.com']
$domain_google_diizinkan = [];

function kolom_ada_lg($koneksi, string $tabel, string $kolom): bool {
    $t = mysqli_real_escape_string($koneksi, $tabel);
    $k = mysqli_real_escape_string($koneksi, $kolom);
    $res = mysqli_query($koneksi, "SHOW COLUMNS FROM `$t` LIKE '$k'");
    return ($res && mysqli_num_rows($res) > 0);
}

function curl_post_json(string $url, array $post_fields): ?array {
    if (!function_exists('curl_init')) {
        return null;
    }

    $ch = curl_init($url);
    $opts = [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($post_fields),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 25,
    ];
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);

    if ($resp === false && curl_errno($ch) === 60) {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        $resp = curl_exec($ch);
    }

    curl_close($ch);
    if (!is_string($resp) || $resp === '') {
        return null;
    }
    $decoded = json_decode($resp, true);
    return is_array($decoded) ? $decoded : null;
}

function curl_get_bearer_json(string $url, string $access_token): ?array {
    if (!function_exists('curl_init')) {
        return null;
    }

    $ch = curl_init($url);
    $opts = [
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $access_token],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 25,
    ];
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);

    if ($resp === false && curl_errno($ch) === 60) {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        $resp = curl_exec($ch);
    }

    curl_close($ch);
    if (!is_string($resp) || $resp === '') {
        return null;
    }
    $decoded = json_decode($resp, true);
    return is_array($decoded) ? $decoded : null;
}

function tolak_login_google(string $pesan): void {
    $_SESSION['login_error'] = $pesan;
    header("Location: login.php?noredirect=1");
    exit();
}

// Set these values in the hosting environment.
$google_client_id     = (string)(getenv('GOOGLE_CLIENT_ID') ?: '');
$google_client_secret = (string)(getenv('GOOGLE_CLIENT_SECRET') ?: '');
$google_redirect_uri  = (string)(getenv('GOOGLE_REDIRECT_URI') ?: 'http://localhost/LsmKominfo/login_google.php');

if (
    $google_client_id === '' || $google_client_id === 'ISI_CLIENT_ID_GOOGLE_ANDA' ||
    $google_client_secret === '' || $google_client_secret === 'ISI_CLIENT_SECRET_GOOGLE_ANDA'
) {
    tolak_login_google('Google OAuth belum dikonfigurasi di environment (GOOGLE_CLIENT_ID dan GOOGLE_CLIENT_SECRET).');
}

// Tahap 1: Arahkan ke halaman pemilihan akun Google
if (!isset($_GET['code'])) {
    $state = bin2hex(random_bytes(16));
    $_SESSION['google_oauth_state'] = $state;

    $auth_url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id'     => $google_client_id,
        'redirect_uri'  => $google_redirect_uri,
        'response_type' => 'code',
        'scope'         => 'openid email profile',
        'state'         => $state,
        'prompt'        => 'select_account',
    ]);

    header("Location: " . $auth_url);
    exit();
}

// Tahap 2: Validasi state & error callback dari Google
$state_session = (string)($_SESSION['google_oauth_state'] ?? '');
$state_query   = (string)($_GET['state'] ?? '');
unset($_SESSION['google_oauth_state']);

if ($state_session === '' || !hash_equals($state_session, $state_query)) {
    tolak_login_google('Validasi state Google OAuth tidak sesuai. Silakan coba lagi.');
}

if (!empty($_GET['error'])) {
    tolak_login_google('Login Google dibatalkan atau gagal: ' . (string)$_GET['error']);
}

// Tukar code menjadi access_token
$token_data   = curl_post_json('https://oauth2.googleapis.com/token', [
    'code'          => (string)$_GET['code'],
    'client_id'     => $google_client_id,
    'client_secret' => $google_client_secret,
    'redirect_uri'  => $google_redirect_uri,
    'grant_type'    => 'authorization_code',
]);
$access_token = (string)($token_data['access_token'] ?? '');

if ($access_token === '') {
    tolak_login_google('Gagal memperoleh token akses dari Google.');
}

// Ambil profil akun Google yang sedang login
$profil_google = curl_get_bearer_json('https://openidconnect.googleapis.com/v1/userinfo', $access_token);
$google_id     = trim((string)($profil_google['sub'] ?? ''));
$email         = strtolower(trim((string)($profil_google['email'] ?? '')));
$email_verified = filter_var($profil_google['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN);
$nama_google   = trim((string)($profil_google['name'] ?? ''));
$foto_google   = trim((string)($profil_google['picture'] ?? ''));

if ($email === '' || $google_id === '') {
    tolak_login_google('Data email atau ID akun Google tidak ditemukan.');
}

// Hanya izinkan akun Google aktif dan terverifikasi oleh Google
if (!$email_verified) {
    tolak_login_google('Login ditolak: hanya akun Google aktif dan terverifikasi yang diperbolehkan masuk.');
}

// Opsional: batasi domain Google resmi bila array $domain_google_diizinkan diisi
if (!empty($domain_google_diizinkan)) {
    $domain_email = strtolower((string)substr(strrchr($email, '@'), 1));
    $daftar_domain = array_map('strtolower', $domain_google_diizinkan);
    if (!in_array($domain_email, $daftar_domain, true)) {
        tolak_login_google('Login ditolak: akun Google yang digunakan bukan akun Google resmi yang diizinkan.');
    }
}

$tabel_bersih = preg_replace('/[^a-zA-Z0-9_]/', '', $tabel_akun) ?: 'users';
$ada_email    = kolom_ada_lg($koneksi, $tabel_bersih, 'email');
$ada_google   = kolom_ada_lg($koneksi, $tabel_bersih, 'google_id');
$ada_nama     = kolom_ada_lg($koneksi, $tabel_bersih, 'nama');
$ada_foto     = kolom_ada_lg($koneksi, $tabel_bersih, 'foto');
$ada_status   = kolom_ada_lg($koneksi, $tabel_bersih, 'status');

$kolom_select = ['id', 'nama', 'username', 'role'];
if ($ada_email) {
    $kolom_select[] = 'email';
}
if ($ada_status) {
    $kolom_select[] = 'status';
}

// Cari akun pengirim berdasarkan google_id atau email (akun sama -> akun sama; akun beda -> akun baru)
$akun = null;
$where_parts = [];
$params      = [];
$types       = '';

if ($ada_google && $google_id !== '') {
    $where_parts[] = "google_id = ?";
    $params[]      = $google_id;
    $types        .= 's';
}
if ($ada_email && $email !== '') {
    $where_parts[] = "LOWER(email) = LOWER(?)";
    $params[]      = $email;
    $types        .= 's';
}
if (empty($where_parts)) {
    $where_parts[] = "LOWER(username) = LOWER(?)";
    $params[]      = $email;
    $types        .= 's';
}

$sql_cek = "SELECT " . implode(', ', $kolom_select) . " FROM `$tabel_bersih` WHERE (" . implode(' OR ', $where_parts) . ") LIMIT 1";
$stmt = mysqli_prepare($koneksi, $sql_cek);
if ($stmt) {
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $res  = mysqli_stmt_get_result($stmt);
    $akun = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
}

$nama_bersih = ($nama_google !== '') ? $nama_google : (strstr($email, '@', true) ?: $email);

// Cek status akun di database bila tersedia
if ($akun && $ada_status) {
    $status_akun = strtolower(trim((string)($akun['status'] ?? 'aktif')));
    if (in_array($status_akun, ['nonaktif', 'tidak aktif', 'inactive', 'blokir', 'diblokir', 'banned', '0'], true)) {
        tolak_login_google('Akun Google ini sedang tidak aktif atau diblokir pada sistem.');
    }
}

// Buat akun pengirim baru jika akun Google belum pernah terdaftar
if (!$akun) {
    $dasar_user = preg_replace('/[^a-zA-Z0-9._-]/', '', explode('@', $email)[0] ?? 'pengirim');
    if ($dasar_user === '') {
        $dasar_user = 'pengirim';
    }
    $username_baru = substr($dasar_user, 0, 22) . '_' . substr(bin2hex(random_bytes(3)), 0, 6);
    $pass_acak     = password_hash(bin2hex(random_bytes(12)), PASSWORD_DEFAULT);

    $kolom_ins = ['nama', 'username', 'password', 'role'];
    $tanda_ins = ['?', '?', '?', "'pengirim'"];
    $tipe_ins  = 'sss';
    $nilai_ins = [$nama_bersih, $username_baru, $pass_acak];

    if ($ada_email) {
        $kolom_ins[] = 'email';
        $tanda_ins[] = '?';
        $tipe_ins   .= 's';
        $nilai_ins[] = $email;
    }
    if ($ada_google) {
        $kolom_ins[] = 'google_id';
        $tanda_ins[] = '?';
        $tipe_ins   .= 's';
        $nilai_ins[] = $google_id;
    }
    if ($ada_foto && $foto_google !== '') {
        $kolom_ins[] = 'foto';
        $tanda_ins[] = '?';
        $tipe_ins   .= 's';
        $nilai_ins[] = $foto_google;
    }
    if ($ada_status) {
        $kolom_ins[] = 'status';
        $tanda_ins[] = "'aktif'";
    }

    $sql_ins = "INSERT INTO `$tabel_bersih` (" . implode(', ', $kolom_ins) . ") VALUES (" . implode(', ', $tanda_ins) . ")";
    $stmt_ins = mysqli_prepare($koneksi, $sql_ins);
    if ($stmt_ins) {
        mysqli_stmt_bind_param($stmt_ins, $tipe_ins, ...$nilai_ins);
        if (mysqli_stmt_execute($stmt_ins)) {
            $akun = [
                'id'       => (int)mysqli_insert_id($koneksi),
                'nama'     => $nama_bersih,
                'username' => $username_baru,
                'email'    => $email,
                'role'     => 'pengirim',
            ];
        }
        mysqli_stmt_close($stmt_ins);
    }
} else {
    // Sinkronkan data diri terbaru dari akun Google ke akun yang sudah ada
    $set_parts = [];
    $upd_types = '';
    $upd_vals  = [];

    if ($ada_nama && $nama_bersih !== '') {
        $set_parts[] = "nama = ?";
        $upd_types  .= 's';
        $upd_vals[]  = $nama_bersih;
        $akun['nama'] = $nama_bersih;
    }
    if ($ada_email && $email !== '') {
        $set_parts[] = "email = ?";
        $upd_types  .= 's';
        $upd_vals[]  = $email;
    }
    if ($ada_google && $google_id !== '') {
        $set_parts[] = "google_id = ?";
        $upd_types  .= 's';
        $upd_vals[]  = $google_id;
    }
    if ($ada_foto && $foto_google !== '') {
        $set_parts[] = "foto = ?";
        $upd_types  .= 's';
        $upd_vals[]  = $foto_google;
    }

    if (!empty($set_parts)) {
        $id_akun = (int)$akun['id'];
        $upd_types .= 'i';
        $upd_vals[]  = $id_akun;
        $stmt_upd = mysqli_prepare($koneksi, "UPDATE `$tabel_bersih` SET " . implode(', ', $set_parts) . " WHERE id = ? LIMIT 1");
        if ($stmt_upd) {
            mysqli_stmt_bind_param($stmt_upd, $upd_types, ...$upd_vals);
            mysqli_stmt_execute($stmt_upd);
            mysqli_stmt_close($stmt_upd);
        }
    }
}

if (!$akun) {
    tolak_login_google('Gagal memuat atau membuat akun pengirim untuk akun Google ini.');
}

$role_akun = strtolower(trim((string)($akun['role'] ?? 'pengirim')));
if (in_array($role_akun, ['kepala_dinas', 'sekretariat', 'sekretaris', 'kabid'], true)) {
    tolak_login_google('Akun pegawai internal wajib login menggunakan username dan kata sandi internal.');
}

// Perbarui sesi sesuai akun pengirim & data diri Google yang aktif
session_regenerate_id(true);
$_SESSION['user_id']      = (int)$akun['id'];
$_SESSION['id']           = (int)$akun['id'];
$_SESSION['nama']         = (string)($akun['nama'] ?? $nama_bersih);
$_SESSION['google_name']  = $nama_bersih;
$_SESSION['username']     = (string)$akun['username'];
$_SESSION['email']        = $email;
$_SESSION['google_email'] = $email;
$_SESSION['google_id']    = $google_id;
$_SESSION['foto_profil']  = $foto_google;
$_SESSION['role']         = 'pengirim';
$_SESSION['level']        = 'pengirim';
$_SESSION['login_tipe']   = 'pengirim';
$_SESSION['login_method'] = 'google';

header("Location: pengirim/dashboard.php");
exit();