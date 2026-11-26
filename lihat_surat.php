<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$path_koneksi = file_exists(__DIR__ . '/koneksi.php') ? __DIR__ . '/koneksi.php' : __DIR__ . '/../koneksi.php';
$path_akses   = file_exists(__DIR__ . '/akses_surat.php') ? __DIR__ . '/akses_surat.php' : __DIR__ . '/../akses_surat.php';

include_once $path_koneksi;
require_once $path_akses;

$id_login    = (int)($_SESSION['user_id'] ?? $_SESSION['id_user'] ?? $_SESSION['id'] ?? (is_array($_SESSION['user'] ?? null) ? ($_SESSION['user']['id'] ?? 0) : 0));
$user_login  = trim((string)($_SESSION['username'] ?? (is_array($_SESSION['user'] ?? null) ? ($_SESSION['user']['username'] ?? '') : '')));
$email_login = trim((string)($_SESSION['email'] ?? $_SESSION['google_email'] ?? (is_array($_SESSION['user'] ?? null) ? ($_SESSION['user']['email'] ?? '') : '')));
$role_login  = ambil_role_aktif();

if ($role_login === '' && $id_login <= 0 && $user_login === '' && $email_login === '') {
    http_response_code(401);
    exit('Akses ditolak. Silakan login terlebih dahulu.');
}

$id_surat = (int)($_GET['id'] ?? 0);
if ($id_surat <= 0 || !isset($koneksi) || !($koneksi instanceof mysqli)) {
    http_response_code(400);
    exit('Permintaan tidak valid.');
}

$stmt = mysqli_prepare($koneksi, "SELECT * FROM `surat_masuk` WHERE `id` = ? LIMIT 1");
if (!$stmt) {
    http_response_code(500);
    exit('Gagal menyiapkan query.');
}
mysqli_stmt_bind_param($stmt, 'i', $id_surat);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$row = mysqli_fetch_assoc($res) ?: null;
mysqli_stmt_close($stmt);

if (!$row) {
    http_response_code(404);
    exit('Data surat tidak ditemukan.');
}

if (!boleh_lihat_isi_surat($row, $role_login, ambil_bidang_aktif(), $id_login, $email_login, $user_login)) {
    http_response_code(403);
    exit('Dokumen ini bersifat Rahasia dan Anda tidak memiliki hak akses untuk membukanya.');
}

$raw_file = trim((string)($row['file_surat'] ?? $row['lampiran'] ?? $row['berkas_surat'] ?? $row['dokumen_surat'] ?? ''));
if ($raw_file === '') {
    http_response_code(404);
    exit('Berkas surat tidak tersedia.');
}

$nama_file = basename(str_replace('\\', '/', $raw_file));
$kandidat_path = [
    __DIR__ . '/uploads/' . $nama_file,
    __DIR__ . '/../uploads/' . $nama_file,
];

$full_path = '';
foreach ($kandidat_path as $kp) {
    if (file_exists($kp) && is_file($kp)) {
        $full_path = $kp;
        break;
    }
}

if ($full_path === '') {
    http_response_code(404);
    exit('File fisik tidak ditemukan di server.');
}

$ext = strtolower(pathinfo($full_path, PATHINFO_EXTENSION));
$mime_map = [
    'pdf'  => 'application/pdf',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
];
$mime = $mime_map[$ext] ?? 'application/octet-stream';

header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . rawurlencode($nama_file) . '"');
header('Content-Length: ' . filesize($full_path));
header('X-Content-Type-Options: nosniff');
readfile($full_path);
exit();