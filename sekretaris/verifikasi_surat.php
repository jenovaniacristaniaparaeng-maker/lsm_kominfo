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

$role_session = $_SESSION['role'] ?? ($_SESSION['level'] ?? (is_array($_SESSION['user'] ?? null) ? ($_SESSION['user']['role'] ?? ($_SESSION['user']['level'] ?? '')) : ''));
$role_clean   = strtolower(trim((string)$role_session));
if ($role_clean !== '' && !in_array($role_clean, ['sekretaris', 'sekretariat', 'admin'], true)) {
    echo "<script>alert('Akses ditolak! Halaman ini khusus untuk Sekretaris.'); window.location='../login.php';</script>";
    exit();
}

$id_login    = (int)($_SESSION['user_id'] ?? $_SESSION['id_user'] ?? $_SESSION['id'] ?? (is_array($_SESSION['user'] ?? null) ? ($_SESSION['user']['id'] ?? 0) : 0));
$user_login  = trim((string)($_SESSION['username'] ?? (is_array($_SESSION['user'] ?? null) ? ($_SESSION['user']['username'] ?? '') : '')));
$email_login = trim((string)($_SESSION['email'] ?? $_SESSION['google_email'] ?? (is_array($_SESSION['user'] ?? null) ? ($_SESSION['user']['email'] ?? '') : '')));

$nama_profil = 'SEKRETARIS';
if (!empty($_SESSION['google_name'])) {
    $nama_profil = trim((string)$_SESSION['google_name']);
} elseif (is_array($_SESSION['user'] ?? null) && !empty($_SESSION['user']['name'])) {
    $nama_profil = trim((string)$_SESSION['user']['name']);
} elseif (is_array($_SESSION['user'] ?? null) && !empty($_SESSION['user']['nama'])) {
    $nama_profil = trim((string)$_SESSION['user']['nama']);
} elseif (!empty($_SESSION['nama'])) {
    $nama_profil = trim((string)$_SESSION['nama']);
} elseif (!empty($_SESSION['name'])) {
    $nama_profil = trim((string)$_SESSION['name']);
} elseif ($user_login !== '') {
    $nama_profil = $user_login;
}

$nip_profil = trim((string)($_SESSION['nip'] ?? ''));

if (isset($koneksi) && $koneksi instanceof mysqli) {
    $cek_users = mysqli_query($koneksi, "SHOW TABLES LIKE 'users'");
    if ($cek_users && mysqli_num_rows($cek_users) > 0) {
        $row_u = null;
        if ($id_login > 0) {
            $stmt_u = mysqli_prepare($koneksi, "SELECT * FROM `users` WHERE `id` = ? LIMIT 1");
            if ($stmt_u) {
                mysqli_stmt_bind_param($stmt_u, 'i', $id_login);
                mysqli_stmt_execute($stmt_u);
                $res_u = mysqli_stmt_get_result($stmt_u);
                $row_u = mysqli_fetch_assoc($res_u) ?: null;
                mysqli_stmt_close($stmt_u);
            }
        } elseif ($email_login !== '') {
            $stmt_u = mysqli_prepare($koneksi, "SELECT * FROM `users` WHERE LOWER(TRIM(`email`)) = LOWER(?) LIMIT 1");
            if ($stmt_u) {
                mysqli_stmt_bind_param($stmt_u, 's', $email_login);
                mysqli_stmt_execute($stmt_u);
                $res_u = mysqli_stmt_get_result($stmt_u);
                $row_u = mysqli_fetch_assoc($res_u) ?: null;
                mysqli_stmt_close($stmt_u);
            }
        } elseif ($user_login !== '') {
            $stmt_u = mysqli_prepare($koneksi, "SELECT * FROM `users` WHERE LOWER(TRIM(`username`)) = LOWER(?) LIMIT 1");
            if ($stmt_u) {
                mysqli_stmt_bind_param($stmt_u, 's', $user_login);
                mysqli_stmt_execute($stmt_u);
                $res_u = mysqli_stmt_get_result($stmt_u);
                $row_u = mysqli_fetch_assoc($res_u) ?: null;
                mysqli_stmt_close($stmt_u);
            }
        }

        if ($row_u) {
            if (!empty($row_u['nama'])) {
                $nama_profil = trim((string)$row_u['nama']);
            } elseif (!empty($row_u['name'])) {
                $nama_profil = trim((string)$row_u['name']);
            }
            if (!empty($row_u['nip'])) {
                $nip_profil = trim((string)$row_u['nip']);
            }
        }
    }
}

function kolom_ada_ver_persis(mysqli $koneksi, string $tabel, string $kolom): bool {
    $t = mysqli_real_escape_string($koneksi, $tabel);
    $k = mysqli_real_escape_string($koneksi, $kolom);
    $res = mysqli_query($koneksi, "SHOW COLUMNS FROM `$t` LIKE '$k'");
    return ($res && mysqli_num_rows($res) > 0);
}

function cari_kolom_ver_persis(mysqli $koneksi, string $tabel, array $kandidat): string {
    foreach ($kandidat as $kolom) {
        if (kolom_ada_ver_persis($koneksi, $tabel, $kolom)) {
            return $kolom;
        }
    }
    return '';
}

function pastikan_kolom_ver_persis(mysqli $koneksi, string $kolom, string $definisi_sql): string {
    if (kolom_ada_ver_persis($koneksi, 'surat_masuk', $kolom)) {
        return $kolom;
    }
    @mysqli_query($koneksi, "ALTER TABLE `surat_masuk` ADD COLUMN `$kolom` $definisi_sql");
    return kolom_ada_ver_persis($koneksi, 'surat_masuk', $kolom) ? $kolom : '';
}

function cari_logo_sangihe_ver_persis(): string {
    $base_dir = __DIR__;
    $root_dir = dirname(__DIR__);
    foreach ([
        '../assets/logo_sangihe.png', '../assets/logo_sangihe.jpg', '../assets/logo_sangihe.jpeg',
        'assets/logo_sangihe.png', 'assets/logo_sangihe.jpg', 'assets/logo_sangihe.jpeg',
        '../logo_sangihe.png', 'logo_sangihe.png', '../sangihe.png', 'sangihe.png',
        '../assets/img/logo_sangihe.png', 'assets/img/logo_sangihe.png',
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
    return '../assets/logo_sangihe.png';
}

function format_no_agenda_ver_persis($raw_agenda): string {
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

function resolve_url_file_ver_persis(string $raw_file): string {
    $bersih = trim(str_replace('\\', '/', $raw_file));
    if ($bersih === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $bersih)) {
        return $bersih;
    }
    $nama_saja = basename($bersih);
    foreach ([
        ltrim($bersih, '/'),
        '../' . ltrim($bersih, '/'),
        '../uploads/' . $nama_saja,
        'uploads/' . $nama_saja,
    ] as $rel) {
        if (file_exists(__DIR__ . '/' . $rel) && is_file(__DIR__ . '/' . $rel)) {
            return $rel;
        }
    }
    return (strpos($bersih, '../') === 0) ? $bersih : ('../uploads/' . $nama_saja);
}

$url_logo        = cari_logo_sangihe_ver_persis();
$kol_pk          = cari_kolom_ver_persis($koneksi, 'surat_masuk', ['id', 'id_surat']);
if ($kol_pk === '') {
    $kol_pk = 'id';
}
$kol_agenda      = cari_kolom_ver_persis($koneksi, 'surat_masuk', ['no_agenda', 'nomor_agenda']);
$kol_asal        = cari_kolom_ver_persis($koneksi, 'surat_masuk', ['asal_instansi', 'asal_surat', 'instansi_pengirim', 'pengirim']);
$kol_no_surat    = cari_kolom_ver_persis($koneksi, 'surat_masuk', ['no_surat', 'nomor_surat']);
$kol_jenis       = cari_kolom_ver_persis($koneksi, 'surat_masuk', ['jenis_surat', 'jenis']);
$kol_perihal     = cari_kolom_ver_persis($koneksi, 'surat_masuk', ['perihal', 'subjek', 'hal']);
$kol_sifat       = cari_kolom_ver_persis($koneksi, 'surat_masuk', ['sifat_surat', 'sifat']);
$kol_file        = cari_kolom_ver_persis($koneksi, 'surat_masuk', ['file_surat', 'lampiran', 'berkas_surat', 'dokumen_surat']);
$kol_status      = cari_kolom_ver_persis($koneksi, 'surat_masuk', ['status_surat', 'status']);
$kol_disposisi   = cari_kolom_ver_persis($koneksi, 'surat_masuk', ['disposisi_kepada', 'tujuan_bidang', 'bidang_tujuan', 'disposisi_ke']);
$kol_posisi      = cari_kolom_ver_persis($koneksi, 'surat_masuk', ['penerima_sekarang', 'posisi_surat', 'disposisi_kepada', 'tujuan_bidang']);
$kol_sembunyi_sk = pastikan_kolom_ver_persis($koneksi, 'dihapus_sekretaris', 'TINYINT(1) NOT NULL DEFAULT 0');
$kol_alasan      = cari_kolom_ver_persis($koneksi, 'surat_masuk', [
    'alasan_penolakan',
    'catatan_penolakan',
    'alasan_tolak',
    'catatan_tolak',
    'keterangan_penolakan',
    'catatan_verifikasi',
    'catatan',
    'alasan',
]);
if ($kol_alasan === '') {
    $kol_alasan = pastikan_kolom_ver_persis($koneksi, 'alasan_penolakan', 'TEXT NULL');
}
$kol_catatan = cari_kolom_ver_persis($koneksi, 'surat_masuk', [
    'catatan_verifikasi',
    'catatan_sekretaris',
    'catatan_disposisi',
    'catatan',
]);

$opsi_sifat = ['Biasa', 'Penting', 'Segera', 'Rahasia'];

$opsi_disposisi = [
    'Kepala Dinas',
    'Bidang 1 - Pengelolaan Informasi dan Saluran Komunikasi Publik',
    'Bidang 2 - Penyelenggaraan e-Government',
    'Bidang 3 - Pengembangan Sumber Daya Komunikasi dan Informatika',
    'Bidang 4 - Statistik Sektoral dan Persandian',
];

$pesan_sukses = '';
$pesan_error  = '';

$id_verifikasi     = 0;
$agenda_verifikasi = '';

foreach (['id', 'verifikasi', 'id_surat', 'surat'] as $param_kunci) {
    if (isset($_GET[$param_kunci])) {
        $raw_p = trim((string)$_GET[$param_kunci]);
        if ($raw_p !== '' && ctype_digit($raw_p) && (int)$raw_p > 0) {
            $id_verifikasi = (int)$raw_p;
            break;
        }
    }
}
if ($id_verifikasi === 0) {
    foreach (['no_agenda', 'agenda', 'nomor_agenda'] as $param_agd) {
        if (!empty($_GET[$param_agd])) {
            $agenda_verifikasi = trim((string)$_GET[$param_agd]);
            break;
        }
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['id_surat']) && ctype_digit((string)$_POST['id_surat'])) {
    $id_verifikasi = (int)$_POST['id_surat'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['simpan_verifikasi'])) {
    $id_surat_post   = (int)($_POST['id_surat'] ?? 0);
    $keputusan_post  = trim((string)($_POST['keputusan'] ?? ''));
    $disposisi_post  = trim((string)($_POST['disposisi_kepada'] ?? ''));
    $sifat_post      = trim((string)($_POST['sifat_surat'] ?? ''));
    $alasan_cat_post = trim((string)($_POST['alasan_catatan'] ?? ''));

    $is_ditolak = (strtolower($keputusan_post) === 'ditolak');

    if ($id_surat_post <= 0 || $keputusan_post === '') {
        $pesan_error = 'Pilih surat melalui tombol Verifikasi di Surat Masuk dan tentukan keputusan verifikasi.';
    } elseif (!$is_ditolak && $disposisi_post === '') {
        $pesan_error = 'Silakan pilih tujuan disposisi terlebih dahulu apabila surat diverifikasi/diproses.';
    } elseif (!$is_ditolak && $sifat_post === '') {
        $pesan_error = 'Silakan tentukan sifat surat untuk tujuan disposisi agar bidang dapat membuka dokumennya.';
    } elseif ($is_ditolak && $alasan_cat_post === '') {
        $pesan_error = 'Alasan penolakan / catatan wajib diisi apabila surat ditolak.';
    } else {
        $set_parts = [];
        $types     = '';
        $params    = [];

        if ($kol_status !== '') {
            $set_parts[] = "`$kol_status` = ?";
            $types .= 's';
            $params[] = $keputusan_post;
        }
        if (!$is_ditolak) {
            if ($kol_sifat !== '' && $sifat_post !== '') {
                $set_parts[] = "`$kol_sifat` = ?";
                $types .= 's';
                $params[] = $sifat_post;
            }
            if ($kol_disposisi !== '' && $disposisi_post !== '') {
                $set_parts[] = "`$kol_disposisi` = ?";
                $types .= 's';
                $params[] = $disposisi_post;
            }
            if ($kol_posisi !== '' && $kol_posisi !== $kol_disposisi && $disposisi_post !== '') {
                $set_parts[] = "`$kol_posisi` = ?";
                $types .= 's';
                $params[] = $disposisi_post;
            }
        } elseif ($kol_posisi !== '') {
            $set_parts[] = "`$kol_posisi` = ?";
            $types .= 's';
            $params[] = 'Ditolak (Sekretaris)';
        }

        if ($kol_alasan !== '') {
            $set_parts[] = "`$kol_alasan` = ?";
            $types .= 's';
            $params[] = $alasan_cat_post;
        }
        if ($kol_catatan !== '' && $kol_catatan !== $kol_alasan) {
            $set_parts[] = "`$kol_catatan` = ?";
            $types .= 's';
            $params[] = $alasan_cat_post;
        }

        if (!empty($set_parts)) {
            $sql_upd  = "UPDATE `surat_masuk` SET " . implode(', ', $set_parts) . " WHERE `$kol_pk` = ? LIMIT 1";
            $stmt_upd = mysqli_prepare($koneksi, $sql_upd);
            if ($stmt_upd) {
                $types .= 'i';
                $params[] = $id_surat_post;
                mysqli_stmt_bind_param($stmt_upd, $types, ...$params);
                if (mysqli_stmt_execute($stmt_upd)) {
                    $info_disp     = (!$is_ditolak) ? " (Disposisi: $disposisi_post | Sifat: $sifat_post)" : '';
                    $pesan_sukses  = 'Verifikasi surat berhasil disimpan dengan status: ' . $keputusan_post . $info_disp . '.';
                    $id_verifikasi = 0;
                } else {
                    $pesan_error = 'Gagal menyimpan verifikasi surat.';
                }
                mysqli_stmt_close($stmt_upd);
            }
        }
    }
}

$surat_dipilih = null;
if ($id_verifikasi > 0) {
    $where_aktif = "`$kol_pk` = ?";
    if ($kol_sembunyi_sk !== '') {
        $where_aktif .= " AND COALESCE(`$kol_sembunyi_sk`, 0) = 0";
    }
    $stmt_ver = mysqli_prepare($koneksi, "SELECT * FROM `surat_masuk` WHERE $where_aktif LIMIT 1");
    if ($stmt_ver) {
        mysqli_stmt_bind_param($stmt_ver, 'i', $id_verifikasi);
        mysqli_stmt_execute($stmt_ver);
        $res_ver = mysqli_stmt_get_result($stmt_ver);
        $surat_dipilih = mysqli_fetch_assoc($res_ver) ?: null;
        mysqli_stmt_close($stmt_ver);
    }
    if ($surat_dipilih && isset($surat_dipilih[$kol_pk])) {
        $id_verifikasi = (int)$surat_dipilih[$kol_pk];
    } else {
        $id_verifikasi = 0;
    }
} elseif ($agenda_verifikasi !== '' && $kol_agenda !== '') {
    $where_agd = "UPPER(TRIM(COALESCE(`$kol_agenda`, ''))) = UPPER(TRIM(?))";
    if ($kol_sembunyi_sk !== '') {
        $where_agd .= " AND COALESCE(`$kol_sembunyi_sk`, 0) = 0";
    }
    $stmt_agd = mysqli_prepare($koneksi, "SELECT * FROM `surat_masuk` WHERE $where_agd ORDER BY `$kol_pk` DESC LIMIT 1");
    if ($stmt_agd) {
        mysqli_stmt_bind_param($stmt_agd, 's', $agenda_verifikasi);
        mysqli_stmt_execute($stmt_agd);
        $res_agd = mysqli_stmt_get_result($stmt_agd);
        $surat_dipilih = mysqli_fetch_assoc($res_agd) ?: null;
        mysqli_stmt_close($stmt_agd);
    }
    if ($surat_dipilih && isset($surat_dipilih[$kol_pk])) {
        $id_verifikasi = (int)$surat_dipilih[$kol_pk];
    }
}

$v_agenda    = ($surat_dipilih && $kol_agenda !== '' && !empty($surat_dipilih[$kol_agenda])) ? format_no_agenda_ver_persis($surat_dipilih[$kol_agenda]) : '';
$v_asal      = ($surat_dipilih && $kol_asal !== '' && !empty($surat_dipilih[$kol_asal])) ? (string)$surat_dipilih[$kol_asal] : '';
$v_no_sr     = ($surat_dipilih && $kol_no_surat !== '' && !empty($surat_dipilih[$kol_no_surat])) ? (string)$surat_dipilih[$kol_no_surat] : '';
$v_jenis     = ($surat_dipilih && $kol_jenis !== '' && !empty($surat_dipilih[$kol_jenis])) ? (string)$surat_dipilih[$kol_jenis] : '';
$v_hal       = ($surat_dipilih && $kol_perihal !== '' && !empty($surat_dipilih[$kol_perihal])) ? (string)$surat_dipilih[$kol_perihal] : '';
$v_sifat_awl = ($surat_dipilih && $kol_sifat !== '' && !empty($surat_dipilih[$kol_sifat])) ? trim((string)$surat_dipilih[$kol_sifat]) : 'Biasa';
$v_sifat_tjn = (strtolower($v_sifat_awl) === 'rahasia') ? 'Biasa' : ($v_sifat_awl !== '' ? $v_sifat_awl : 'Biasa');
$v_file      = ($surat_dipilih && $kol_file !== '' && !empty($surat_dipilih[$kol_file])) ? (string)$surat_dipilih[$kol_file] : '';
$v_disposisi = ($surat_dipilih && $kol_disposisi !== '' && !empty($surat_dipilih[$kol_disposisi])) ? trim((string)$surat_dipilih[$kol_disposisi]) : '';
$v_alasan_ct = '';
if ($surat_dipilih) {
    if ($kol_alasan !== '' && !empty($surat_dipilih[$kol_alasan])) {
        $v_alasan_ct = trim((string)$surat_dipilih[$kol_alasan]);
    } elseif ($kol_catatan !== '' && !empty($surat_dipilih[$kol_catatan])) {
        $v_alasan_ct = trim((string)$surat_dipilih[$kol_catatan]);
    }
}
$url_file   = ($v_file !== '') ? resolve_url_file_ver_persis($v_file) : '';
$is_rahasia = (strtolower(trim($v_sifat_awl)) === 'rahasia');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verifikasi Surat - Sekretaris</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <style>
        body {
            background-color: #f4f7fb;
            color: #1e293b;
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
        }
        .sidebar-logo-box {
            background-color: #ffffff;
            border-radius: 10px;
            padding: 6px 8px 14px;
            width: 100%;
            height: 106px;
            overflow: hidden;
            display: flex;
            justify-content: center;
            align-items: center;
            margin-bottom: 16px;
        }
        .sidebar-logo-img {
            width: 100%;
            height: 100%;
            max-width: 224px;
            object-fit: contain;
            display: block;
            background-color: #ffffff;
        }
        .sidebar .nav-link {
            color: #334155;
            padding: 12px 20px;
            border-radius: 8px;
            margin-bottom: 5px;
            font-weight: 500;
        }
        .sidebar .nav-link:hover,
        .sidebar .nav-link.active {
            background-color: #3b6998;
            color: #fff;
            font-weight: 600;
        }
        .main-content {
            margin-left: 260px;
            padding: 30px;
        }
        .header-biru-sekretaris {
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
            border-radius: 10px;
            text-transform: uppercase;
            box-shadow: 0 2px 6px rgba(15, 23, 42, 0.12);
        }
        .card-form-verif {
            background-color: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 24px;
            box-shadow: 0 .125rem .25rem rgba(0,0,0,.075);
        }
        .section-title-verif {
            font-size: 0.85rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #3b6998;
            margin-bottom: 14px;
            padding-bottom: 8px;
            border-bottom: 1px solid #e2e8f0;
        }
        .form-label {
            font-size: 0.85rem;
            font-weight: 600;
            color: #334155;
            margin-bottom: 6px;
        }
        .form-control, .form-select {
            border-radius: 9px;
            border-color: #cbd5e1;
            font-size: 0.9rem;
            padding: 9px 12px;
        }
        .form-control[readonly] {
            background-color: #f8fafc;
            color: #0f172a;
            font-weight: 500;
        }
        .box-berkas-surat {
            background-color: #f8fafc;
            border: 1px dashed #cbd5e1;
            border-radius: 10px;
            padding: 14px 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
        }
        .box-keputusan-verif {
            background-color: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 18px;
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

    <div class="sidebar d-flex flex-column p-3">
        <div>
            <div class="sidebar-logo-box">
                <img src="<?= htmlspecialchars($url_logo, ENT_QUOTES, 'UTF-8'); ?>" alt="Logo Kabupaten Kepulauan Sangihe" class="sidebar-logo-img">
            </div>
            <ul class="nav nav-pills flex-column mb-auto">
                <li class="nav-item"><a href="dashboard.php" class="nav-link"><i class="bi bi-house-door-fill me-2"></i> Dashboard</a></li>
                <li class="nav-item"><a href="disposisi_masuk.php" class="nav-link"><i class="bi bi-envelope-paper-fill me-2"></i> Surat Masuk</a></li>
                <li class="nav-item"><a href="verifikasi_surat.php" class="nav-link active"><i class="bi bi-check2-circle me-2"></i> Verifikasi Surat</a></li>
                <li class="nav-item"><a href="lembar_kendali.php" class="nav-link"><i class="bi bi-diagram-3-fill me-2"></i> Lembar Kendali</a></li>
                <li class="nav-item"><a href="laporan.php" class="nav-link"><i class="bi bi-file-earmark-bar-graph-fill me-2"></i> Laporan</a></li>
                <li class="nav-item"><a href="arsip.php" class="nav-link"><i class="bi bi-folder-fill me-2"></i> Arsip Surat</a></li>
                <li class="nav-item"><a href="dashboard.php?menu=profil" class="nav-link"><i class="bi bi-gear-fill me-2"></i> Pengaturan Profil</a></li>
            </ul>
        </div>
        <hr class="text-secondary">
        <div><a href="../logout.php" class="nav-link text-danger"><i class="bi bi-box-arrow-left me-2"></i> Keluar Sistem</a></div>
    </div>

    <div class="main-content">
        <div class="container-fluid">
            <div class="header-biru-sekretaris mb-4">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                    <div>
                        <h4 class="fw-bold mb-1 text-white">Verifikasi &amp; Disposisi Surat Masuk</h4>
                        <p class="mb-0 small" style="color: #dbeafe;">
                            Formulir terisi otomatis saat Anda mengklik Verifikasi pada Surat Masuk &bull;
                            <strong class="text-white"><?= htmlspecialchars($nama_profil, ENT_QUOTES, 'UTF-8'); ?></strong>
                            <?php if ($nip_profil !== ''): ?>
                                (NIP: <?= htmlspecialchars($nip_profil, ENT_QUOTES, 'UTF-8'); ?>)
                            <?php endif; ?>
                        </p>
                    </div>
                    <div>
                        <span class="badge-putih-sek"><i class="bi bi-person-badge-fill me-1"></i> SEKRETARIS</span>
                    </div>
                </div>
            </div>

            <?php if ($pesan_sukses !== ''): ?>
                <div class="alert alert-success alert-dismissible fade show shadow-sm rounded-3 mb-4" role="alert">
                    <i class="bi bi-check-circle-fill me-2"></i><?= htmlspecialchars($pesan_sukses, ENT_QUOTES, 'UTF-8'); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <?php if ($pesan_error !== ''): ?>
                <div class="alert alert-danger alert-dismissible fade show shadow-sm rounded-3 mb-4" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($pesan_error, ENT_QUOTES, 'UTF-8'); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <div class="card-form-verif">
                <?php if ($surat_dipilih): ?>
                    <form method="POST" action="verifikasi_surat.php?id=<?= (int)$id_verifikasi; ?>">
                        <input type="hidden" name="id_surat" value="<?= (int)$id_verifikasi; ?>">

                        <div class="section-title-verif">
                            <i class="bi bi-envelope-open me-1"></i> Informasi Surat
                        </div>

                        <div class="row g-3 mb-4">
                            <div class="col-md-4">
                                <label class="form-label">No. Agenda</label>
                                <input type="text" class="form-control" value="<?= htmlspecialchars($v_agenda, ENT_QUOTES, 'UTF-8'); ?>" readonly>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Nomor Surat</label>
                                <input type="text" class="form-control" value="<?= htmlspecialchars($v_no_sr, ENT_QUOTES, 'UTF-8'); ?>" readonly>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Asal Instansi</label>
                                <input type="text" class="form-control" value="<?= htmlspecialchars($v_asal, ENT_QUOTES, 'UTF-8'); ?>" readonly>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Jenis Surat</label>
                                <input type="text" class="form-control" value="<?= htmlspecialchars($v_jenis, ENT_QUOTES, 'UTF-8'); ?>" readonly>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Sifat Surat Awal (dari Pengirim)</label>
                                <input type="text" class="form-control" value="<?= htmlspecialchars($v_sifat_awl, ENT_QUOTES, 'UTF-8'); ?>" readonly>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Perihal</label>
                                <input type="text" class="form-control" value="<?= htmlspecialchars($v_hal, ENT_QUOTES, 'UTF-8'); ?>" readonly>
                            </div>
                        </div>

                        <div class="section-title-verif">
                            <i class="bi bi-paperclip me-1"></i> Dokumen Lampiran
                        </div>

                        <div class="box-berkas-surat mb-4">
                            <div class="d-flex align-items-center gap-2">
                                <i class="bi bi-file-earmark-text fs-4 text-primary"></i>
                                <div>
                                    <div class="fw-semibold small text-dark">
                                        Berkas Surat <?= $is_rahasia ? '<span class="badge bg-dark ms-1"><i class="bi bi-lock-fill me-1"></i>Rahasia</span>' : ''; ?>
                                    </div>
                                    <div class="text-muted small">
                                        <?= $url_file !== '' ? 'Klik tombol di samping untuk membuka dokumen pada tab baru.' : 'Tidak ada berkas lampiran pada surat ini.'; ?>
                                    </div>
                                </div>
                            </div>
                            <?php if ($url_file !== ''): ?>
                                <a href="<?= htmlspecialchars($url_file, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" class="btn btn-success btn-sm px-3 py-2 fw-semibold">
                                    <i class="bi bi-file-earmark-pdf-fill me-1"></i> Buka Dokumen Surat
                                </a>
                            <?php endif; ?>
                        </div>

                        <div class="section-title-verif">
                            <i class="bi bi-check2-square me-1"></i> Keputusan Verifikasi, Disposisi &amp; Sifat Surat
                        </div>

                        <div class="box-keputusan-verif mb-4">
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label">Keputusan Verifikasi <span class="text-danger">*</span></label>
                                    <select name="keputusan" id="keputusanVerif" class="form-select" required>
                                        <option value="">-- Pilih Keputusan --</option>
                                        <option value="Diproses">Terverifikasi / Diproses</option>
                                        <option value="Ditolak">Ditolak</option>
                                    </select>
                                </div>

                                <div class="col-md-4" id="blokTujuanDisposisi">
                                    <label class="form-label">Tujuan Disposisi <span class="text-danger" id="tandaWajibDisp">*</span></label>
                                    <select name="disposisi_kepada" id="tujuanDisposisi" class="form-select">
                                        <option value="">-- Pilih Tujuan Disposisi --</option>
                                        <?php foreach ($opsi_disposisi as $tujuan): ?>
                                            <option value="<?= htmlspecialchars($tujuan, ENT_QUOTES, 'UTF-8'); ?>" <?= ($v_disposisi === $tujuan) ? 'selected' : ''; ?>>
                                                <?= htmlspecialchars($tujuan, ENT_QUOTES, 'UTF-8'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                        <?php if ($v_disposisi !== '' && !in_array($v_disposisi, $opsi_disposisi, true)): ?>
                                            <option value="<?= htmlspecialchars($v_disposisi, ENT_QUOTES, 'UTF-8'); ?>" selected>
                                                <?= htmlspecialchars($v_disposisi, ENT_QUOTES, 'UTF-8'); ?>
                                            </option>
                                        <?php endif; ?>
                                    </select>
                                </div>

                                <div class="col-md-4" id="blokSifatTujuan">
                                    <label class="form-label">Sifat Surat untuk Tujuan Disposisi <span class="text-danger" id="tandaWajibSifat">*</span></label>
                                    <select name="sifat_surat" id="sifatTujuan" class="form-select">
                                        <option value="">-- Pilih Sifat Surat --</option>
                                        <?php foreach ($opsi_sifat as $sifat_opt): ?>
                                            <option value="<?= htmlspecialchars($sifat_opt, ENT_QUOTES, 'UTF-8'); ?>" <?= (strcasecmp($v_sifat_tjn, $sifat_opt) === 0) ? 'selected' : ''; ?>>
                                                <?= htmlspecialchars($sifat_opt, ENT_QUOTES, 'UTF-8'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="form-text small">
                                        Jika didisposisikan ke Bidang dengan sifat selain Rahasia, bidang dapat membuka dokumen.
                                    </div>
                                </div>

                                <div class="col-12">
                                    <label class="form-label" id="labelAlasanCatatan">
                                        Catatan Verifikasi / Alasan Penolakan <span class="text-danger d-none" id="tandaWajibAlasan">*</span>
                                    </label>
                                    <textarea name="alasan_catatan" id="alasanCatatan" rows="3" class="form-control" placeholder="Tulis catatan disposisi atau alasan apabila surat ditolak..."><?= htmlspecialchars($v_alasan_ct, ENT_QUOTES, 'UTF-8'); ?></textarea>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <a href="disposisi_masuk.php" class="btn btn-outline-secondary px-4 py-2 fw-semibold">
                                <i class="bi bi-arrow-left me-1"></i> Kembali ke Surat Masuk
                            </a>
                            <button type="submit" name="simpan_verifikasi" value="1" class="btn btn-primary px-4 py-2 fw-semibold">
                                <i class="bi bi-check2-circle me-1"></i> Simpan Verifikasi
                            </button>
                        </div>
                    </form>
                <?php else: ?>
                    <div class="text-center py-5">
                        <i class="bi bi-envelope-exclamation fs-1 text-muted d-block mb-3"></i>
                        <h5 class="fw-bold text-dark mb-1">Belum Ada Surat yang Dipilih untuk Diverifikasi</h5>
                        <p class="text-muted small mb-4">
                            Silakan buka menu <strong>Surat Masuk</strong> lalu klik tombol <strong>Verifikasi</strong> pada surat yang ingin diproses.
                        </p>
                        <a href="disposisi_masuk.php" class="btn btn-primary px-4 py-2 fw-semibold">
                            <i class="bi bi-envelope-paper me-1"></i> Ke Daftar Surat Masuk
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        (function () {
            var selKeputusan = document.getElementById('keputusanVerif');
            var selTujuan    = document.getElementById('tujuanDisposisi');
            var selSifat     = document.getElementById('sifatTujuan');
            var txtAlasan    = document.getElementById('alasanCatatan');
            var wajibDisp    = document.getElementById('tandaWajibDisp');
            var wajibSifat   = document.getElementById('tandaWajibSifat');
            var wajibAlasan  = document.getElementById('tandaWajibAlasan');
            var lblAlasan    = document.getElementById('labelAlasanCatatan');

            if (!selKeputusan) return;

            function sinkronFormVerif() {
                var val = (selKeputusan.value || '').toLowerCase();
                var ditolak = (val === 'ditolak');

                if (selTujuan) {
                    selTujuan.required = (!ditolak && val !== '');
                    selTujuan.disabled = ditolak;
                }
                if (selSifat) {
                    selSifat.required = (!ditolak && val !== '');
                    selSifat.disabled = ditolak;
                }
                if (wajibDisp) {
                    wajibDisp.classList.toggle('d-none', ditolak);
                }
                if (wajibSifat) {
                    wajibSifat.classList.toggle('d-none', ditolak);
                }
                if (txtAlasan) {
                    txtAlasan.required = ditolak;
                    txtAlasan.placeholder = ditolak
                        ? 'Wajib diisi: jelaskan alasan penolakan surat...'
                        : 'Tulis catatan disposisi atau alasan apabila surat ditolak...';
                }
                if (wajibAlasan) {
                    wajibAlasan.classList.toggle('d-none', !ditolak);
                }
                if (lblAlasan) {
                    lblAlasan.innerHTML = ditolak
                        ? 'Alasan Penolakan <span class="text-danger">*</span>'
                        : 'Catatan Verifikasi / Alasan Penolakan <span class="text-danger d-none" id="tandaWajibAlasan">*</span>';
                }
            }

            selKeputusan.addEventListener('change', sinkronFormVerif);
            sinkronFormVerif();
        })();
    </script>
</body>
</html>