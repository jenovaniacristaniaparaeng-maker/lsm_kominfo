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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: kirim_surat.php");
    exit();
}

if (!isset($koneksi) || !($koneksi instanceof mysqli)) {
    die("Koneksi database tidak tersedia.");
}

function kolom_ada_proses(mysqli $koneksi, string $tabel, string $kolom): bool {
    $t = mysqli_real_escape_string($koneksi, $tabel);
    $k = mysqli_real_escape_string($koneksi, $kolom);
    $res = mysqli_query($koneksi, "SHOW COLUMNS FROM `$t` LIKE '$k'");
    return ($res && mysqli_num_rows($res) > 0);
}

function cari_kolom_proses(mysqli $koneksi, string $tabel, array $kandidat): string {
    foreach ($kandidat as $kolom) {
        if (kolom_ada_proses($koneksi, $tabel, $kolom)) {
            return $kolom;
        }
    }
    return '';
}

$id_login    = (int)($_SESSION['user_id'] ?? $_SESSION['id_user'] ?? $_SESSION['id'] ?? (is_array($_SESSION['user'] ?? null) ? ($_SESSION['user']['id'] ?? 0) : 0));
$user_login  = trim((string)($_SESSION['username'] ?? (is_array($_SESSION['user'] ?? null) ? ($_SESSION['user']['username'] ?? '') : '')));
$email_login = trim((string)($_SESSION['email'] ?? $_SESSION['google_email'] ?? (is_array($_SESSION['user'] ?? null) ? ($_SESSION['user']['email'] ?? '') : '')));

// [LETAK 1] Lengkapi $id_login dari tabel users atau $_POST['user_id'] jika belum ada di session
if ($id_login === 0) {
    $cek_users = mysqli_query($koneksi, "SHOW TABLES LIKE 'users'");
    if ($cek_users && mysqli_num_rows($cek_users) > 0) {
        if ($email_login !== '') {
            $stmt_u = mysqli_prepare($koneksi, "SELECT `id` FROM `users` WHERE LOWER(TRIM(`email`)) = LOWER(?) LIMIT 1");
            if ($stmt_u) {
                mysqli_stmt_bind_param($stmt_u, 's', $email_login);
                mysqli_stmt_execute($stmt_u);
                $res_u = mysqli_stmt_get_result($stmt_u);
                if ($row_u = mysqli_fetch_assoc($res_u)) {
                    $id_login = (int)($row_u['id'] ?? 0);
                }
                mysqli_stmt_close($stmt_u);
            }
        } elseif ($user_login !== '') {
            $stmt_u = mysqli_prepare($koneksi, "SELECT `id` FROM `users` WHERE LOWER(TRIM(`username`)) = LOWER(?) LIMIT 1");
            if ($stmt_u) {
                mysqli_stmt_bind_param($stmt_u, 's', $user_login);
                mysqli_stmt_execute($stmt_u);
                $res_u = mysqli_stmt_get_result($stmt_u);
                if ($row_u = mysqli_fetch_assoc($res_u)) {
                    $id_login = (int)($row_u['id'] ?? 0);
                }
                mysqli_stmt_close($stmt_u);
            }
        }
    }
}
if ($id_login === 0 && !empty($_POST['user_id'])) {
    $id_login = (int)$_POST['user_id'];
}

$nama_sesi = '';
if (!empty($_SESSION['google_name'])) {
    $nama_sesi = (string)$_SESSION['google_name'];
} elseif (is_array($_SESSION['user'] ?? null) && !empty($_SESSION['user']['name'])) {
    $nama_sesi = (string)$_SESSION['user']['name'];
} elseif (is_array($_SESSION['user'] ?? null) && !empty($_SESSION['user']['nama'])) {
    $nama_sesi = (string)$_SESSION['user']['nama'];
} elseif (!empty($_SESSION['nama'])) {
    $nama_sesi = (string)$_SESSION['nama'];
} elseif (!empty($_SESSION['name'])) {
    $nama_sesi = (string)$_SESSION['name'];
}

$asal_instansi = trim((string)($_POST['asal_instansi'] ?? ''));
$no_telepon    = trim((string)($_POST['no_telepon'] ?? ''));
$no_surat      = trim((string)($_POST['no_surat'] ?? ''));
$tanggal_surat = trim((string)($_POST['tanggal_surat'] ?? date('Y-m-d')));
$jenis_surat   = trim((string)($_POST['jenis_surat'] ?? ''));
$sifat_surat   = trim((string)($_POST['sifat_surat'] ?? 'Biasa'));
$perihal       = trim((string)($_POST['perihal'] ?? ''));

$nama_file_simpan = '';
if (!empty($_FILES['file_surat']['name']) && (int)($_FILES['file_surat']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
    $ext = strtolower((string)pathinfo((string)$_FILES['file_surat']['name'], PATHINFO_EXTENSION));
    if (in_array($ext, ['pdf', 'jpg', 'jpeg', 'png'], true)) {
        $dir_uploads = __DIR__ . '/../uploads';
        if (!is_dir($dir_uploads)) {
            @mkdir($dir_uploads, 0775, true);
        }
        $nama_baru = 'SURAT_' . time() . '_' . mt_rand(100, 999) . '.' . $ext;
        if (move_uploaded_file((string)$_FILES['file_surat']['tmp_name'], $dir_uploads . '/' . $nama_baru)) {
            $nama_file_simpan = $nama_baru;
        }
    }
}

$k_agenda  = cari_kolom_proses($koneksi, 'surat_masuk', ['no_agenda', 'nomor_agenda']);
$k_tgl_in  = cari_kolom_proses($koneksi, 'surat_masuk', ['tanggal_input', 'tanggal_masuk', 'tgl_masuk']);
$k_no_sr   = cari_kolom_proses($koneksi, 'surat_masuk', ['no_surat', 'nomor_surat']);
$k_tgl_sr  = cari_kolom_proses($koneksi, 'surat_masuk', ['tanggal_surat', 'tgl_surat']);
$k_asal    = cari_kolom_proses($koneksi, 'surat_masuk', ['asal_instansi', 'asal_surat', 'instansi_pengirim', 'pengirim']);
$k_telp    = cari_kolom_proses($koneksi, 'surat_masuk', ['no_telepon', 'no_hp', 'telepon', 'whatsapp', 'no_wa']);
$k_jenis   = cari_kolom_proses($koneksi, 'surat_masuk', ['jenis_surat', 'jenis']);
$k_sifat   = cari_kolom_proses($koneksi, 'surat_masuk', ['sifat_surat', 'sifat']);
$k_hal     = cari_kolom_proses($koneksi, 'surat_masuk', ['perihal', 'subjek', 'hal']);
$k_file    = cari_kolom_proses($koneksi, 'surat_masuk', ['file_surat', 'lampiran', 'berkas_surat', 'dokumen_surat']);
$k_status  = cari_kolom_proses($koneksi, 'surat_masuk', ['status_surat', 'status']);
$k_id_peng = cari_kolom_proses($koneksi, 'surat_masuk', ['user_id', 'id_user', 'id_pengirim', 'pengirim_id']);
$k_em_peng = cari_kolom_proses($koneksi, 'surat_masuk', ['email_pengirim', 'email', 'pengirim_email']);
$k_nm_peng = cari_kolom_proses($koneksi, 'surat_masuk', ['nama_pengirim', 'username']);

$no_agenda_baru = '';
if ($k_agenda !== '') {
    $q_max = mysqli_query($koneksi, "SELECT MAX(`id`) AS max_id FROM `surat_masuk`");
    $d_max = $q_max ? mysqli_fetch_assoc($q_max) : null;
    $next_no = (int)($d_max['max_id'] ?? 0) + 1;
    $no_agenda_baru = 'AGD-' . str_pad((string)$next_no, 3, '0', STR_PAD_LEFT);
}

$cols = [];
$vals = [];

// [LETAK 2] Simpan user_id pada setiap surat baru yang dikirim
if ($k_id_peng !== '' && $id_login > 0) {
    $cols[] = "`$k_id_peng`";
    $vals[] = (int)$id_login;
}
if ($k_agenda !== '')  { $cols[] = "`$k_agenda`";  $vals[] = "'" . mysqli_real_escape_string($koneksi, $no_agenda_baru) . "'"; }
if ($k_tgl_in !== '')  { $cols[] = "`$k_tgl_in`";  $vals[] = "'" . mysqli_real_escape_string($koneksi, date('Y-m-d')) . "'"; }
if ($k_no_sr !== '')   { $cols[] = "`$k_no_sr`";   $vals[] = "'" . mysqli_real_escape_string($koneksi, $no_surat) . "'"; }
if ($k_tgl_sr !== '')  { $cols[] = "`$k_tgl_sr`";  $vals[] = "'" . mysqli_real_escape_string($koneksi, $tanggal_surat) . "'"; }
if ($k_asal !== '')    { $cols[] = "`$k_asal`";    $vals[] = "'" . mysqli_real_escape_string($koneksi, $asal_instansi) . "'"; }
if ($k_telp !== '')    { $cols[] = "`$k_telp`";    $vals[] = "'" . mysqli_real_escape_string($koneksi, $no_telepon) . "'"; }
if ($k_jenis !== '')   { $cols[] = "`$k_jenis`";   $vals[] = "'" . mysqli_real_escape_string($koneksi, $jenis_surat) . "'"; }
if ($k_sifat !== '')   { $cols[] = "`$k_sifat`";   $vals[] = "'" . mysqli_real_escape_string($koneksi, $sifat_surat) . "'"; }
if ($k_hal !== '')     { $cols[] = "`$k_hal`";     $vals[] = "'" . mysqli_real_escape_string($koneksi, $perihal) . "'"; }
if ($k_file !== '')    { $cols[] = "`$k_file`";    $vals[] = "'" . mysqli_real_escape_string($koneksi, $nama_file_simpan) . "'"; }
if ($k_status !== '')  { $cols[] = "`$k_status`";  $vals[] = "'Masuk'"; }
if ($k_em_peng !== '' && $email_login !== '') {
    $cols[] = "`$k_em_peng`";
    $vals[] = "'" . mysqli_real_escape_string($koneksi, $email_login) . "'";
}
if ($k_nm_peng !== '') {
    $identitas_pengirim = $nama_sesi !== '' ? $nama_sesi : ($user_login !== '' ? $user_login : $asal_instansi);
    $cols[] = "`$k_nm_peng`";
    $vals[] = "'" . mysqli_real_escape_string($koneksi, $identitas_pengirim) . "'";
}

if (empty($cols)) {
    die("Tidak ada kolom yang cocok pada tabel surat_masuk.");
}

$sql_insert = "INSERT INTO `surat_masuk` (" . implode(", ", $cols) . ") VALUES (" . implode(", ", $vals) . ")";

if (mysqli_query($koneksi, $sql_insert)) {
    echo "<script>alert('Surat berhasil dikirim!'); window.location.href='riwayat_surat.php';</script>";
    exit();
} else {
    die("Gagal menyimpan surat ke database: " . mysqli_error($koneksi));
}