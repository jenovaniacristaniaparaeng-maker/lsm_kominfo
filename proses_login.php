<?php
session_start();
include 'koneksi.php';

$tabel_akun = 'users';

function cocokkan_sandi_akun(string $input_pass, string $db_pass): bool {
    if ($db_pass === '') {
        return false;
    }
    $info = password_get_info($db_pass);
    if (!empty($info['algo'])) {
        return password_verify($input_pass, $db_pass);
    }
    return hash_equals($db_pass, $input_pass);
}

function kolom_ada_pl(mysqli $koneksi, string $tabel, string $kolom): bool {
    $t = mysqli_real_escape_string($koneksi, $tabel);
    $k = mysqli_real_escape_string($koneksi, $kolom);
    $res = mysqli_query($koneksi, "SHOW COLUMNS FROM `$t` LIKE '$k'");
    return ($res && mysqli_num_rows($res) > 0);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: login.php");
    exit();
}

$akun_input     = trim((string)($_POST['username'] ?? ''));
$password_input = (string)($_POST['password'] ?? '');

if ($akun_input === '' || $password_input === '') {
    $_SESSION['login_error'] = 'Username atau email dan kata sandi wajib diisi.';
    header("Location: login.php");
    exit();
}

$tabel_bersih = preg_replace('/[^a-zA-Z0-9_]/', '', $tabel_akun) ?: 'users';
$ada_email    = kolom_ada_pl($koneksi, $tabel_bersih, 'email');
$ada_nip      = kolom_ada_pl($koneksi, $tabel_bersih, 'nip');

$kolom_select = ['id', 'nama', 'username', 'password', 'role'];
if ($ada_email) {
    $kolom_select[] = 'email';
}
if ($ada_nip) {
    $kolom_select[] = 'nip';
}

$kondisi_where = ["LOWER(TRIM(username)) = LOWER(?)"];
$types_bind    = 's';
$params_bind   = [$akun_input];

if ($ada_email) {
    $kondisi_where[] = "LOWER(TRIM(email)) = LOWER(?)";
    $types_bind     .= 's';
    $params_bind[]   = $akun_input;
}

$sql_select = "SELECT " . implode(', ', $kolom_select) . "
               FROM `$tabel_bersih`
               WHERE (" . implode(' OR ', $kondisi_where) . ")
               LIMIT 1";

$stmt = mysqli_prepare($koneksi, $sql_select);

if (!$stmt) {
    $_SESSION['login_error'] = 'Terjadi kesalahan pada query login.';
    header("Location: login.php");
    exit();
}

mysqli_stmt_bind_param($stmt, $types_bind, ...$params_bind);
mysqli_stmt_execute($stmt);
$res  = mysqli_stmt_get_result($stmt);
$akun = $res ? mysqli_fetch_assoc($res) : null;
mysqli_stmt_close($stmt);

if (!$akun) {
    $_SESSION['login_error'] = 'Akun tidak ditemukan. Masukkan username atau email yang terdaftar (NIP dan ID pengguna tidak berlaku).';
    header("Location: login.php");
    exit();
}

if (!cocokkan_sandi_akun($password_input, (string)($akun['password'] ?? ''))) {
    $_SESSION['login_error'] = 'Username/email atau kata sandi tidak valid.';
    header("Location: login.php");
    exit();
}

$role_db     = strtolower(str_replace([' ', '-'], '_', trim((string)($akun['role'] ?? ''))));
$username_db = strtolower(trim((string)($akun['username'] ?? '')));

session_regenerate_id(true);
$_SESSION['user_id']  = (int)$akun['id'];
$_SESSION['nama']     = (string)$akun['nama'];
$_SESSION['nip']      = (string)($akun['nip'] ?? '');
$_SESSION['username'] = (string)$akun['username'];
if ($ada_email) {
    $_SESSION['email'] = (string)($akun['email'] ?? '');
}
$_SESSION['role']     = $role_db;
$_SESSION['level']    = $role_db;

if (in_array($role_db, ['admin', 'administrator', 'superadmin'], true) || $username_db === 'admin') {
    header("Location: admin/dashboard.php");
    exit();
}
if (in_array($role_db, ['kepala_dinas', 'kepala dinas', 'kadis'], true)) {
    header("Location: kadis/dashboard.php");
    exit();
}
if ($role_db === 'sekretariat') {
    header("Location: sekretariat/dashboard.php");
    exit();
}
if ($role_db === 'sekretaris') {
    header("Location: sekretaris/dashboard.php");
    exit();
}
if ($role_db === 'kabid') {
    $map_bidang = [
        'kabid01' => 'kabid/bidang1.php?menu=dashboard',
        'bidang1' => 'kabid/bidang1.php?menu=dashboard',
        'kabid02' => 'kabid/bidang2.php?menu=dashboard',
        'bidang2' => 'kabid/bidang2.php?menu=dashboard',
        'kabid03' => 'kabid/bidang3.php?menu=dashboard',
        'bidang3' => 'kabid/bidang3.php?menu=dashboard',
        'kabid04' => 'kabid/bidang4.php?menu=dashboard',
        'bidang4' => 'kabid/bidang4.php?menu=dashboard',
    ];
    $tujuan_kabid = $map_bidang[$username_db] ?? 'kabid/bidang1.php?menu=dashboard';
    header("Location: " . $tujuan_kabid);
    exit();
}
if (in_array($role_db, ['pengirim', 'pengirim_surat', 'pengirim surat', 'user', 'publik'], true)) {
    header("Location: pengirim/dashboard.php");
    exit();
}

session_unset();
session_destroy();
session_start();
$_SESSION['login_error'] = 'Role akun tidak dikenali.';
header("Location: login.php");
exit();