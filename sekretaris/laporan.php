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

function cari_logo_sangihe_sek_lap(): string {
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

function format_no_agenda_sek_lap($raw_agenda): string {
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

function resolve_url_berkas_sek_lap(string $raw_file): string {
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
        'uploads/' . $nama_saja,
    ];
    foreach ($kandidat_rel as $rel) {
        if (file_exists(__DIR__ . '/' . $rel) && is_file(__DIR__ . '/' . $rel)) {
            return $rel;
        }
    }
    return (strpos($bersih, '../') === 0) ? $bersih : ('../uploads/' . $nama_saja);
}

$url_logo = cari_logo_sangihe_sek_lap();

$filter_status = $_GET['status'] ?? 'Semua';
$tgl_awal = $_GET['tgl_awal'] ?? '';
$tgl_akhir = $_GET['tgl_akhir'] ?? '';

$where_clauses = [];
if ($filter_status === 'Diproses') {
    $where_clauses[] = "status_surat = 'Diproses'";
} elseif ($filter_status === 'Selesai') {
    $where_clauses[] = "status_surat = 'Selesai'";
}

if (!empty($tgl_awal) && !empty($tgl_akhir)) {
    $awal_safe = mysqli_real_escape_string($koneksi, $tgl_awal);
    $akhir_safe = mysqli_real_escape_string($koneksi, $tgl_akhir);
    $where_clauses[] = "DATE(tanggal_input) BETWEEN '$awal_safe' AND '$akhir_safe'";
}

$where_sql = !empty($where_clauses) ? (" WHERE " . implode(" AND ", $where_clauses)) : "";

$q_all = mysqli_query($koneksi, "SELECT COUNT(*) as total FROM surat_masuk");
$rekap_all = (int)(mysqli_fetch_assoc($q_all)['total'] ?? 0);

$q_proc = mysqli_query($koneksi, "SELECT COUNT(*) as total FROM surat_masuk WHERE status_surat = 'Diproses'");
$rekap_proc = (int)(mysqli_fetch_assoc($q_proc)['total'] ?? 0);

$q_done = mysqli_query($koneksi, "SELECT COUNT(*) as total FROM surat_masuk WHERE status_surat = 'Selesai'");
$rekap_done = (int)(mysqli_fetch_assoc($q_done)['total'] ?? 0);

$cek_penerima = mysqli_query($koneksi, "SHOW COLUMNS FROM surat_masuk LIKE 'penerima_sekarang'");
$ada_penerima = ($cek_penerima && mysqli_num_rows($cek_penerima) > 0);

$query = mysqli_query($koneksi, "SELECT * FROM surat_masuk" . $where_sql . " ORDER BY id DESC");
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Surat - Sekretaris</title>
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
        @media print {
            .sidebar, .no-print { display: none !important; }
            .main-content { margin-left: 0 !important; padding: 0 !important; }
        }
        @media (max-width: 768px) {
            .sidebar { position: relative; width: 100%; min-height: auto; border-right: none; border-bottom: 1px solid #e2e8f0; }
            .main-content { margin-left: 0; padding: 16px; }
        }
    </style>
</head>
<body>

    <div class="sidebar d-flex flex-column p-3 no-print">
        <div>
            <div class="sidebar-logo-box">
                <img src="<?= htmlspecialchars($url_logo, ENT_QUOTES, 'UTF-8'); ?>" alt="Logo Kabupaten Kepulauan Sangihe" class="sidebar-logo-img">
            </div>
            <ul class="nav nav-pills flex-column mb-auto">
                <li class="nav-item"><a href="dashboard.php" class="nav-link"><i class="bi bi-house-door-fill me-2"></i> Dashboard</a></li>
                <li class="nav-item"><a href="disposisi_masuk.php" class="nav-link"><i class="bi bi-envelope-paper-fill me-2"></i> Surat Masuk</a></li>
                <li class="nav-item"><a href="verifikasi_surat.php" class="nav-link"><i class="bi bi-check2-circle me-2"></i> Verifikasi Surat</a></li>
                <li class="nav-item"><a href="lembar_kendali.php" class="nav-link"><i class="bi bi-diagram-3-fill me-2"></i> Lembar Kendali</a></li>
                <li class="nav-item"><a href="laporan.php" class="nav-link active"><i class="bi bi-file-earmark-bar-graph-fill me-2"></i> Laporan</a></li>
                <li class="nav-item"><a href="arsip.php" class="nav-link"><i class="bi bi-folder-fill me-2"></i> Arsip Surat</a></li>
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
                        <h4 class="fw-bold mb-1 text-white">Laporan Rekapitulasi Surat</h4>
                        <p class="mb-0 small" style="color: #dbeafe;">Ringkasan jumlah surat, filter status/tanggal, dan cetak dokumen laporan</p>
                    </div>
                    <div class="d-flex align-items-center gap-2 no-print">
                        <button type="button" onclick="window.print()" class="btn btn-light btn-sm text-dark fw-semibold">
                            <i class="bi bi-printer-fill me-1"></i> Cetak Laporan
                        </button>
                        <span class="badge-putih-sek"><i class="bi bi-person-badge-fill me-1"></i> SEKRETARIS</span>
                    </div>
                </div>
            </div>

            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <div class="card border-0 shadow-sm p-3 bg-white">
                        <small class="text-muted fw-bold text-uppercase">Total Keseluruhan</small>
                        <h4 class="fw-bold mt-1 mb-0"><?= $rekap_all; ?></h4>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card border-0 shadow-sm p-3 bg-white">
                        <small class="text-muted fw-bold text-uppercase">Sedang Diproses</small>
                        <h4 class="fw-bold mt-1 mb-0 text-warning"><?= $rekap_proc; ?></h4>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card border-0 shadow-sm p-3 bg-white">
                        <small class="text-muted fw-bold text-uppercase">Selesai / Terarsip</small>
                        <h4 class="fw-bold mt-1 mb-0 text-success"><?= $rekap_done; ?></h4>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm p-4 bg-white">
                <form method="GET" class="row g-2 align-items-end mb-3 no-print">
                    <div class="col-md-3">
                        <label class="form-label small fw-bold mb-1">Status Surat</label>
                        <select name="status" class="form-select form-select-sm">
                            <option value="Semua" <?= $filter_status === 'Semua' ? 'selected' : ''; ?>>Semua Status</option>
                            <option value="Diproses" <?= $filter_status === 'Diproses' ? 'selected' : ''; ?>>Diproses</option>
                            <option value="Selesai" <?= $filter_status === 'Selesai' ? 'selected' : ''; ?>>Selesai</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-bold mb-1">Dari Tanggal</label>
                        <input type="date" name="tgl_awal" class="form-control form-control-sm" value="<?= htmlspecialchars($tgl_awal); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-bold mb-1">Sampai Tanggal</label>
                        <input type="date" name="tgl_akhir" class="form-control form-control-sm" value="<?= htmlspecialchars($tgl_akhir); ?>">
                    </div>
                    <div class="col-md-3 d-flex gap-2">
                        <button type="submit" class="btn btn-dark btn-sm w-100"><i class="bi bi-funnel-fill me-1"></i> Filter</button>
                        <a href="laporan.php" class="btn btn-outline-secondary btn-sm">Reset</a>
                    </div>
                </form>

                <div class="table-responsive">
                    <table class="table table-bordered table-striped table-custom align-middle">
                        <thead class="text-center">
                            <tr>
                                <th width="4%">No</th>
                                <th width="8%">No. Agenda</th>
                                <th width="15%">Asal Instansi</th>
                                <th width="13%">Nomor Surat</th>
                                <th width="22%">Perihal</th>
                                <th width="16%">Posisi / Keterangan</th>
                                <th width="10%">Dokumen</th>
                                <th width="12%">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $no = 1;
                            if ($query && mysqli_num_rows($query) > 0) {
                                while ($row = mysqli_fetch_assoc($query)) {
                                    $status = $row['status_surat'] ?? 'Masuk';
                                    $sifat_val = trim((string)($row['sifat_surat'] ?? 'Biasa'));
                                    $is_rahasia = (strtolower($sifat_val) === 'rahasia');
                                    $posisi = $status === 'Selesai'
                                        ? 'Selesai / Terarsip'
                                        : ($ada_penerima ? ($row['penerima_sekarang'] ?: ($row['disposisi_kepada'] ?? '-')) : ($row['disposisi_kepada'] ?? '-'));
                                    $agenda_tampil = format_no_agenda_sek_lap($row['no_agenda'] ?? '');
                                    $url_dok = !empty($row['file_surat']) ? resolve_url_berkas_sek_lap((string)$row['file_surat']) : '';
                                    $boleh_buka_dok = ($url_dok !== '') && (!$is_rahasia || $boleh_lihat_rahasia);
                            ?>
                            <tr>
                                <td class="text-center"><?= $no++; ?></td>
                                <td class="text-center"><span class="badge bg-secondary rounded-pill px-2 py-1"><?= htmlspecialchars($agenda_tampil, ENT_QUOTES, 'UTF-8'); ?></span></td>
                                <td><?= htmlspecialchars($row['asal_instansi'] ?? '-'); ?></td>
                                <td><?= htmlspecialchars($row['no_surat'] ?? '-'); ?></td>
                                <td><?= htmlspecialchars($row['perihal'] ?? '-'); ?></td>
                                <td class="text-center"><?= htmlspecialchars($posisi); ?></td>
                                <td class="text-center">
                                    <?php if ($boleh_buka_dok): ?>
                                        <a href="<?= htmlspecialchars($url_dok, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" class="btn btn-success btn-xs" title="<?= $is_rahasia ? 'Lihat Dokumen Rahasia' : 'Lihat PDF'; ?>">
                                            <i class="bi bi-file-earmark-pdf-fill"></i>
                                        </a>
                                    <?php elseif ($is_rahasia): ?>
                                        <span class="badge bg-secondary" style="font-size: 9px;"><i class="bi bi-lock-fill"></i> Rahasia</span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <span class="badge <?= $status === 'Selesai' ? 'bg-success' : ($status === 'Diproses' ? 'bg-warning text-dark' : 'bg-info text-dark'); ?>">
                                        <?= htmlspecialchars($status); ?>
                                    </span>
                                </td>
                            </tr>
                            <?php
                                }
                            } else {
                            ?>
                            <tr><td colspan="8" class="text-center text-muted py-4">Tidak ada data laporan sesuai filter.</td></tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>