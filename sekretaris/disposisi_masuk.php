<?php
session_start();
include '../koneksi.php';

if (!isset($_SESSION['user']) && !isset($_SESSION['id_user']) && !isset($_SESSION['username'])) {
    header("location: ../login.php");
    exit();
}

$role_session = $_SESSION['role'] ?? ($_SESSION['level'] ?? (is_array($_SESSION['user'] ?? null) ? ($_SESSION['user']['role'] ?? ($_SESSION['user']['level'] ?? '')) : ''));
if (strtolower(trim((string)$role_session)) !== 'sekretaris') {
    echo "<script>alert('Akses ditolak! Halaman ini khusus untuk Sekretaris.'); window.location='../login.php';</script>";
    exit();
}

function cari_logo_sangihe_sek_masuk(): string {
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

function format_no_agenda_sek_masuk($raw_agenda): string {
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

$url_logo = cari_logo_sangihe_sek_masuk();

$cek_penerima = mysqli_query($koneksi, "SHOW COLUMNS FROM surat_masuk LIKE 'penerima_sekarang'");
$ada_penerima = ($cek_penerima && mysqli_num_rows($cek_penerima) > 0);

if ($ada_penerima) {
    $query = mysqli_query($koneksi, "SELECT * FROM surat_masuk WHERE status_surat = 'Diproses' AND (LOWER(penerima_sekarang) = 'sekretaris' OR LOWER(disposisi_kepada) = 'sekretaris') ORDER BY id DESC");
} else {
    $query = mysqli_query($koneksi, "SELECT * FROM surat_masuk WHERE status_surat = 'Diproses' AND LOWER(disposisi_kepada) = 'sekretaris' ORDER BY id DESC");
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Surat Masuk - Sekretaris</title>
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
            padding: 7px 6px;
            vertical-align: middle;
        }
        .btn-xs {
            padding: 3px 8px;
            font-size: 10px;
        }
        @media (max-width: 768px) {
            .sidebar { position: relative; width: 100%; min-height: auto; border-right: none; border-bottom: 1px solid #e2e8f0; }
            .main-content { margin-left: 0; padding: 16px; }
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
                <li class="nav-item"><a href="disposisi_masuk.php" class="nav-link active"><i class="bi bi-envelope-paper-fill me-2"></i> Surat Masuk</a></li>
                <li class="nav-item"><a href="verifikasi_surat.php" class="nav-link"><i class="bi bi-check2-circle me-2"></i> Verifikasi Surat</a></li>
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
            <div class="header-banner-sek mb-4">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                    <div>
                        <h4 class="fw-bold mb-1 text-white">Surat Masuk Sekretaris</h4>
                        <p class="mb-0 small" style="color: #dbeafe;">Daftar surat yang masuk ke Sekretaris. Klik tombol verifikasi untuk menindaklanjuti surat.</p>
                    </div>
                    <div>
                        <span class="badge-putih-sek"><i class="bi bi-person-badge-fill me-1"></i> SEKRETARIS</span>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm p-4 bg-white">
                <h5 class="fw-bold text-dark mb-3"><i class="bi bi-envelope-paper me-2" style="color: #3b6998;"></i>Daftar Surat Diterima</h5>
                <div class="table-responsive">
                    <table class="table table-bordered table-striped table-custom align-middle">
                        <thead class="text-center">
                            <tr>
                                <th width="4%">No</th>
                                <th width="8%">No. Agenda</th>
                                <th width="14%">Asal Instansi</th>
                                <th width="12%">Nomor Surat</th>
                                <th width="12%">Jenis Surat</th>
                                <th width="22%">Perihal</th>
                                <th width="7%">Sifat</th>
                                <th width="7%">Dokumen</th>
                                <th width="12%">Tindak Lanjut</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $no = 1;
                            if ($query && mysqli_num_rows($query) > 0) {
                                while ($row = mysqli_fetch_assoc($query)) {
                                    $is_rahasia = (strtolower($row['sifat_surat'] ?? '') === 'rahasia');
                                    $idx = (int)$row['id'];
                                    $agenda_tampil = format_no_agenda_sek_masuk($row['no_agenda'] ?? '');
                                    $jenis_tampil = trim((string)($row['jenis_surat'] ?? ($row['jenis'] ?? '')));
                                    if ($jenis_tampil === '') {
                                        $jenis_tampil = '-';
                                    }
                            ?>
                            <tr>
                                <td class="text-center"><?= $no++; ?></td>
                                <td class="text-center"><span class="badge bg-secondary rounded-pill px-2 py-1"><?= htmlspecialchars($agenda_tampil, ENT_QUOTES, 'UTF-8'); ?></span></td>
                                <td><?= htmlspecialchars($row['asal_instansi'] ?? '-'); ?></td>
                                <td><?= htmlspecialchars($row['no_surat'] ?? '-'); ?></td>
                                <td class="text-center"><?= htmlspecialchars($jenis_tampil, ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?= htmlspecialchars($row['perihal'] ?? '-'); ?></td>
                                <td class="text-center">
                                    <span class="badge <?= $is_rahasia ? 'bg-dark' : 'bg-danger'; ?>" style="font-size: 9px;">
                                        <?= htmlspecialchars($row['sifat_surat'] ?? 'Biasa'); ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <?php if (!empty($row['file_surat']) && !$is_rahasia): ?>
                                        <a href="../uploads/<?= htmlspecialchars($row['file_surat']); ?>" target="_blank" class="btn btn-success btn-xs" title="Lihat PDF">
                                            <i class="bi bi-file-earmark-pdf-fill"></i>
                                        </a>
                                    <?php elseif ($is_rahasia): ?>
                                        <span class="badge bg-secondary" style="font-size: 9px;"><i class="bi bi-lock-fill"></i> Rahasia</span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <a href="verifikasi_surat.php?id=<?= $idx; ?>" class="btn btn-primary btn-xs">
                                        <i class="bi bi-check2-circle me-1"></i> Verifikasi
                                    </a>
                                </td>
                            </tr>
                            <?php
                                }
                            } else {
                            ?>
                            <tr><td colspan="9" class="text-center text-muted py-4">Belum ada surat masuk untuk Sekretaris.</td></tr>
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