<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include '../koneksi.php';

$ada_sesi_login = isset($_SESSION['user'])
    || isset($_SESSION['id_user'])
    || isset($_SESSION['user_id'])
    || isset($_SESSION['id'])
    || isset($_SESSION['username']);

if (!$ada_sesi_login) {
    header("Location: ../login.php");
    exit();
}

$role_session = $_SESSION['role'] ?? ($_SESSION['level'] ?? (is_array($_SESSION['user'] ?? null) ? ($_SESSION['user']['role'] ?? ($_SESSION['user']['level'] ?? '')) : ''));
if ($role_session !== '' && strtolower(trim((string)$role_session)) !== 'sekretariat') {
    echo "<script>alert('Akses ditolak! Halaman ini khusus untuk Sekretariat.'); window.location='../login.php';</script>";
    exit();
}

function kolom_ada_ar(mysqli $koneksi, string $tabel, string $kolom): bool {
    $t = mysqli_real_escape_string($koneksi, $tabel);
    $k = mysqli_real_escape_string($koneksi, $kolom);
    $res = mysqli_query($koneksi, "SHOW COLUMNS FROM `$t` LIKE '$k'");
    return ($res && mysqli_num_rows($res) > 0);
}

function tabel_ada_ar(mysqli $koneksi, string $tabel): bool {
    $t = mysqli_real_escape_string($koneksi, $tabel);
    $res = mysqli_query($koneksi, "SHOW TABLES LIKE '$t'");
    return ($res && mysqli_num_rows($res) > 0);
}

function deteksi_pk_ar(mysqli $koneksi, string $tabel): string {
    $t = mysqli_real_escape_string($koneksi, $tabel);
    $res = mysqli_query($koneksi, "SHOW KEYS FROM `$t` WHERE Key_name = 'PRIMARY'");
    if ($res && ($row = mysqli_fetch_assoc($res)) && !empty($row['Column_name'])) {
        return (string)$row['Column_name'];
    }
    return 'id';
}

function cari_kolom_ar(mysqli $koneksi, string $tabel, array $kandidat): string {
    foreach ($kandidat as $kolom) {
        if (kolom_ada_ar($koneksi, $tabel, $kolom)) {
            return $kolom;
        }
    }
    return '';
}

function format_no_agenda_ar($raw_agenda): string {
    $val = trim((string)$raw_agenda);
    if ($val === '' || $val === '-') {
        return '-';
    }
    if (preg_match('/^AGD-/i', $val)) {
        $angka = trim((string)preg_replace('/^AGD-/i', '', $val));
        if ($angka !== '' && ctype_digit($angka)) {
            return 'AGD-' . str_pad($angka, 3, '0', STR_PAD_LEFT);
        }
        return 'AGD-' . strtoupper($angka);
    }
    if (ctype_digit($val)) {
        return 'AGD-' . str_pad($val, 3, '0', STR_PAD_LEFT);
    }
    return 'AGD-' . $val;
}

function kirim_otp_profil_ar(string $email_tujuan, string $kode_otp, string $nama_user): array {
    if (function_exists('kirim_otp_smtp')) {
        $detail = '';
        $ok = kirim_otp_smtp($email_tujuan, $kode_otp, $nama_user !== '' ? $nama_user : 'Sekretariat', $detail);
        return [
            'terkirim' => (bool)$ok,
            'error'    => $ok ? '' : $detail,
        ];
    }

    $subjek = 'Kode Persetujuan Pembaruan Profil Sekretariat - Portal E-Office Diskominfo Sangihe';
    $isi = "Halo " . ($nama_user !== '' ? $nama_user : 'Sekretariat') . ",\n\n"
        . "Kode persetujuan untuk memperbarui data profil dan/atau keamanan akun Sekretariat:\n"
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

$menu_aktif = strtolower(trim((string)($_GET['menu'] ?? 'arsip')));
if (!in_array($menu_aktif, ['arsip', 'profil'], true)) {
    $menu_aktif = 'arsip';
}

$pesan_sukses = '';
$pesan_error  = '';

$id_user_login  = (int)($_SESSION['user_id'] ?? $_SESSION['id_user'] ?? $_SESSION['id'] ?? (is_array($_SESSION['user'] ?? null) ? ($_SESSION['user']['id'] ?? 0) : 0));
$username_login = trim((string)($_SESSION['username'] ?? (is_array($_SESSION['user'] ?? null) ? ($_SESSION['user']['username'] ?? '') : '')));

$ada_tabel_users = (isset($koneksi) && $koneksi instanceof mysqli) ? tabel_ada_ar($koneksi, 'users') : false;
$pk_users        = $ada_tabel_users ? deteksi_pk_ar($koneksi, 'users') : 'id';
$kol_u_nama      = $ada_tabel_users ? cari_kolom_ar($koneksi, 'users', ['nama', 'nama_lengkap', 'full_name']) : '';
$kol_u_email     = $ada_tabel_users ? cari_kolom_ar($koneksi, 'users', ['email']) : '';
$kol_u_username  = $ada_tabel_users ? cari_kolom_ar($koneksi, 'users', ['username', 'user']) : '';
$kol_u_password  = $ada_tabel_users ? cari_kolom_ar($koneksi, 'users', ['password', 'sandi', 'kata_sandi']) : '';

$data_akun_ar = [];
if ($ada_tabel_users) {
    $pk_u_esc = mysqli_real_escape_string($koneksi, $pk_users);
    if ($id_user_login > 0) {
        $res_u = mysqli_query($koneksi, "SELECT * FROM `users` WHERE `$pk_u_esc` = $id_user_login LIMIT 1");
        if ($res_u && ($r_u = mysqli_fetch_assoc($res_u))) {
            $data_akun_ar  = $r_u;
            $id_user_login = (int)($data_akun_ar[$pk_users] ?? $id_user_login);
        }
    }
    if (empty($data_akun_ar) && $kol_u_username !== '' && $username_login !== '') {
        $u_esc = mysqli_real_escape_string($koneksi, $username_login);
        $res_u = mysqli_query($koneksi, "SELECT * FROM `users` WHERE LOWER(TRIM(`$kol_u_username`)) = LOWER('$u_esc') LIMIT 1");
        if ($res_u && ($r_u = mysqli_fetch_assoc($res_u))) {
            $data_akun_ar  = $r_u;
            $id_user_login = (int)($data_akun_ar[$pk_users] ?? $id_user_login);
        }
    }
    if (empty($data_akun_ar) && kolom_ada_ar($koneksi, 'users', 'role')) {
        $res_u = mysqli_query($koneksi, "SELECT * FROM `users` WHERE LOWER(TRIM(`role`)) = 'sekretariat' LIMIT 1");
        if ($res_u && ($r_u = mysqli_fetch_assoc($res_u))) {
            $data_akun_ar  = $r_u;
            $id_user_login = (int)($data_akun_ar[$pk_users] ?? $id_user_login);
        }
    }
}

$nama_raw = 'SEKRETARIAT';
if ($kol_u_nama !== '' && !empty($data_akun_ar[$kol_u_nama])) {
    $nama_raw = (string)$data_akun_ar[$kol_u_nama];
} elseif (!empty($_SESSION['google_name'])) {
    $nama_raw = $_SESSION['google_name'];
} elseif (is_array($_SESSION['user'] ?? null) && !empty($_SESSION['user']['name'])) {
    $nama_raw = $_SESSION['user']['name'];
} elseif (is_array($_SESSION['user'] ?? null) && !empty($_SESSION['user']['nama'])) {
    $nama_raw = $_SESSION['user']['nama'];
} elseif (!empty($_SESSION['nama'])) {
    $nama_raw = $_SESSION['nama'];
} elseif (!empty($_SESSION['name'])) {
    $nama_raw = $_SESSION['name'];
} elseif (!empty($_SESSION['username'])) {
    $nama_raw = $_SESSION['username'];
}

$email_login = ($kol_u_email !== '' && isset($data_akun_ar[$kol_u_email]))
    ? trim((string)$data_akun_ar[$kol_u_email])
    : trim((string)($_SESSION['email'] ?? ''));

$username_tampil = ($kol_u_username !== '' && !empty($data_akun_ar[$kol_u_username]))
    ? trim((string)$data_akun_ar[$kol_u_username])
    : ($username_login !== '' ? $username_login : 'sekretariat');

$draft_profil_ar = $_SESSION['draft_profil_ar'] ?? [];
$otp_aktif_ar    = $_SESSION['otp_profil_ar'] ?? null;

$nama_tampil_sk = trim((string)$nama_raw) !== '' ? trim((string)$nama_raw) : 'SEKRETARIAT';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['kirim_kode_profil_ar'])) {
    $menu_aktif    = 'profil';
    $nama_baru     = trim((string)($_POST['nama_profil'] ?? ''));
    $email_baru    = trim((string)($_POST['email_profil'] ?? ''));
    $username_baru = trim((string)($_POST['username_profil'] ?? ''));
    $sandi_baru    = (string)($_POST['sandi_baru_profil'] ?? '');
    $sandi_konf    = (string)($_POST['sandi_konfirmasi_profil'] ?? '');

    if ($nama_baru === '') {
        $pesan_error = 'Nama lengkap / nama petugas wajib diisi.';
    } elseif ($kol_u_username !== '' && $username_baru === '') {
        $pesan_error = 'Username login wajib diisi.';
    } elseif ($email_baru === '' || !filter_var($email_baru, FILTER_VALIDATE_EMAIL)) {
        $pesan_error = 'Masukkan alamat email aktif yang valid untuk menerima kode persetujuan.';
    } elseif ($sandi_baru !== '' && strlen($sandi_baru) < 6) {
        $pesan_error = 'Kata sandi baru minimal 6 karakter.';
    } elseif ($sandi_baru !== '' && $sandi_baru !== $sandi_konf) {
        $pesan_error = 'Konfirmasi kata sandi baru tidak sama.';
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

        $_SESSION['draft_profil_ar'] = $draft_baru;
        $_SESSION['otp_profil_ar']   = $otp_baru;
        $draft_profil_ar = $draft_baru;
        $otp_aktif_ar    = $otp_baru;

        $hasil_kirim = kirim_otp_profil_ar($email_baru, $kode_otp, $nama_baru);
        if ($hasil_kirim['terkirim']) {
            $pesan_sukses = 'Kode persetujuan telah dikirim ke email aktif ' . $email_baru . '. Silakan cek Inbox atau folder Spam, lalu masukkan kodenya.';
        } else {
            $detail_err = trim((string)($hasil_kirim['error'] ?? ''));
            $pesan_error  = 'Gagal mengirim kode ke email ' . $email_baru . ($detail_err !== '' ? ' (' . $detail_err . ')' : '') . '.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['batal_kode_profil_ar'])) {
    $menu_aktif = 'profil';
    unset($_SESSION['draft_profil_ar'], $_SESSION['otp_profil_ar']);
    $draft_profil_ar = [];
    $otp_aktif_ar    = null;
    $pesan_sukses    = 'Permintaan verifikasi email dibatalkan.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['simpan_profil_ar'])) {
    $menu_aktif = 'profil';
    $kode_input = trim((string)($_POST['kode_persetujuan_email'] ?? ''));

    if (!is_array($otp_aktif_ar) || empty($draft_profil_ar)) {
        $pesan_error = 'Pembaruan profil membutuhkan persetujuan email aktif. Isi email aktif lalu klik "Kirim Kode ke Email" terlebih dahulu.';
    } elseif ($kode_input === '') {
        $pesan_error = 'Masukkan kode persetujuan yang dikirim ke email aktif Anda.';
    } elseif (time() > (int)($otp_aktif_ar['expires_at'] ?? 0)) {
        $pesan_error = 'Kode persetujuan sudah kedaluwarsa. Klik "Kirim Kode ke Email" untuk meminta kode baru.';
    } elseif (!hash_equals((string)($otp_aktif_ar['kode'] ?? ''), $kode_input)) {
        $pesan_error = 'Kode persetujuan email tidak sesuai. Periksa kembali kode di email aktif Anda.';
    } elseif (!isset($koneksi) || !($koneksi instanceof mysqli) || !$ada_tabel_users || empty($data_akun_ar)) {
        $pesan_error = 'Data akun pengguna pada tabel users tidak ditemukan.';
    } else {
        $nama_baru     = (string)($draft_profil_ar['nama'] ?? '');
        $email_baru    = (string)($draft_profil_ar['email'] ?? '');
        $username_baru = (string)($draft_profil_ar['username'] ?? '');
        $hash_baru     = (string)($draft_profil_ar['password_hash'] ?? '');

        $id_target_u = (int)($data_akun_ar[$pk_users] ?? $id_user_login);
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
            $pesan_error = 'Tidak ada kolom profil yang dapat diperbarui.';
        } else {
            $pk_u_esc = mysqli_real_escape_string($koneksi, $pk_users);
            $sql_u = "UPDATE `users` SET " . implode(', ', $set_u) . " WHERE `$pk_u_esc` = $id_target_u LIMIT 1";
            if (mysqli_query($koneksi, $sql_u)) {
                $_SESSION['nama'] = $nama_baru;
                if ($username_baru !== '') {
                    $_SESSION['username'] = $username_baru;
                }
                if ($email_baru !== '') {
                    $_SESSION['email'] = $email_baru;
                }

                unset($_SESSION['draft_profil_ar'], $_SESSION['otp_profil_ar']);
                $draft_profil_ar = [];
                $otp_aktif_ar    = null;

                $res_u_fresh = mysqli_query($koneksi, "SELECT * FROM `users` WHERE `$pk_u_esc` = $id_target_u LIMIT 1");
                if ($res_u_fresh && ($r_fresh = mysqli_fetch_assoc($res_u_fresh))) {
                    $data_akun_ar    = $r_fresh;
                    $nama_tampil_sk  = trim((string)($data_akun_ar[$kol_u_nama] ?? $nama_baru));
                    $email_login     = trim((string)($data_akun_ar[$kol_u_email] ?? $email_baru));
                    $username_tampil = trim((string)($data_akun_ar[$kol_u_username] ?? $username_baru));
                }

                $pesan_sukses = 'Persetujuan email berhasil diverifikasi. Profil dan keamanan akun Sekretariat telah diperbarui.' . ($hash_baru !== '' ? ' Kata sandi baru juga aktif.' : '');
            } else {
                $pesan_error = 'Gagal menyimpan perubahan profil: ' . mysqli_error($koneksi);
            }
        }
    }
}

$val_nama_form     = (string)($draft_profil_ar['nama'] ?? $nama_tampil_sk);
$val_username_form = (string)($draft_profil_ar['username'] ?? $username_tampil);
$val_email_form    = (string)($draft_profil_ar['email'] ?? $email_login);
$menunggu_otp_ar   = is_array($otp_aktif_ar) && !empty($draft_profil_ar);

$kandidat_logo = [
    '../assets/logo_sangihe.png',
    'assets/logo_sangihe.png',
    '../logo_sangihe.png',
    '../assets/img/logo_sangihe.png',
];
$url_logo = '../assets/logo_sangihe.png';
foreach ($kandidat_logo as $rel) {
    if (file_exists(__DIR__ . '/' . $rel) && is_file(__DIR__ . '/' . $rel)) {
        $url_logo = $rel;
        break;
    }
}

$kol_agenda    = cari_kolom_ar($koneksi, 'surat_masuk', ['no_agenda', 'nomor_agenda']);
$kol_asal      = cari_kolom_ar($koneksi, 'surat_masuk', ['asal_instansi', 'asal_surat', 'instansi_pengirim', 'pengirim']);
$kol_no_surat  = cari_kolom_ar($koneksi, 'surat_masuk', ['no_surat', 'nomor_surat']);
$kol_jenis     = cari_kolom_ar($koneksi, 'surat_masuk', ['jenis_surat', 'jenis']);
$kol_perihal   = cari_kolom_ar($koneksi, 'surat_masuk', ['perihal', 'subjek', 'hal']);
$kol_disposisi = cari_kolom_ar($koneksi, 'surat_masuk', ['disposisi_kepada', 'tujuan_disposisi', 'tujuan_bidang', 'disposisi_ke']);
$kol_sifat     = cari_kolom_ar($koneksi, 'surat_masuk', ['sifat_surat', 'sifat']);
$kol_file      = cari_kolom_ar($koneksi, 'surat_masuk', ['file_surat', 'lampiran', 'berkas_surat']);
$kol_status    = cari_kolom_ar($koneksi, 'surat_masuk', ['status_surat', 'status']);
$kol_alasan    = cari_kolom_ar($koneksi, 'surat_masuk', [
    'alasan_penolakan',
    'catatan_penolakan',
    'alasan_tolak',
    'catatan_tolak',
    'keterangan_penolakan',
    'catatan_verifikasi',
    'alasan',
]);

$where_selesai = ($kol_status !== '')
    ? "WHERE LOWER(TRIM(COALESCE(`$kol_status`, ''))) IN ('selesai', 'diarsipkan', 'arsip', 'ditolak')"
    : "";
$order_by = kolom_ada_ar($koneksi, 'surat_masuk', 'id') ? "ORDER BY `id` DESC" : "";
$query = mysqli_query($koneksi, "SELECT * FROM `surat_masuk` $where_selesai $order_by");

$judul_halaman = $menu_aktif === 'profil'
    ? 'Pengaturan Profil - Sekretariat'
    : 'Arsip Surat - Sekretariat';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($judul_halaman, ENT_QUOTES, 'UTF-8'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            background-color: #f4f7fb;
            color: #1e293b;
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            margin: 0;
        }
        .sidebar {
            width: 260px;
            min-height: 100vh;
            background-color: #ffffff;
            border-right: 1px solid #e2e8f0;
            position: fixed;
            top: 0;
            left: 0;
            z-index: 100;
            padding: 18px 14px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        .sidebar-logo-box {
            background-color: #ffffff;
            border-radius: 10px;
            padding: 4px 8px;
            width: 100%;
            height: 102px;
            overflow: hidden;
            display: flex;
            justify-content: center;
            align-items: center;
            margin-bottom: 18px;
        }
        .sidebar-logo-img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            transform: scale(1.08);
            display: block;
            background-color: #ffffff;
        }
        .sidebar .nav-link {
            color: #334155;
            padding: 11px 14px;
            border-radius: 10px;
            margin-bottom: 6px;
            font-size: 0.92rem;
            font-weight: 500;
            transition: all 0.2s ease;
        }
        .sidebar .nav-link:hover {
            background-color: #f1f5f9;
            color: #0f172a;
        }
        .sidebar .nav-link.active {
            background-color: #0d6efd;
            color: #ffffff;
            font-weight: 600;
            box-shadow: 0 4px 12px rgba(13, 110, 253, 0.22);
        }
        .sidebar .logout-link {
            color: #dc2626 !important;
            font-weight: 500;
            padding: 10px 14px;
            border-radius: 10px;
            text-decoration: none;
            display: flex;
            align-items: center;
        }
        .sidebar .logout-link:hover {
            background-color: #fef2f2;
        }
        .main-content {
            margin-left: 260px;
            padding: 26px 30px;
        }
        .header-biru-sekretariat {
            background-color: #3b6998;
            border: 1px solid #2f5780;
            color: #ffffff;
            border-radius: 14px;
            padding: 20px 24px;
            box-shadow: 0 8px 20px rgba(47, 87, 128, 0.16);
        }
        .badge-putih-sek {
            background-color: #ffffff;
            color: #1e293b;
            font-size: 0.78rem;
            font-weight: 700;
            letter-spacing: 0.4px;
            padding: 7px 14px;
            border-radius: 999px;
            text-transform: uppercase;
            box-shadow: 0 2px 6px rgba(15, 23, 42, 0.12);
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .card-tabel-putih {
            background-color: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            box-shadow: 0 4px 14px rgba(15, 23, 42, 0.04);
        }
        .kartu-profil-putih {
            background-color: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            box-shadow: 0 4px 14px rgba(15, 23, 42, 0.04);
            color: #1e293b;
        }
        .avatar-profil-ar {
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
        .sandi-wrapper-ar {
            position: relative;
        }
        .sandi-wrapper-ar .form-control {
            padding-right: 42px;
        }
        .btn-toggle-sandi-ar {
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
        .btn-toggle-sandi-ar:hover {
            color: #0d6efd;
            background-color: rgba(13, 110, 253, 0.08);
        }
        .table-custom thead th {
            background-color: #3b6998 !important;
            color: #ffffff !important;
            border-color: #2f5780 !important;
        }
        .table-custom th, .table-custom td {
            font-size: 11px;
            padding: 6px 5px;
            vertical-align: middle;
        }
        .btn-xs {
            padding: 2px 5px;
            font-size: 10px;
        }
        @media (max-width: 768px) {
            .sidebar {
                position: relative;
                width: 100%;
                min-height: auto;
                border-right: none;
                border-bottom: 1px solid #e2e8f0;
            }
            .main-content {
                margin-left: 0;
                padding: 16px;
            }
        }
    </style>
</head>
<body>
    <aside class="sidebar">
        <div>
            <div class="sidebar-logo-box">
                <img src="<?= htmlspecialchars($url_logo, ENT_QUOTES, 'UTF-8'); ?>" alt="Logo LSM Diskominfo Sangihe" class="sidebar-logo-img">
            </div>
            <ul class="nav nav-pills flex-column">
                <li class="nav-item"><a href="dashboard.php" class="nav-link"><i class="bi bi-house-door-fill me-2"></i> Dashboard</a></li>
                <li class="nav-item"><a href="surat_masuk.php" class="nav-link"><i class="bi bi-envelope-paper-fill me-2"></i> Surat Masuk</a></li>
                <li class="nav-item"><a href="riwayat_surat.php" class="nav-link"><i class="bi bi-clock-history me-2"></i> Riwayat Surat</a></li>
                <li class="nav-item"><a href="registrasi_surat.php" class="nav-link"><i class="bi bi-journal-text me-2"></i> Registrasi Surat</a></li>
                <li class="nav-item"><a href="arsip_dokumen.php" class="nav-link <?= $menu_aktif === 'arsip' ? 'active' : ''; ?>"><i class="bi bi-folder-fill me-2"></i> Arsip Surat</a></li>
                <li class="nav-item"><a href="arsip_dokumen.php?menu=profil" class="nav-link <?= $menu_aktif === 'profil' ? 'active' : ''; ?>"><i class="bi bi-gear-fill me-2"></i> Pengaturan Profil</a></li>
            </ul>
        </div>
        <div class="pt-3 border-top border-light-subtle">
            <a href="../logout.php" class="logout-link"><i class="bi bi-box-arrow-left me-2"></i> Keluar Sistem</a>
        </div>
    </aside>

    <main class="main-content">
        <div class="container-fluid px-0">
            <?php if ($pesan_sukses !== ''): ?>
                <div class="alert alert-success alert-dismissible fade show mb-3" role="alert">
                    <?= htmlspecialchars($pesan_sukses, ENT_QUOTES, 'UTF-8'); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            <?php if ($pesan_error !== ''): ?>
                <div class="alert alert-danger alert-dismissible fade show mb-3" role="alert">
                    <?= htmlspecialchars($pesan_error, ENT_QUOTES, 'UTF-8'); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if ($menu_aktif === 'profil'): ?>
                <div class="header-biru-sekretariat mb-4 d-flex justify-content-between align-items-center flex-wrap gap-3">
                    <div>
                        <h4 class="fw-bold mb-1 text-white">Pengaturan Profil &mdash; Sekretariat</h4>
                        <p class="mb-0 small" style="color: #dbeafe;">Kelola informasi akun dan keamanan kata sandi Sekretariat</p>
                    </div>
                    <span class="badge-putih-sek"><i class="bi bi-person-circle"></i> SEKRETARIAT</span>
                </div>

                <div class="row g-4">
                    <div class="col-lg-4">
                        <div class="kartu-profil-putih p-4 h-100">
                            <div class="d-flex align-items-center gap-3 mb-3">
                                <div class="avatar-profil-ar">
                                    <i class="bi bi-person-badge"></i>
                                </div>
                                <div>
                                    <h6 class="fw-bold mb-1 text-dark"><?= htmlspecialchars($nama_tampil_sk, ENT_QUOTES, 'UTF-8'); ?></h6>
                                    <span class="badge bg-primary">Sekretariat</span>
                                </div>
                            </div>
                            <hr>
                            <div class="small mb-2">
                                <div class="text-muted">Unit / Bagian</div>
                                <div class="fw-semibold text-dark">Sekretariat</div>
                            </div>
                            <div class="small mb-2">
                                <div class="text-muted">Username Akun</div>
                                <div class="fw-semibold text-dark"><?= htmlspecialchars($username_tampil !== '' ? $username_tampil : '-', ENT_QUOTES, 'UTF-8'); ?></div>
                            </div>
                            <div class="small">
                                <div class="text-muted">Email Aktif</div>
                                <div class="fw-semibold text-dark"><?= htmlspecialchars($email_login !== '' ? $email_login : '-', ENT_QUOTES, 'UTF-8'); ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-8">
                        <div class="kartu-profil-putih p-4">
                            <h6 class="fw-bold mb-2 text-dark"><i class="bi bi-shield-check me-2 text-primary"></i>Perbarui Data Profil &amp; Keamanan Akun (Persetujuan Email Aktif)</h6>
                            <p class="small text-muted mb-3">Setiap pembaruan profil atau kata sandi memerlukan kode persetujuan yang dikirim ke <strong>email aktif</strong> yang Anda isikan.</p>

                            <form method="POST" action="arsip_dokumen.php?menu=profil" autocomplete="off">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label small fw-semibold text-dark">Nama Lengkap / Nama Petugas</label>
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
                                        <div class="sandi-wrapper-ar">
                                            <input type="password" id="sandiBaruProfilAr" name="sandi_baru_profil" class="form-control" placeholder="Minimal 6 karakter..." minlength="6" autocomplete="new-password">
                                            <button type="button" class="btn-toggle-sandi-ar toggle-sandi-ar" data-target="sandiBaruProfilAr" aria-label="Tampilkan sandi" title="Tampilkan sandi">
                                                <i class="bi bi-eye"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-semibold text-dark">Konfirmasi Kata Sandi Baru</label>
                                        <div class="sandi-wrapper-ar">
                                            <input type="password" id="sandiKonfirmasiProfilAr" name="sandi_konfirmasi_profil" class="form-control" placeholder="Ulangi kata sandi baru..." minlength="6" autocomplete="new-password">
                                            <button type="button" class="btn-toggle-sandi-ar toggle-sandi-ar" data-target="sandiKonfirmasiProfilAr" aria-label="Tampilkan sandi" title="Tampilkan sandi">
                                                <i class="bi bi-eye"></i>
                                            </button>
                                        </div>
                                    </div>

                                    <div class="col-12"><hr class="my-1"><div class="small fw-bold text-primary">Persetujuan Melalui Email Aktif</div></div>

                                    <div class="col-md-6">
                                        <label class="form-label small fw-semibold text-dark">Kode Persetujuan (6 Digit dari Email)</label>
                                        <input type="text" name="kode_persetujuan_email" class="form-control" maxlength="6" pattern="[0-9]{6}" placeholder="Contoh: 123456" autocomplete="one-time-code">
                                        <div class="form-text">
                                            <?php if ($menunggu_otp_ar): ?>
                                                Kode dikirim ke <strong><?= htmlspecialchars((string)$otp_aktif_ar['email'], ENT_QUOTES, 'UTF-8'); ?></strong>.
                                            <?php else: ?>
                                                Klik <strong>Kirim Kode ke Email</strong> terlebih dahulu.
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="col-md-6 d-flex align-items-start pt-md-4 gap-2 flex-wrap">
                                        <button type="submit" name="kirim_kode_profil_ar" value="1" formnovalidate class="btn btn-outline-primary fw-semibold">
                                            <i class="bi bi-envelope-check me-1"></i> <?= $menunggu_otp_ar ? 'Kirim Ulang Kode' : 'Kirim Kode ke Email'; ?>
                                        </button>
                                        <?php if ($menunggu_otp_ar): ?>
                                            <button type="submit" name="batal_kode_profil_ar" value="1" formnovalidate class="btn btn-outline-danger btn-sm mt-1">
                                                Batalkan
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="mt-4 d-flex justify-content-end gap-2">
                                    <a href="arsip_dokumen.php" class="btn btn-outline-secondary">Kembali ke Arsip Surat</a>
                                    <button type="submit" name="simpan_profil_ar" value="1" class="btn btn-primary fw-semibold px-4">
                                        <i class="bi bi-check2-circle me-1"></i> Verifikasi &amp; Simpan Profil
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

            <?php else: ?>
                <div class="header-biru-sekretariat mb-4 d-flex justify-content-between align-items-center flex-wrap gap-3">
                    <div>
                        <h4 class="fw-bold mb-1 text-white">Arsip Surat Selesai &amp; Ditolak</h4>
                        <p class="mb-0 small" style="color: #dbeafe;">
                            Penyimpanan permanen arsip surat yang telah selesai maupun yang ditolak beserta alasannya &bull;
                            <strong class="text-white"><?= htmlspecialchars($nama_tampil_sk, ENT_QUOTES, 'UTF-8'); ?></strong>
                        </p>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <a href="arsip_dokumen.php?menu=profil" class="btn btn-outline-light btn-sm rounded-pill px-3 fw-semibold">
                            <i class="bi bi-person-gear me-1"></i> Profil
                        </a>
                        <span class="badge-putih-sek"><i class="bi bi-person-circle"></i> SEKRETARIAT</span>
                    </div>
                </div>

                <div class="card card-tabel-putih p-4">
                    <h5 class="fw-bold mb-3" style="color: #0f172a;"><i class="bi bi-table me-2" style="color: #3b6998;"></i>Daftar Arsip Surat</h5>
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped table-custom align-middle mb-0">
                            <thead class="text-center">
                                <tr>
                                    <th width="4%">No</th>
                                    <th width="7%">No. Agenda</th>
                                    <th width="12%">Asal Instansi</th>
                                    <th width="11%">Nomor Surat</th>
                                    <th width="10%">Jenis Surat</th>
                                    <th width="16%">Perihal</th>
                                    <th width="12%">Disposisi Tujuan</th>
                                    <th width="18%">Status &amp; Alasan</th>
                                    <th width="10%">Dokumen Surat</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $no = 1;
                                if ($query && mysqli_num_rows($query) > 0) {
                                    while ($row = mysqli_fetch_assoc($query)) {
                                        $v_agenda    = ($kol_agenda !== '' && !empty($row[$kol_agenda])) ? format_no_agenda_ar($row[$kol_agenda]) : '-';
                                        $v_asal      = ($kol_asal !== '' && !empty($row[$kol_asal])) ? (string)$row[$kol_asal] : '-';
                                        $v_no_surat  = ($kol_no_surat !== '' && !empty($row[$kol_no_surat])) ? (string)$row[$kol_no_surat] : '-';
                                        $v_jenis     = ($kol_jenis !== '' && !empty($row[$kol_jenis])) ? (string)$row[$kol_jenis] : '-';
                                        $v_perihal   = ($kol_perihal !== '' && !empty($row[$kol_perihal])) ? (string)$row[$kol_perihal] : '-';
                                        $v_disposisi = ($kol_disposisi !== '' && !empty($row[$kol_disposisi])) ? (string)$row[$kol_disposisi] : '-';
                                        $v_sifat     = ($kol_sifat !== '' && !empty($row[$kol_sifat])) ? (string)$row[$kol_sifat] : 'Biasa';
                                        $v_file      = ($kol_file !== '' && !empty($row[$kol_file])) ? (string)$row[$kol_file] : '';
                                        $v_status    = ($kol_status !== '' && !empty($row[$kol_status])) ? trim((string)$row[$kol_status]) : 'Selesai';
                                        $v_alasan    = ($kol_alasan !== '' && !empty($row[$kol_alasan])) ? trim((string)$row[$kol_alasan]) : '-';
                                        $is_ditolak  = (strtolower($v_status) === 'ditolak');
                                        $is_rahasia  = (strtolower(trim($v_sifat)) === 'rahasia');
                                ?>
                                <tr>
                                    <td class="text-center"><?= $no++; ?></td>
                                    <td class="text-center"><span class="badge bg-secondary rounded-pill px-2 py-1"><?= htmlspecialchars($v_agenda, ENT_QUOTES, 'UTF-8'); ?></span></td>
                                    <td><?= htmlspecialchars($v_asal, ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?= htmlspecialchars($v_no_surat, ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td class="text-center"><?= htmlspecialchars($v_jenis, ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?= htmlspecialchars($v_perihal, ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?= htmlspecialchars($v_disposisi, ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td>
                                        <?php if ($is_ditolak): ?>
                                            <span class="badge bg-danger mb-1"><i class="bi bi-x-circle-fill me-1"></i> Ditolak</span>
                                            <div class="text-danger fw-medium" style="font-size: 10px;">
                                                Alasan: <?= htmlspecialchars($v_alasan !== '' ? $v_alasan : '-', ENT_QUOTES, 'UTF-8'); ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="badge bg-success">Selesai</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($v_file !== '' && !$is_rahasia): ?>
                                            <a href="../uploads/<?= htmlspecialchars($v_file, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" class="btn btn-success btn-xs" title="Lihat PDF"><i class="bi bi-file-earmark-pdf-fill"></i></a>
                                        <?php else: ?>
                                            <span class="text-muted" style="font-size: 9px;">Arsip Rahasia</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php
                                    }
                                } else {
                                ?>
                                <tr><td colspan="9" class="text-center text-muted py-4">Belum ada arsip surat yang selesai atau ditolak.</td></tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.querySelectorAll('.toggle-sandi-ar').forEach(function (btn) {
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