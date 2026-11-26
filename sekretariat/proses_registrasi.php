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

function kolom_ada_pr(mysqli $koneksi, string $tabel, string $kolom): bool {
    $t = mysqli_real_escape_string($koneksi, $tabel);
    $k = mysqli_real_escape_string($koneksi, $kolom);
    $res = mysqli_query($koneksi, "SHOW COLUMNS FROM `$t` LIKE '$k'");
    return ($res && mysqli_num_rows($res) > 0);
}

function cari_kolom_pr(mysqli $koneksi, string $tabel, array $kandidat): string {
    foreach ($kandidat as $kolom) {
        if (kolom_ada_pr($koneksi, $tabel, $kolom)) {
            return $kolom;
        }
    }
    return '';
}

$id_surat         = trim((string)($_POST['id_surat'] ?? ''));
$no_agenda        = trim((string)($_POST['no_agenda'] ?? ''));
$asal_instansi    = trim((string)($_POST['asal_instansi'] ?? ''));
$no_surat         = trim((string)($_POST['no_surat'] ?? ''));
$tanggal_surat    = trim((string)($_POST['tanggal_surat'] ?? ''));
$sifat_surat      = trim((string)($_POST['sifat_surat'] ?? 'Biasa'));
$tujuan_disposisi = trim((string)($_POST['tujuan_disposisi'] ?? ($_POST['disposisi_kepada'] ?? '')));
$perihal          = trim((string)($_POST['perihal'] ?? ''));
$tanggal_masuk    = date('Y-m-d');
$file_lama        = basename(trim((string)($_POST['file_lama'] ?? '')));

$nama_file_db = $file_lama;

if (isset($_FILES['file_surat']) && (int)($_FILES['file_surat']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
    $nama_file   = (string)$_FILES['file_surat']['name'];
    $tmp_file    = (string)$_FILES['file_surat']['tmp_name'];

    $ekstensi_diperbolehkan = ['pdf', 'jpg', 'jpeg', 'png'];
    $bagian_nama = explode('.', $nama_file);
    $ekstensi = strtolower((string)end($bagian_nama));

    if (in_array($ekstensi, $ekstensi_diperbolehkan, true)) {
        $folder_tujuan_dir = __DIR__ . '/../uploads/';

        if (!file_exists($folder_tujuan_dir)) {
            mkdir($folder_tujuan_dir, 0775, true);
        }

        $nama_file_baru = 'SURAT_' . time() . '_' . mt_rand(100, 999) . '.' . $ekstensi;
        $folder_tujuan  = $folder_tujuan_dir . $nama_file_baru;

        if (move_uploaded_file($tmp_file, $folder_tujuan)) {
            $nama_file_db = $nama_file_baru;

            if ($file_lama !== '' && file_exists($folder_tujuan_dir . $file_lama)) {
                @unlink($folder_tujuan_dir . $file_lama);
            }
        }
    }
}

$kol_agenda   = cari_kolom_pr($koneksi, 'surat_masuk', ['no_agenda', 'nomor_agenda']);
$kol_tgl_in   = cari_kolom_pr($koneksi, 'surat_masuk', ['tanggal_masuk', 'tanggal_input', 'tgl_masuk']);
$kol_asal     = cari_kolom_pr($koneksi, 'surat_masuk', ['asal_instansi', 'asal_surat', 'instansi_pengirim']);
$kol_no_surat = cari_kolom_pr($koneksi, 'surat_masuk', ['no_surat', 'nomor_surat']);
$kol_tgl_sr   = cari_kolom_pr($koneksi, 'surat_masuk', ['tanggal_surat', 'tgl_surat']);
$kol_sifat    = cari_kolom_pr($koneksi, 'surat_masuk', ['sifat_surat', 'sifat']);
$kol_tujuan   = cari_kolom_pr($koneksi, 'surat_masuk', ['tujuan_disposisi', 'disposisi_kepada', 'disposisi_ke']);
$kol_perihal  = cari_kolom_pr($koneksi, 'surat_masuk', ['perihal', 'subjek', 'hal']);
$kol_file     = cari_kolom_pr($koneksi, 'surat_masuk', ['file_surat', 'lampiran', 'berkas_surat']);
$kol_status   = cari_kolom_pr($koneksi, 'surat_masuk', ['status_surat', 'status']);
$kol_posisi   = cari_kolom_pr($koneksi, 'surat_masuk', ['penerima_sekarang', 'posisi_surat']);

$e_agenda     = mysqli_real_escape_string($koneksi, $no_agenda);
$e_tgl_masuk  = mysqli_real_escape_string($koneksi, $tanggal_masuk);
$e_asal       = mysqli_real_escape_string($koneksi, $asal_instansi);
$e_no_surat   = mysqli_real_escape_string($koneksi, $no_surat);
$e_tgl_surat  = mysqli_real_escape_string($koneksi, $tanggal_surat);
$e_sifat      = mysqli_real_escape_string($koneksi, $sifat_surat);
$e_tujuan     = mysqli_real_escape_string($koneksi, $tujuan_disposisi);
$e_perihal    = mysqli_real_escape_string($koneksi, $perihal);
$e_file_db    = mysqli_real_escape_string($koneksi, $nama_file_db);

if ($id_surat !== '' && is_numeric($id_surat)) {
    $id_int = (int)$id_surat;
    $set_parts = [];

    if ($kol_agenda !== '' && $no_agenda !== '') {
        $set_parts[] = "`$kol_agenda` = '$e_agenda'";
    }
    if ($kol_asal !== '') {
        $set_parts[] = "`$kol_asal` = '$e_asal'";
    }
    if ($kol_no_surat !== '') {
        $set_parts[] = "`$kol_no_surat` = '$e_no_surat'";
    }
    if ($kol_tgl_sr !== '') {
        $set_parts[] = "`$kol_tgl_sr` = '$e_tgl_surat'";
    }
    if ($kol_sifat !== '') {
        $set_parts[] = "`$kol_sifat` = '$e_sifat'";
    }
    if ($kol_tujuan !== '') {
        $set_parts[] = "`$kol_tujuan` = '$e_tujuan'";
    }
    if ($kol_perihal !== '') {
        $set_parts[] = "`$kol_perihal` = '$e_perihal'";
    }
    if ($kol_file !== '') {
        $set_parts[] = "`$kol_file` = '$e_file_db'";
    }
    if ($tujuan_disposisi !== '') {
        if ($kol_status !== '') {
            $set_parts[] = "`$kol_status` = 'Diproses'";
        }
        if ($kol_posisi !== '') {
            $set_parts[] = "`$kol_posisi` = '$e_tujuan'";
        }
    }

    $query = "UPDATE `surat_masuk` SET " . implode(", ", $set_parts) . " WHERE `id` = $id_int";
} else {
    if ($no_agenda === '') {
        $q_agenda = mysqli_query($koneksi, "SELECT MAX(`id`) AS max_id FROM `surat_masuk`");
        $d_agenda = $q_agenda ? mysqli_fetch_assoc($q_agenda) : null;
        $next_id  = (int)($d_agenda['max_id'] ?? 0) + 1;
        $no_agenda = 'AGD-' . str_pad((string)$next_id, 3, '0', STR_PAD_LEFT);
        $e_agenda = mysqli_real_escape_string($koneksi, $no_agenda);
    }

    $cols = [];
    $vals = [];

    if ($kol_agenda !== '')   { $cols[] = "`$kol_agenda`";   $vals[] = "'$e_agenda'"; }
    if ($kol_tgl_in !== '')   { $cols[] = "`$kol_tgl_in`";   $vals[] = "'$e_tgl_masuk'"; }
    if ($kol_asal !== '')     { $cols[] = "`$kol_asal`";     $vals[] = "'$e_asal'"; }
    if ($kol_no_surat !== '') { $cols[] = "`$kol_no_surat`"; $vals[] = "'$e_no_surat'"; }
    if ($kol_tgl_sr !== '')   { $cols[] = "`$kol_tgl_sr`";   $vals[] = "'$e_tgl_surat'"; }
    if ($kol_sifat !== '')    { $cols[] = "`$kol_sifat`";    $vals[] = "'$e_sifat'"; }
    if ($kol_tujuan !== '')   { $cols[] = "`$kol_tujuan`";   $vals[] = "'$e_tujuan'"; }
    if ($kol_perihal !== '')  { $cols[] = "`$kol_perihal`";  $vals[] = "'$e_perihal'"; }
    if ($kol_file !== '')     { $cols[] = "`$kol_file`";     $vals[] = "'$e_file_db'"; }
    if ($kol_status !== '') {
        $cols[] = "`$kol_status`";
        $vals[] = ($tujuan_disposisi !== '') ? "'Diproses'" : "'Masuk'";
    }
    if ($kol_posisi !== '' && $tujuan_disposisi !== '') {
        $cols[] = "`$kol_posisi`";
        $vals[] = "'$e_tujuan'";
    }

    $query = "INSERT INTO `surat_masuk` (" . implode(", ", $cols) . ") VALUES (" . implode(", ", $vals) . ")";
}

if (mysqli_query($koneksi, $query)) {
    header("Location: surat_masuk.php?status=sukses");
    exit();
} else {
    echo "Gagal menyimpan data ke database: " . htmlspecialchars(mysqli_error($koneksi), ENT_QUOTES, 'UTF-8');
}
?>