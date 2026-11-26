<?php
session_start();
include '../koneksi.php';

if (!isset($_SESSION['user']) && !isset($_SESSION['id_user']) && !isset($_SESSION['username'])) {
    header("location: ../login.php");
    exit();
}

$role_session = $_SESSION['role'] ?? ($_SESSION['level'] ?? (is_array($_SESSION['user'] ?? null) ? ($_SESSION['user']['role'] ?? ($_SESSION['user']['level'] ?? '')) : ''));
$role_clean = strtolower(trim((string)$role_session));
if ($role_clean !== 'sekretaris') {
    echo "<script>alert('Akses ditolak! Halaman ini khusus untuk Sekretaris.'); window.location='../login.php';</script>";
    exit();
}

$boleh_lihat_rahasia = in_array($role_clean, ['sekretaris', 'kepala_dinas', 'kepala dinas', 'kadis'], true);

function kolom_ada_sek_arsip(mysqli $koneksi, string $tabel, string $kolom): bool {
    $t = mysqli_real_escape_string($koneksi, $tabel);
    $k = mysqli_real_escape_string($koneksi, $kolom);
    $res = mysqli_query($koneksi, "SHOW COLUMNS FROM `$t` LIKE '$k'");
    return ($res && mysqli_num_rows($res) > 0);
}

function cari_kolom_sek_arsip(mysqli $koneksi, string $tabel, array $kandidat): string {
    foreach ($kandidat as $kolom) {
        if (kolom_ada_sek_arsip($koneksi, $tabel, $kolom)) {
            return $kolom;
        }
    }
    return '';
}

function pastikan_kolom_sembunyi_sek_arsip(mysqli $koneksi): string {
    $kolom_ada = cari_kolom_sek_arsip($koneksi, 'surat_masuk', [
        'dihapus_sekretaris',
        'sembunyikan_sekretaris',
        'is_hidden_sekretaris',
    ]);
    if ($kolom_ada !== '') {
        return $kolom_ada;
    }
    @mysqli_query($koneksi, "ALTER TABLE `surat_masuk` ADD COLUMN `dihapus_sekretaris` TINYINT(1) NOT NULL DEFAULT 0");
    return kolom_ada_sek_arsip($koneksi, 'surat_masuk', 'dihapus_sekretaris') ? 'dihapus_sekretaris' : '';
}

function cari_logo_sangihe_sek_arsip(): string {
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

function format_no_agenda_sek_arsip($raw_agenda): string {
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

function resolve_url_berkas_sek_arsip(string $raw_file): string {
    $bersih = trim(str_replace('\\', '/', $raw_file));
    if ($bersih === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $bersih)) {
        return $bersih;
    }
    $nama_saja = basename($bersih);
    $kandidat_rel = [
        ltrim($bersih, '/'),
        '../' . ltrim($bersih, '/'),
        '../uploads/' . $nama_saja,
        '../uploads/bukti_kadis/' . $nama_saja,
        'uploads/' . $nama_saja,
    ];
    foreach ($kandidat_rel as $rel) {
        if (file_exists(__DIR__ . '/' . $rel) && is_file(__DIR__ . '/' . $rel)) {
            return $rel;
        }
    }
    return (strpos($bersih, '../') === 0) ? $bersih : ('../uploads/' . $nama_saja);
}

$url_logo        = cari_logo_sangihe_sek_arsip();
$kol_sembunyi_sk = pastikan_kolom_sembunyi_sek_arsip($koneksi);
$kol_alasan      = cari_kolom_sek_arsip($koneksi, 'surat_masuk', [
    'alasan_penolakan',
    'catatan_penolakan',
    'alasan_tolak',
    'catatan_tolak',
    'keterangan_penolakan',
    'catatan_verifikasi',
    'alasan',
]);

$pesan_alert = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['hapus_arsip_sekretaris'])) {
    $id_surat_hapus = (int)($_POST['id_surat'] ?? 0);
    if ($id_surat_hapus > 0 && $kol_sembunyi_sk !== '') {
        $stmt_del = mysqli_prepare(
            $koneksi,
            "UPDATE `surat_masuk` SET `$kol_sembunyi_sk` = 1 WHERE `id` = ? LIMIT 1"
        );
        if ($stmt_del) {
            mysqli_stmt_bind_param($stmt_del, 'i', $id_surat_hapus);
            mysqli_stmt_execute($stmt_del);
            mysqli_stmt_close($stmt_del);
            $_SESSION['flash_ar_sek_tgl'] = 'Surat berhasil dihapus dari tampilan arsip Sekretaris (data tetap tersimpan untuk Pengirim dan penerima disposisi).';
        }
    }
    header("Location: " . basename($_SERVER['PHP_SELF']));
    exit();
}

if (!empty($_SESSION['flash_ar_sek_tgl'])) {
    $pesan_alert = (string)$_SESSION['flash_ar_sek_tgl'];
    unset($_SESSION['flash_ar_sek_tgl']);
}

$cek_bukti = mysqli_query($koneksi, "SHOW COLUMNS FROM surat_masuk LIKE 'bukti_dukung'");
$ada_bukti = ($cek_bukti && mysqli_num_rows($cek_bukti) > 0);

$syarat_where = ["LOWER(TRIM(COALESCE(status_surat, ''))) IN ('selesai', 'ditolak')"];
if ($kol_sembunyi_sk !== '') {
    $syarat_where[] = "COALESCE(`$kol_sembunyi_sk`, 0) = 0";
}
$where_sql = "WHERE " . implode(" AND ", $syarat_where);

$query = mysqli_query($koneksi, "SELECT * FROM surat_masuk $where_sql ORDER BY id DESC");
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Arsip Surat - Sekretaris</title>
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
        .sidebar .nav-link:hover, .sidebar .nav-link.active {
            background-color: #3b6998;
            color: #fff;
            font-weight: 600;
        }
        .main-content {
            margin-left: 260px;
            padding: 30px;
        }
        .header-banner-sek {
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
        .search-arsip-box {
            max-width: 440px;
            width: 100%;
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
            padding: 2px 6px;
            font-size: 10px;
        }
        @media (max-width: 768px) {
            .sidebar { position: relative; width: 100%; min-height: auto; border-right: none; border-bottom: 1px solid #e2e8f0; }
            .main-content { margin-left: 0; padding: 16px; }
            .search-arsip-box { max-width: 100%; }
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
                <li class="nav-item"><a href="verifikasi_surat.php" class="nav-link"><i class="bi bi-check2-circle me-2"></i> Verifikasi Surat</a></li>
                <li class="nav-item"><a href="lembar_kendali.php" class="nav-link"><i class="bi bi-diagram-3-fill me-2"></i> Lembar Kendali</a></li>
                <li class="nav-item"><a href="laporan.php" class="nav-link"><i class="bi bi-file-earmark-bar-graph-fill me-2"></i> Laporan</a></li>
                <li class="nav-item"><a href="arsip.php" class="nav-link active"><i class="bi bi-folder-fill me-2"></i> Arsip Surat</a></li>
                <li class="nav-item"><a href="dashboard.php?menu=profil" class="nav-link"><i class="bi bi-gear-fill me-2"></i> Pengaturan Profil</a></li>
            </ul>
        </div>
        <hr class="text-secondary">
        <div><a href="../logout.php" class="nav-link text-danger"><i class="bi bi-box-arrow-left me-2"></i> Keluar Sistem</a></div>
    </div>

    <div class="main-content">
        <div class="container-fluid">
            <div class="header-banner-sek mb-4">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                    <div>
                        <h4 class="fw-bold mb-1 text-white">Arsip Surat</h4>
                        <p class="mb-0 small" style="color: #dbeafe;">Daftar surat yang telah selesai maupun ditolak beserta bukti dukung dan alasannya</p>
                    </div>
                    <div>
                        <span class="badge-putih-sek"><i class="bi bi-person-badge-fill me-1"></i> SEKRETARIS</span>
                    </div>
                </div>
            </div>

            <?php if ($pesan_alert !== ''): ?>
                <div class="alert alert-success alert-dismissible fade show shadow-sm" role="alert">
                    <i class="bi bi-check-circle-fill me-2"></i><?= htmlspecialchars($pesan_alert, ENT_QUOTES, 'UTF-8'); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <div class="card border-0 shadow-sm p-4 bg-white">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-3">
                    <h5 class="fw-bold text-dark mb-0"><i class="bi bi-folder-check me-2" style="color: #3b6998;"></i>Daftar Arsip Surat</h5>
                    <div class="search-arsip-box">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text bg-white text-muted"><i class="bi bi-search"></i></span>
                            <input type="text" id="cariArsipSurat" class="form-control" placeholder="Cari no. agenda, asal instansi, no. surat, perihal..." autocomplete="off">
                            <button type="button" id="resetCariArsip" class="btn btn-outline-secondary d-none" title="Hapus pencarian">
                                <i class="bi bi-x-lg"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-bordered table-striped table-custom align-middle mb-0" id="tabelArsipSurat">
                        <thead class="text-center">
                            <tr>
                                <th width="4%">No</th>
                                <th width="8%">No. Agenda</th>
                                <th width="13%">Asal Instansi</th>
                                <th width="11%">Nomor Surat</th>
                                <th width="19%">Perihal</th>
                                <th width="13%">Status &amp; Alasan</th>
                                <th width="12%">Dokumen Surat</th>
                                <th width="15%">Bukti Dukung</th>
                                <th width="9%">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $no = 1;
                            if ($query && mysqli_num_rows($query) > 0) {
                                while ($row = mysqli_fetch_assoc($query)) {
                                    $id_row        = (int)($row['id'] ?? 0);
                                    $status_raw    = trim((string)($row['status_surat'] ?? 'Selesai'));
                                    $is_ditolak    = (strtolower($status_raw) === 'ditolak');
                                    $is_rahasia    = (strtolower(trim((string)($row['sifat_surat'] ?? ''))) === 'rahasia');
                                    $bukti         = $ada_bukti ? ($row['bukti_dukung'] ?? '') : '';
                                    $alasan_tolak  = ($kol_alasan !== '' && !empty($row[$kol_alasan])) ? trim((string)$row[$kol_alasan]) : '';
                                    $agenda_tampil = format_no_agenda_sek_arsip($row['no_agenda'] ?? '');
                                    $url_dok       = !empty($row['file_surat']) ? resolve_url_berkas_sek_arsip((string)$row['file_surat']) : '';
                                    $boleh_buka_dok = ($url_dok !== '') && (!$is_rahasia || $boleh_lihat_rahasia);
                                    $url_bukti     = ($bukti !== '') ? resolve_url_berkas_sek_arsip((string)$bukti) : '';
                            ?>
                            <tr class="baris-arsip">
                                <td class="text-center nomor-urut"><?= $no++; ?></td>
                                <td class="text-center"><span class="badge bg-secondary rounded-pill px-2 py-1"><?= htmlspecialchars($agenda_tampil, ENT_QUOTES, 'UTF-8'); ?></span></td>
                                <td><?= htmlspecialchars($row['asal_instansi'] ?? '-'); ?></td>
                                <td>
                                    <?= htmlspecialchars($row['no_surat'] ?? '-'); ?>
                                    <?php if ($is_rahasia): ?>
                                        <span class="badge bg-dark ms-1" style="font-size: 9px;"><i class="bi bi-shield-lock-fill me-1"></i>Rahasia</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($row['perihal'] ?? '-'); ?></td>
                                <td class="text-center">
                                    <?php if ($is_ditolak): ?>
                                        <span class="badge bg-danger">Ditolak</span>
                                        <div class="text-danger fw-medium mt-1 text-start" style="font-size: 10px;">
                                            Alasan: <?= htmlspecialchars($alasan_tolak !== '' ? $alasan_tolak : 'Tidak ada catatan alasan.', ENT_QUOTES, 'UTF-8'); ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="badge bg-success"><?= htmlspecialchars($status_raw !== '' ? $status_raw : 'Selesai', ENT_QUOTES, 'UTF-8'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($boleh_buka_dok): ?>
                                        <a href="<?= htmlspecialchars($url_dok, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" class="btn btn-success btn-xs" title="<?= $is_rahasia ? 'Lihat Dokumen Rahasia' : 'Lihat PDF'; ?>">
                                            <i class="bi bi-file-earmark-pdf-fill"></i><?= $is_rahasia ? ' Rahasia' : ''; ?>
                                        </a>
                                    <?php elseif ($is_rahasia): ?>
                                        <span class="badge bg-secondary" style="font-size: 9px;"><i class="bi bi-lock-fill"></i> Rahasia</span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($url_bukti !== ''): ?>
                                        <a href="<?= htmlspecialchars($url_bukti, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" class="btn btn-primary btn-xs">
                                            <i class="bi bi-file-earmark-check-fill me-1"></i> Lihat Bukti
                                        </a>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($id_row > 0): ?>
                                        <form method="POST" action="" onsubmit="return confirm('Hapus surat ini dari tampilan arsip Sekretaris? Data tetap tersimpan untuk Pengirim dan penerima disposisi.');" class="d-inline">
                                            <input type="hidden" name="id_surat" value="<?= $id_row; ?>">
                                            <button type="submit" name="hapus_arsip_sekretaris" value="1" class="btn btn-danger btn-xs" title="Hapus dari tampilan arsip Sekretaris">
                                                <i class="bi bi-trash"></i> Hapus
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php
                                }
                            } else {
                            ?>
                            <tr id="barisKosongAwal"><td colspan="9" class="text-center text-muted py-4">Belum ada dokumen arsip surat.</td></tr>
                            <?php } ?>
                            <tr id="barisTidakDitemukan" class="d-none">
                                <td colspan="9" class="text-center text-muted py-4">Surat yang dicari tidak ditemukan pada arsip.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script>
        (function () {
            const inputCari = document.getElementById('cariArsipSurat');
            const btnReset = document.getElementById('resetCariArsip');
            const barisArsip = Array.from(document.querySelectorAll('#tabelArsipSurat tbody tr.baris-arsip'));
            const barisKosong = document.getElementById('barisTidakDitemukan');

            function filterArsip() {
                if (!inputCari || barisArsip.length === 0) return;
                const kataKunci = inputCari.value.toLowerCase().trim();
                if (btnReset) {
                    btnReset.classList.toggle('d-none', kataKunci === '');
                }

                let jumlahTampil = 0;
                barisArsip.forEach((tr) => {
                    const teksBaris = tr.textContent.toLowerCase();
                    const cocok = kataKunci === '' || teksBaris.includes(kataKunci);
                    tr.classList.toggle('d-none', !cocok);
                    if (cocok) {
                        jumlahTampil++;
                        const selNo = tr.querySelector('.nomor-urut');
                        if (selNo) selNo.textContent = jumlahTampil;
                    }
                });

                if (barisKosong) {
                    barisKosong.classList.toggle('d-none', jumlahTampil > 0);
                }
            }

            if (inputCari) {
                inputCari.addEventListener('input', filterArsip);
                inputCari.addEventListener('keydown', function (e) {
                    if (e.key === 'Escape') {
                        inputCari.value = '';
                        filterArsip();
                    }
                });
            }

            if (btnReset) {
                btnReset.addEventListener('click', function () {
                    inputCari.value = '';
                    filterArsip();
                    inputCari.focus();
                });
            }
        })();
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>