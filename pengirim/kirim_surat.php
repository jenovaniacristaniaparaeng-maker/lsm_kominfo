<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$path_koneksi = file_exists(__DIR__ . '/../koneksi.php') ? __DIR__ . '/../koneksi.php' : __DIR__ . '/koneksi.php';
include_once $path_koneksi;

$ada_sesi = isset($_SESSION['user'])
    || isset($_SESSION['id_user'])
    || isset($_SESSION['user_id'])
    || isset($_SESSION['id'])
    || isset($_SESSION['username'])
    || isset($_SESSION['email'])
    || isset($_SESSION['google_email']);

if (!$ada_sesi) {
    $path_login = file_exists(__DIR__ . '/login.php') ? 'login.php' : '../login.php';
    header("Location: " . $path_login);
    exit();
}

function kolom_ada_pg_ks(mysqli $koneksi, string $tabel, string $kolom): bool {
    $t = mysqli_real_escape_string($koneksi, $tabel);
    $k = mysqli_real_escape_string($koneksi, $kolom);
    $res = mysqli_query($koneksi, "SHOW COLUMNS FROM `$t` LIKE '$k'");
    return ($res && mysqli_num_rows($res) > 0);
}

function tabel_ada_pg_ks(mysqli $koneksi, string $tabel): bool {
    $t = mysqli_real_escape_string($koneksi, $tabel);
    $res = mysqli_query($koneksi, "SHOW TABLES LIKE '$t'");
    return ($res && mysqli_num_rows($res) > 0);
}

function deteksi_pk_pg_ks(mysqli $koneksi, string $tabel): string {
    $t = mysqli_real_escape_string($koneksi, $tabel);
    $res = mysqli_query($koneksi, "SHOW KEYS FROM `$t` WHERE Key_name = 'PRIMARY'");
    if ($res && ($row = mysqli_fetch_assoc($res)) && !empty($row['Column_name'])) {
        return (string)$row['Column_name'];
    }
    return 'id';
}

function cari_kolom_pg_ks(mysqli $koneksi, string $tabel, array $kandidat): string {
    foreach ($kandidat as $kolom) {
        if (kolom_ada_pg_ks($koneksi, $tabel, $kolom)) {
            return $kolom;
        }
    }
    return '';
}

function kirim_otp_profil_pg_ks(string $email_tujuan, string $kode_otp, string $nama_user): array {
    if (function_exists('kirim_otp_smtp')) {
        $detail = '';
        $ok = kirim_otp_smtp($email_tujuan, $kode_otp, $nama_user !== '' ? $nama_user : 'Pengirim', $detail);
        return [
            'terkirim' => (bool)$ok,
            'error'    => $ok ? '' : $detail,
        ];
    }

    $subjek = 'Kode Persetujuan Pembaruan Profil Pengirim - Portal E-Office Diskominfo Sangihe';
    $isi = "Halo " . ($nama_user !== '' ? $nama_user : 'Pengirim') . ",\n\n"
        . "Kode persetujuan untuk memperbarui data profil dan/atau keamanan akun Pengirim:\n"
        . "KODE OTP: " . $kode_otp . "\n\n"
        . "Kode berlaku selama 10 menit.\n\n"
        . "Dinas Komunikasi dan Informatika Daerah Kabupaten Kepulauan Sangihe";

    $headers = "From: Portal E-Office Diskominfo Sangihe <no-reply@diskominfo-sangihe.go.id>\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n";
    $ok = @mail($email_tujuan, $subjek, $isi, $headers);
    return [
        'terkirim' => (bool)$ok,
        'error'    => $ok ? '' : 'Fungsi pengiriman email tidak tersedia atau gagal.',
    ];
}

$id_login    = (int)($_SESSION['user_id'] ?? $_SESSION['id_user'] ?? $_SESSION['id'] ?? (is_array($_SESSION['user'] ?? null) ? ($_SESSION['user']['id'] ?? 0) : 0));
$user_login  = trim((string)($_SESSION['username'] ?? (is_array($_SESSION['user'] ?? null) ? ($_SESSION['user']['username'] ?? '') : '')));
$email_login = trim((string)($_SESSION['email'] ?? $_SESSION['google_email'] ?? (is_array($_SESSION['user'] ?? null) ? ($_SESSION['user']['email'] ?? '') : '')));

$nama_tampil = '';
if (!empty($_SESSION['google_name'])) {
    $nama_tampil = trim((string)$_SESSION['google_name']);
} elseif (is_array($_SESSION['user'] ?? null) && !empty($_SESSION['user']['name'])) {
    $nama_tampil = trim((string)$_SESSION['user']['name']);
} elseif (is_array($_SESSION['user'] ?? null) && !empty($_SESSION['user']['nama'])) {
    $nama_tampil = trim((string)$_SESSION['user']['nama']);
} elseif (!empty($_SESSION['nama'])) {
    $nama_tampil = trim((string)$_SESSION['nama']);
} elseif (!empty($_SESSION['name'])) {
    $nama_tampil = trim((string)$_SESSION['name']);
} elseif ($user_login !== '') {
    $nama_tampil = $user_login;
}

$ada_tabel_users = (isset($koneksi) && $koneksi instanceof mysqli) ? tabel_ada_pg_ks($koneksi, 'users') : false;
$pk_users        = $ada_tabel_users ? deteksi_pk_pg_ks($koneksi, 'users') : 'id';
$kol_u_nama      = $ada_tabel_users ? cari_kolom_pg_ks($koneksi, 'users', ['nama', 'nama_lengkap', 'full_name']) : '';
$kol_u_email     = $ada_tabel_users ? cari_kolom_pg_ks($koneksi, 'users', ['email']) : '';
$kol_u_username  = $ada_tabel_users ? cari_kolom_pg_ks($koneksi, 'users', ['username', 'user']) : '';
$kol_u_password  = $ada_tabel_users ? cari_kolom_pg_ks($koneksi, 'users', ['password', 'sandi', 'kata_sandi']) : '';

$data_akun_ks = [];
if ($ada_tabel_users) {
    $pk_u_esc = mysqli_real_escape_string($koneksi, $pk_users);
    if ($id_login > 0) {
        $stmt_u = mysqli_prepare($koneksi, "SELECT * FROM `users` WHERE `$pk_u_esc` = ? LIMIT 1");
        if ($stmt_u) {
            mysqli_stmt_bind_param($stmt_u, 'i', $id_login);
            mysqli_stmt_execute($stmt_u);
            $res_u = mysqli_stmt_get_result($stmt_u);
            $data_akun_ks = mysqli_fetch_assoc($res_u) ?: [];
            mysqli_stmt_close($stmt_u);
        }
    }
    if (empty($data_akun_ks) && $kol_u_email !== '' && $email_login !== '') {
        $stmt_u = mysqli_prepare($koneksi, "SELECT * FROM `users` WHERE LOWER(TRIM(`$kol_u_email`)) = LOWER(?) LIMIT 1");
        if ($stmt_u) {
            mysqli_stmt_bind_param($stmt_u, 's', $email_login);
            mysqli_stmt_execute($stmt_u);
            $res_u = mysqli_stmt_get_result($stmt_u);
            $data_akun_ks = mysqli_fetch_assoc($res_u) ?: [];
            mysqli_stmt_close($stmt_u);
        }
    }
    if (empty($data_akun_ks) && $kol_u_username !== '' && $user_login !== '') {
        $stmt_u = mysqli_prepare($koneksi, "SELECT * FROM `users` WHERE LOWER(TRIM(`$kol_u_username`)) = LOWER(?) LIMIT 1");
        if ($stmt_u) {
            mysqli_stmt_bind_param($stmt_u, 's', $user_login);
            mysqli_stmt_execute($stmt_u);
            $res_u = mysqli_stmt_get_result($stmt_u);
            $data_akun_ks = mysqli_fetch_assoc($res_u) ?: [];
            mysqli_stmt_close($stmt_u);
        }
    }

    if (!empty($data_akun_ks)) {
        if ($id_login === 0 && !empty($data_akun_ks[$pk_users])) {
            $id_login = (int)$data_akun_ks[$pk_users];
        }
        if ($kol_u_nama !== '' && !empty($data_akun_ks[$kol_u_nama])) {
            $nama_tampil = trim((string)$data_akun_ks[$kol_u_nama]);
        } elseif (!empty($data_akun_ks['name'])) {
            $nama_tampil = trim((string)$data_akun_ks['name']);
        }
        if ($kol_u_username !== '' && !empty($data_akun_ks[$kol_u_username])) {
            $user_login = trim((string)$data_akun_ks[$kol_u_username]);
        }
        if ($kol_u_email !== '' && !empty($data_akun_ks[$kol_u_email])) {
            $email_login = trim((string)$data_akun_ks[$kol_u_email]);
        }
    }
}

$nama_label = $nama_tampil !== '' ? $nama_tampil : ($user_login !== '' ? $user_login : 'Pengirim');

$menu_ks = strtolower(trim((string)($_GET['menu'] ?? 'kirim')));
if (!in_array($menu_ks, ['kirim', 'profil'], true)) {
    $menu_ks = 'kirim';
}

$pesan_profil_sukses = '';
$pesan_profil_error  = '';
$draft_profil_ks     = $_SESSION['draft_profil_ks'] ?? [];
$otp_aktif_ks        = $_SESSION['otp_profil_ks'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['kirim_kode_profil_ks'])) {
    $menu_ks       = 'profil';
    $nama_baru     = trim((string)($_POST['nama_profil'] ?? ''));
    $email_baru    = trim((string)($_POST['email_profil'] ?? ''));
    $username_baru = trim((string)($_POST['username_profil'] ?? ''));
    $sandi_baru    = (string)($_POST['sandi_baru_profil'] ?? '');
    $sandi_konf    = (string)($_POST['sandi_konfirmasi_profil'] ?? '');

    if ($nama_baru === '') {
        $pesan_profil_error = 'Nama lengkap / nama pengirim wajib diisi.';
    } elseif ($kol_u_username !== '' && $username_baru === '') {
        $pesan_profil_error = 'Username login wajib diisi.';
    } elseif ($email_baru === '' || !filter_var($email_baru, FILTER_VALIDATE_EMAIL)) {
        $pesan_profil_error = 'Masukkan alamat email aktif yang valid untuk menerima kode persetujuan.';
    } elseif ($sandi_baru !== '' && strlen($sandi_baru) < 6) {
        $pesan_profil_error = 'Kata sandi baru minimal 6 karakter.';
    } elseif ($sandi_baru !== '' && $sandi_baru !== $sandi_konf) {
        $pesan_profil_error = 'Konfirmasi kata sandi baru tidak sama.';
    } else {
        $kode_otp = (string)random_int(100000, 999999);
        $draft_baru = [
            'nama'          => $nama_baru,
            'email'         => $email_baru,
            'username'      => $username_baru,
            'password_hash' => ($sandi_baru !== '') ? password_hash($sandi_baru, PASSWORD_DEFAULT) : '',
        ];
        $otp_baru = [
            'email'      => $email_baru,
            'kode'       => $kode_otp,
            'expires_at' => time() + 600,
        ];

        $_SESSION['draft_profil_ks'] = $draft_baru;
        $_SESSION['otp_profil_ks']   = $otp_baru;
        $draft_profil_ks = $draft_baru;
        $otp_aktif_ks    = $otp_baru;

        $hasil_kirim = kirim_otp_profil_pg_ks($email_baru, $kode_otp, $nama_baru);
        if ($hasil_kirim['terkirim']) {
            $pesan_profil_sukses = 'Kode persetujuan telah dikirim ke email aktif ' . $email_baru . '. Silakan cek Inbox atau folder Spam, lalu masukkan kodenya.';
        } else {
            $detail_err = trim((string)($hasil_kirim['error'] ?? ''));
            $pesan_profil_error  = 'Gagal mengirim kode ke email ' . $email_baru . ($detail_err !== '' ? ' (' . $detail_err . ')' : '') . '.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['batal_kode_profil_ks'])) {
    $menu_ks = 'profil';
    unset($_SESSION['draft_profil_ks'], $_SESSION['otp_profil_ks']);
    $draft_profil_ks     = [];
    $otp_aktif_ks        = null;
    $pesan_profil_sukses = 'Permintaan verifikasi email dibatalkan.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['simpan_profil_ks'])) {
    $menu_ks    = 'profil';
    $kode_input = trim((string)($_POST['kode_persetujuan_email'] ?? ''));

    if (!is_array($otp_aktif_ks) || empty($draft_profil_ks)) {
        $pesan_profil_error = 'Pembaruan profil membutuhkan persetujuan email aktif. Isi email aktif lalu klik "Kirim Kode ke Email" terlebih dahulu.';
    } elseif ($kode_input === '') {
        $pesan_profil_error = 'Masukkan kode persetujuan yang dikirim ke email aktif Anda.';
    } elseif (time() > (int)($otp_aktif_ks['expires_at'] ?? 0)) {
        $pesan_profil_error = 'Kode persetujuan sudah kedaluwarsa. Klik "Kirim Kode ke Email" untuk meminta kode baru.';
    } elseif (!hash_equals((string)($otp_aktif_ks['kode'] ?? ''), $kode_input)) {
        $pesan_profil_error = 'Kode persetujuan email tidak sesuai. Periksa kembali kode di email aktif Anda.';
    } elseif (!isset($koneksi) || !($koneksi instanceof mysqli) || !$ada_tabel_users || empty($data_akun_ks)) {
        $pesan_profil_error = 'Data akun pengguna pada tabel users tidak ditemukan.';
    } else {
        $nama_baru     = (string)($draft_profil_ks['nama'] ?? '');
        $email_baru    = (string)($draft_profil_ks['email'] ?? '');
        $username_baru = (string)($draft_profil_ks['username'] ?? '');
        $hash_baru     = (string)($draft_profil_ks['password_hash'] ?? '');

        $id_target_u = (int)($data_akun_ks[$pk_users] ?? $id_login);
        $set_u = [];

        if ($kol_u_nama !== '') {
            $set_u[] = "`$kol_u_nama` = '" . mysqli_real_escape_string($koneksi, $nama_baru) . "'";
        }
        if ($kol_u_email !== '' && $email_baru !== '') {
            $set_u[] = "`$kol_u_email` = '" . mysqli_real_escape_string($koneksi, $email_baru) . "'";
        }
        if ($kol_u_username !== '' && $username_baru !== '') {
            $set_u[] = "`$kol_u_username` = '" . mysqli_real_escape_string($koneksi, $username_baru) . "'";
        }
        if ($kol_u_password !== '' && $hash_baru !== '') {
            $set_u[] = "`$kol_u_password` = '" . mysqli_real_escape_string($koneksi, $hash_baru) . "'";
        }

        if (empty($set_u) || $id_target_u <= 0) {
            $pesan_profil_error = 'Tidak ada kolom profil yang dapat diperbarui.';
        } else {
            $pk_u_esc = mysqli_real_escape_string($koneksi, $pk_users);
            $sql_u = "UPDATE `users` SET " . implode(', ', $set_u) . " WHERE `$pk_u_esc` = $id_target_u LIMIT 1";
            if (mysqli_query($koneksi, $sql_u)) {
                $_SESSION['nama'] = $nama_baru;
                if ($username_baru !== '') {
                    $_SESSION['username'] = $username_baru;
                    $user_login = $username_baru;
                }
                if ($email_baru !== '') {
                    $_SESSION['email'] = $email_baru;
                    $email_login = $email_baru;
                }
                $nama_tampil = $nama_baru;
                $nama_label  = $nama_baru;

                unset($_SESSION['draft_profil_ks'], $_SESSION['otp_profil_ks']);
                $draft_profil_ks = [];
                $otp_aktif_ks    = null;

                $res_u_fresh = mysqli_query($koneksi, "SELECT * FROM `users` WHERE `$pk_u_esc` = $id_target_u LIMIT 1");
                if ($res_u_fresh && ($r_fresh = mysqli_fetch_assoc($res_u_fresh))) {
                    $data_akun_ks = $r_fresh;
                    if ($kol_u_nama !== '' && !empty($data_akun_ks[$kol_u_nama])) {
                        $nama_tampil = trim((string)$data_akun_ks[$kol_u_nama]);
                        $nama_label  = $nama_tampil;
                    }
                    if ($kol_u_email !== '' && isset($data_akun_ks[$kol_u_email])) {
                        $email_login = trim((string)$data_akun_ks[$kol_u_email]);
                    }
                    if ($kol_u_username !== '' && isset($data_akun_ks[$kol_u_username])) {
                        $user_login = trim((string)$data_akun_ks[$kol_u_username]);
                    }
                }

                $pesan_profil_sukses = 'Persetujuan email berhasil diverifikasi. Profil dan keamanan akun Pengirim telah diperbarui.' . ($hash_baru !== '' ? ' Kata sandi baru juga aktif.' : '');
            } else {
                $pesan_profil_error = 'Gagal menyimpan perubahan profil: ' . mysqli_error($koneksi);
            }
        }
    }
}

$val_nama_form     = (string)($draft_profil_ks['nama'] ?? $nama_label);
$val_username_form = (string)($draft_profil_ks['username'] ?? $user_login);
$val_email_form    = (string)($draft_profil_ks['email'] ?? $email_login);
$menunggu_otp_ks   = is_array($otp_aktif_ks) && !empty($draft_profil_ks);

function cari_logo_sangihe_ks_asli(): string {
    $base_dir = __DIR__;
    $root_dir = dirname(__DIR__);
    foreach ([
        'logo_sangihe.png', 'logo_sangihe.jpg', 'logo_sangihe.jpeg', 'sangihe.png',
        '../logo_sangihe.png', '../logo_sangihe.jpg', '../logo_sangihe.jpeg', '../sangihe.png',
        'assets/logo_sangihe.png', '../assets/logo_sangihe.png',
        'assets/img/logo_sangihe.png', '../assets/img/logo_sangihe.png',
        '../assets/images/logo_sangihe.png', '../img/logo_sangihe.png',
        '../images/logo_sangihe.png', '../logo/logo_sangihe.png',
        '../assets/logo.png', '../img/logo.png', '../uploads/logo_sangihe.png',
    ] as $rel) {
        if (file_exists($base_dir . '/' . $rel) && is_file($base_dir . '/' . $rel)) {
            return $rel;
        }
    }
    foreach ([$base_dir, $base_dir . '/assets/img', $root_dir, $root_dir . '/assets', $root_dir . '/assets/img', $root_dir . '/img'] as $dir) {
        if (!is_dir($dir)) continue;
        $files = @scandir($dir);
        if (!is_array($files)) continue;
        foreach ($files as $f) {
            if ($f === '.' || $f === '..') continue;
            $full = $dir . '/' . $f;
            if (!is_file($full)) continue;
            $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
            if (!in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'gif', 'svg'], true)) continue;
            $fn = strtolower($f);
            if (str_contains($fn, 'sangihe') || str_contains($fn, 'logo') || str_contains($fn, 'kominfo')) {
                $norm_full = str_replace('\\', '/', $full);
                $norm_base = str_replace('\\', '/', $base_dir);
                return str_starts_with($norm_full, $norm_base . '/')
                    ? substr($norm_full, strlen($norm_base) + 1)
                    : '../' . ltrim(str_replace(str_replace('\\', '/', $root_dir), '', $norm_full), '/');
            }
        }
    }
    return '';
}

$url_logo = cari_logo_sangihe_ks_asli();

$link_dashboard     = 'dashboard.php';
$link_kirim_surat   = 'kirim_surat.php';
$link_riwayat_surat = 'riwayat_surat.php';
$link_lacak_surat   = 'lacak_surat.php';
$link_arsip_surat   = 'arsip_surat.php';
$link_profil_surat  = 'kirim_surat.php?menu=profil';
$link_logout        = file_exists(__DIR__ . '/logout.php') ? 'logout.php' : '../logout.php';
$judul_halaman_ks   = $menu_ks === 'profil' ? 'Pengaturan Profil - E-OFFICE' : 'Kirim Surat Baru - E-OFFICE';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($judul_halaman_ks, ENT_QUOTES, 'UTF-8'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            background-color: #eef2f7;
            color: #1e293b;
            font-family: 'Inter', sans-serif;
            margin: 0;
        }
        .sidebar-pg {
            width: 260px;
            min-height: 100vh;
            background-color: #ffffff;
            color: #0f172a;
            border-right: 1px solid #e2e8f0;
            position: fixed;
            top: 0;
            left: 0;
            padding: 20px 16px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            z-index: 100;
        }
        .brand-logo-box {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            padding: 6px 6px 14px;
            margin-bottom: 10px;
        }
        .brand-logo-box img {
            width: 100%;
            max-width: 224px;
            height: auto;
            max-height: 108px;
            object-fit: contain;
            display: block;
            background-color: transparent;
        }
        .sidebar-pg .nav-link {
            color: #334155;
            border-radius: 10px;
            padding: 11px 16px;
            margin-bottom: 6px;
            font-size: 0.95rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
        }
        .sidebar-pg .nav-link:hover {
            background-color: #f1f5f9;
            color: #0f172a;
        }
        .sidebar-pg .nav-link.active {
            background-color: #3b6998;
            color: #ffffff;
        }
        .main-pg {
            margin-left: 260px;
            padding: 28px 32px;
            background-color: #eef2f7;
            min-height: 100vh;
        }
        .header-banner-ks {
            background-color: #3b6998;
            border: 1px solid #2f5780;
            color: #ffffff;
            border-radius: 14px;
            padding: 20px 26px;
            margin-bottom: 20px;
            box-shadow: 0 3px 10px rgba(30, 58, 95, 0.14);
        }
        .badge-pengirim-ks {
            background-color: #ffffff;
            color: #0f172a;
            border-radius: 999px;
            padding: 7px 14px;
            font-size: 0.82rem;
            font-weight: 700;
            letter-spacing: 0.03em;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .card-panel-pg {
            background-color: #4372a1;
            border: 1px solid #355f8a;
            border-radius: 14px;
            padding: 24px;
            box-shadow: 0 3px 10px rgba(30, 58, 95, 0.14);
            margin-bottom: 20px;
            color: #ffffff;
        }
        .card-panel-pg .text-muted {
            color: #e2e8f0 !important;
        }
        .card-panel-pg .form-control,
        .card-panel-pg .form-select {
            background-color: #ffffff;
            color: #0f172a;
            border-color: #cbd5e1;
        }
        .kartu-profil-putih-ks {
            background-color: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 24px;
            box-shadow: 0 3px 10px rgba(15, 23, 42, 0.06);
            margin-bottom: 20px;
            color: #1e293b;
        }
        .avatar-profil-ks {
            width: 64px;
            height: 64px;
            border-radius: 16px;
            background-color: #e8f0fe;
            color: #1a73e8;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            flex-shrink: 0;
        }
        .sandi-wrapper-ks {
            position: relative;
        }
        .sandi-wrapper-ks .form-control {
            padding-right: 42px;
        }
        .btn-toggle-sandi-ks {
            position: absolute;
            top: 50%;
            right: 8px;
            transform: translateY(-50%);
            background: transparent;
            border: none;
            color: #475569;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 30px;
            height: 30px;
            padding: 0;
            cursor: pointer;
            border-radius: 6px;
        }
        .btn-toggle-sandi-ks:hover {
            color: #0d6efd;
            background-color: rgba(13, 110, 253, 0.08);
        }
        @media (max-width: 768px) {
            .sidebar-pg {
                position: relative;
                width: 100%;
                min-height: auto;
                border-right: none;
                border-bottom: 1px solid #e2e8f0;
            }
            .main-pg {
                margin-left: 0;
                padding: 18px;
            }
        }
    </style>
</head>
<body>
    <aside class="sidebar-pg">
        <div>
            <div class="brand-logo-box">
                <?php if ($url_logo !== ''): ?>
                    <img src="<?= htmlspecialchars($url_logo, ENT_QUOTES, 'UTF-8'); ?>" alt="Logo Diskominfo Sangihe">
                <?php else: ?>
                    <div class="fw-bold text-primary"><i class="bi bi-envelope-fill me-1"></i> Diskominfo Sangihe</div>
                <?php endif; ?>
            </div>

            <nav class="nav flex-column">
                <a href="<?= htmlspecialchars($link_dashboard, ENT_QUOTES, 'UTF-8'); ?>" class="nav-link">
                    <i class="bi bi-speedometer2"></i>
                    <span>Dashboard</span>
                </a>
                <a href="<?= htmlspecialchars($link_kirim_surat, ENT_QUOTES, 'UTF-8'); ?>" class="nav-link <?= $menu_ks === 'kirim' ? 'active' : ''; ?>">
                    <i class="bi bi-send"></i>
                    <span>Kirim Surat Baru</span>
                </a>
                <a href="<?= htmlspecialchars($link_riwayat_surat, ENT_QUOTES, 'UTF-8'); ?>" class="nav-link">
                    <i class="bi bi-clock-history"></i>
                    <span>Riwayat Surat Saya</span>
                </a>
                <a href="<?= htmlspecialchars($link_lacak_surat, ENT_QUOTES, 'UTF-8'); ?>" class="nav-link">
                    <i class="bi bi-search"></i>
                    <span>Lacak Surat</span>
                </a>
                <a href="<?= htmlspecialchars($link_arsip_surat, ENT_QUOTES, 'UTF-8'); ?>" class="nav-link">
                    <i class="bi bi-archive"></i>
                    <span>Arsip Surat</span>
                </a>
                <a href="<?= htmlspecialchars($link_profil_surat, ENT_QUOTES, 'UTF-8'); ?>" class="nav-link <?= $menu_ks === 'profil' ? 'active' : ''; ?>">
                    <i class="bi bi-gear"></i>
                    <span>Pengaturan Profil</span>
                </a>
            </nav>
        </div>

        <div class="pt-3 border-top">
            <a href="<?= htmlspecialchars($link_logout, ENT_QUOTES, 'UTF-8'); ?>" class="nav-link text-danger mb-0">
                <i class="bi bi-box-arrow-right"></i>
                <span>Keluar</span>
            </a>
        </div>
    </aside>

    <main class="main-pg">
        <?php if ($pesan_profil_sukses !== ''): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($pesan_profil_sukses, ENT_QUOTES, 'UTF-8'); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        <?php if ($pesan_profil_error !== ''): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($pesan_profil_error, ENT_QUOTES, 'UTF-8'); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($menu_ks === 'profil'): ?>
            <div class="header-banner-ks d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <h4 class="fw-bold mb-1 text-white">Pengaturan Profil &mdash; Pengirim</h4>
                    <div class="small text-white-50">Kelola informasi akun dan keamanan kata sandi Pengirim</div>
                </div>
                <span class="badge-pengirim-ks">
                    <i class="bi bi-person-badge-fill"></i>
                    <span>PENGIRIM &bull; <?= htmlspecialchars(strtoupper($nama_label), ENT_QUOTES, 'UTF-8'); ?></span>
                </span>
            </div>

            <div class="row g-4">
                <div class="col-lg-4">
                    <div class="kartu-profil-putih-ks h-100">
                        <div class="d-flex align-items-center gap-3 mb-3">
                            <div class="avatar-profil-ks">
                                <i class="bi bi-person-badge"></i>
                            </div>
                            <div>
                                <h6 class="fw-bold mb-1 text-dark"><?= htmlspecialchars($nama_label, ENT_QUOTES, 'UTF-8'); ?></h6>
                                <span class="badge bg-primary">Pengirim</span>
                            </div>
                        </div>
                        <hr>
                        <div class="small mb-2">
                            <div class="text-muted">Peran / Role</div>
                            <div class="fw-semibold text-dark">Pengirim Surat</div>
                        </div>
                        <div class="small mb-2">
                            <div class="text-muted">Username Akun</div>
                            <div class="fw-semibold text-dark"><?= htmlspecialchars($user_login !== '' ? $user_login : '-', ENT_QUOTES, 'UTF-8'); ?></div>
                        </div>
                        <div class="small">
                            <div class="text-muted">Email Aktif</div>
                            <div class="fw-semibold text-dark"><?= htmlspecialchars($email_login !== '' ? $email_login : '-', ENT_QUOTES, 'UTF-8'); ?></div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-8">
                    <div class="kartu-profil-putih-ks">
                        <h6 class="fw-bold mb-2 text-dark"><i class="bi bi-shield-check me-2 text-primary"></i>Perbarui Data Profil &amp; Keamanan Akun (Persetujuan Email Aktif)</h6>
                        <p class="small text-muted mb-3">Setiap pembaruan profil atau kata sandi memerlukan kode persetujuan yang dikirim ke <strong>email aktif</strong> yang Anda isikan.</p>

                        <form method="POST" action="kirim_surat.php?menu=profil" autocomplete="off">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold text-dark">Nama Lengkap / Nama Pengirim</label>
                                    <input type="text" name="nama_profil" class="form-control" value="<?= htmlspecialchars($val_nama_form, ENT_QUOTES, 'UTF-8'); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold text-dark">Username Login</label>
                                    <input type="text" name="username_profil" class="form-control" value="<?= htmlspecialchars($val_username_form, ENT_QUOTES, 'UTF-8'); ?>" required>
                                </div>
                                <div class="col-md-12">
                                    <label class="form-label small fw-semibold text-dark">Email Aktif (Wajib untuk Persetujuan)</label>
                                    <input type="email" name="email_profil" class="form-control" value="<?= htmlspecialchars($val_email_form, ENT_QUOTES, 'UTF-8'); ?>" placeholder="nama@emailaktif.com" required>
                                </div>

                                <div class="col-12"><hr class="my-1"><div class="small fw-bold text-muted">Keamanan Akun — Ganti Kata Sandi (Opsional)</div></div>

                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold text-dark">Kata Sandi Baru</label>
                                    <div class="sandi-wrapper-ks">
                                        <input type="password" id="sandiBaruProfilKs" name="sandi_baru_profil" class="form-control" placeholder="Minimal 6 karakter..." minlength="6" autocomplete="new-password">
                                        <button type="button" class="btn-toggle-sandi-ks toggle-sandi-ks" data-target="sandiBaruProfilKs" aria-label="Tampilkan sandi" title="Tampilkan sandi">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold text-dark">Konfirmasi Kata Sandi Baru</label>
                                    <div class="sandi-wrapper-ks">
                                        <input type="password" id="sandiKonfirmasiProfilKs" name="sandi_konfirmasi_profil" class="form-control" placeholder="Ulangi kata sandi baru..." minlength="6" autocomplete="new-password">
                                        <button type="button" class="btn-toggle-sandi-ks toggle-sandi-ks" data-target="sandiKonfirmasiProfilKs" aria-label="Tampilkan sandi" title="Tampilkan sandi">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                    </div>
                                </div>

                                <div class="col-12"><hr class="my-1"><div class="small fw-bold text-primary">Persetujuan Melalui Email Aktif</div></div>

                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold text-dark">Kode Persetujuan (6 Digit dari Email)</label>
                                    <input type="text" name="kode_persetujuan_email" class="form-control" maxlength="6" pattern="[0-9]{6}" placeholder="Contoh: 123456" autocomplete="one-time-code">
                                    <div class="form-text">
                                        <?php if ($menunggu_otp_ks): ?>
                                            Kode dikirim ke <strong><?= htmlspecialchars((string)$otp_aktif_ks['email'], ENT_QUOTES, 'UTF-8'); ?></strong>.
                                        <?php else: ?>
                                            Klik <strong>Kirim Kode ke Email</strong> terlebih dahulu.
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="col-md-6 d-flex align-items-start pt-md-4 gap-2 flex-wrap">
                                    <button type="submit" name="kirim_kode_profil_ks" value="1" formnovalidate class="btn btn-outline-primary fw-semibold">
                                        <i class="bi bi-envelope-check me-1"></i> <?= $menunggu_otp_ks ? 'Kirim Ulang Kode' : 'Kirim Kode ke Email'; ?>
                                    </button>
                                    <?php if ($menunggu_otp_ks): ?>
                                        <button type="submit" name="batal_kode_profil_ks" value="1" formnovalidate class="btn btn-outline-danger btn-sm mt-1">
                                            Batalkan
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="mt-4 d-flex justify-content-end gap-2">
                                <a href="kirim_surat.php" class="btn btn-outline-secondary">Kembali ke Kirim Surat</a>
                                <button type="submit" name="simpan_profil_ks" value="1" class="btn btn-primary fw-semibold px-4">
                                    <i class="bi bi-check2-circle me-1"></i> Verifikasi &amp; Simpan Profil
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

        <?php else: ?>
            <div class="card-panel-pg">
                <div class="d-flex align-items-center mb-4">
                    <div>
                        <h4 class="mb-1 text-white fw-bold">Kirim Surat Baru</h4>
                        <p class="text-muted mb-0">Unggah dan kirimkan dokumen surat resmi ke instansi</p>
                    </div>
                </div>

                <form action="proses_kirim_surat.php" method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="user_id" value="<?= (int)$id_login; ?>">

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold text-white">Asal Instansi / Pengirim <span class="text-warning">*</span></label>
                            <input type="text" name="asal_instansi" class="form-control" placeholder="Contoh: Kantor Camat / Dinas Pendidikan" required>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold text-white">Nomor Telepon / WhatsApp <span class="text-muted fw-normal">(Opsional)</span></label>
                            <input type="text" name="no_telepon" class="form-control" placeholder="Contoh: 081234567890">
                        </div>

                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold text-white">Nomor Surat</label>
                            <input type="text" name="no_surat" class="form-control" placeholder="Masukkan nomor surat jika ada...">
                        </div>

                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold text-white">Tanggal Surat <span class="text-warning">*</span></label>
                            <input type="date" name="tanggal_surat" class="form-control" required>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold text-white">Jenis Surat <span class="text-warning">*</span></label>
                            <select name="jenis_surat" class="form-select" required>
                                <option value="" disabled selected>-- Pilih Jenis Surat --</option>
                                <option value="Surat Undangan">Surat Undangan</option>
                                <option value="Surat Permintaan Data">Surat Permintaan Data</option>
                                <option value="Surat Penegasan">Surat Penegasan</option>
                                <option value="Surat Edaran">Surat Edaran</option>
                                <option value="Surat Pemberitahuan">Surat Pemberitahuan</option>
                                <option value="Surat Permohonan">Surat Permohonan</option>
                                <option value="Surat Lainnya">Surat Lainnya</option>
                            </select>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold text-white">Sifat Surat</label>
                            <select name="sifat_surat" class="form-select">
                                <option value="Biasa">Biasa</option>
                                <option value="Penting">Penting</option>
                                <option value="Segera">Segera</option>
                                <option value="Rahasia">Rahasia</option>
                            </select>
                        </div>

                        <div class="col-12 mb-3">
                            <label class="form-label fw-semibold text-white">Perihal / Isi Ringkas Surat <span class="text-warning">*</span></label>
                            <textarea name="perihal" class="form-control" rows="3" placeholder="Masukkan perihal surat..." required></textarea>
                        </div>

                        <div class="col-12 mb-4">
                            <label class="form-label fw-semibold text-white">Upload Berkas Surat (Format PDF) <span class="text-warning">*</span></label>
                            <input type="file" name="file_surat" class="form-control" accept=".pdf" required>
                            <div class="form-text text-muted">Pastikan file berformat PDF dan ukuran tidak terlalu besar.</div>
                        </div>
                    </div>

                    <div class="d-flex justify-content-end gap-2">
                        <button type="reset" class="btn btn-light text-dark fw-semibold px-4">Batal</button>
                        <button type="submit" class="btn btn-success px-4">
                            <i class="bi bi-send-fill me-1"></i> Kirim Surat Sekarang
                        </button>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.querySelectorAll('.toggle-sandi-ks').forEach(function (btn) {
            var targetId = btn.getAttribute('data-target');
            var inputSandi = document.getElementById(targetId);
            if (!inputSandi) return;

            function sinkronTampilanMata() {
                var terlihat = inputSandi.type === 'text';
                btn.innerHTML = terlihat ? '<i class="bi bi-eye-slash"></i>' : '<i class="bi bi-eye"></i>';
                btn.setAttribute('aria-label', terlihat ? 'Sembunyikan sandi' : 'Tampilkan sandi');
                btn.setAttribute('title', terlihat ? 'Sembunyikan sandi' : 'Tampilkan sandi');
            }

            sinkronTampilanMata();

            btn.addEventListener('click', function () {
                inputSandi.type = (inputSandi.type === 'password') ? 'text' : 'password';
                sinkronTampilanMata();
            });
        });
    </script>
</body>
</html>