<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$path_koneksi = file_exists(__DIR__ . '/../koneksi.php') ? __DIR__ . '/../koneksi.php' : __DIR__ . '/koneksi.php';
include_once $path_koneksi;

$path_akses = file_exists(__DIR__ . '/../akses_surat.php') ? __DIR__ . '/../akses_surat.php' : (file_exists(__DIR__ . '/akses_surat.php') ? __DIR__ . '/akses_surat.php' : '');
if ($path_akses !== '') {
    require_once $path_akses;
}

if (function_exists('wajib_akses_halalman')) {
    wajib_akses_halalman($koneksi, ['kabid'], '', '../');
} elseif (function_exists('wajib_akses_halaman')) {
    wajib_akses_halaman($koneksi, ['kabid'], '', '../');
}

if (function_exists('url_dashboard_sesuai_user')) {
    $tujuan = (string)url_dashboard_sesuai_user('../');
    if ($tujuan !== '' && basename($tujuan) !== 'dashboard.php') {
        header("Location: " . $tujuan);
        exit();
    }
}

$ada_sesi = isset($_SESSION['user'])
    || isset($_SESSION['id_user'])
    || isset($_SESSION['user_id'])
    || isset($_SESSION['id'])
    || isset($_SESSION['username'])
    || isset($_SESSION['email'])
    || isset($_SESSION['google_email'])
    || isset($_SESSION['role'])
    || isset($_SESSION['level']);

if (!$ada_sesi) {
    echo "<script>alert('Akun bidang tidak dikenali.'); window.location='../login.php';</script>";
    exit();
}

$id_login    = (int)($_SESSION['user_id'] ?? $_SESSION['id_user'] ?? $_SESSION['id'] ?? (is_array($_SESSION['user'] ?? null) ? ($_SESSION['user']['id'] ?? 0) : 0));
$user_login  = trim((string)($_SESSION['username'] ?? (is_array($_SESSION['user'] ?? null) ? ($_SESSION['user']['username'] ?? '') : '')));
$email_login = trim((string)($_SESSION['email'] ?? $_SESSION['google_email'] ?? (is_array($_SESSION['user'] ?? null) ? ($_SESSION['user']['email'] ?? '') : '')));

$nama_user = 'KABID';
if (!empty($_SESSION['google_name'])) {
    $nama_user = (string)$_SESSION['google_name'];
} elseif (is_array($_SESSION['user'] ?? null) && !empty($_SESSION['user']['nama'])) {
    $nama_user = (string)$_SESSION['user']['nama'];
} elseif (is_array($_SESSION['user'] ?? null) && !empty($_SESSION['user']['name'])) {
    $nama_user = (string)$_SESSION['user']['name'];
} elseif (!empty($_SESSION['nama'])) {
    $nama_user = (string)$_SESSION['nama'];
} elseif (!empty($_SESSION['name'])) {
    $nama_user = (string)$_SESSION['name'];
} elseif ($user_login !== '') {
    $nama_user = $user_login;
}

$nama_bidang = trim((string)(
    $_SESSION['bidang']
    ?? $_SESSION['nama_bidang']
    ?? $_SESSION['unit_kerja']
    ?? (is_array($_SESSION['user'] ?? null) ? ($_SESSION['user']['bidang'] ?? $_SESSION['user']['nama_bidang'] ?? '') : '')
));

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
                $nama_user = (string)$row_u['nama'];
            } elseif (!empty($row_u['name'])) {
                $nama_user = (string)$row_u['name'];
            }
            if ($nama_bidang === '' && !empty($row_u['bidang'])) {
                $nama_bidang = (string)$row_u['bidang'];
            } elseif ($nama_bidang === '' && !empty($row_u['nama_bidang'])) {
                $nama_bidang = (string)$row_u['nama_bidang'];
            }
            if ($user_login === '' && !empty($row_u['username'])) {
                $user_login = (string)$row_u['username'];
            }
            if ($email_login === '' && !empty($row_u['email'])) {
                $email_login = (string)$row_u['email'];
            }
        }
    }
}

$label_dashboard = $nama_bidang !== '' ? ('Dashboard ' . $nama_bidang) : 'Dashboard Kabid';
$badge_bidang    = $nama_bidang !== '' ? strtoupper($nama_bidang) : 'KABID';

$kandidat_logo = [
    'assets/logo.png',
    'assets/img/logo.png',
    'img/logo.png',
    '../assets/logo.png',
    '../assets/img/logo.png',
    '../img/logo.png',
];
$url_logo = '';
foreach ($kandidat_logo as $k_logo) {
    if (file_exists(__DIR__ . '/' . $k_logo) && is_file(__DIR__ . '/' . $k_logo)) {
        $url_logo = $k_logo;
        break;
    }
}

$total_disposisi = 0;
$total_diproses  = 0;
$total_selesai   = 0;

if (isset($koneksi) && $koneksi instanceof mysqli) {
    $cek_sm = mysqli_query($koneksi, "SHOW TABLES LIKE 'surat_masuk'");
    if ($cek_sm && mysqli_num_rows($cek_sm) > 0) {
        $kol_status = '';
        foreach (['status_surat', 'status'] as $ks) {
            $c = mysqli_query($koneksi, "SHOW COLUMNS FROM `surat_masuk` LIKE '$ks'");
            if ($c && mysqli_num_rows($c) > 0) {
                $kol_status = $ks;
                break;
            }
        }

        $kol_tujuan = '';
        foreach (['disposisi_kepada', 'tujuan_disposisi', 'tujuan_bidang', 'bidang_tujuan'] as $kt) {
            $c = mysqli_query($koneksi, "SHOW COLUMNS FROM `surat_masuk` LIKE '$kt'");
            if ($c && mysqli_num_rows($c) > 0) {
                $kol_tujuan = $kt;
                break;
            }
        }

        $where_bid = '';
        if ($kol_tujuan !== '' && $nama_bidang !== '') {
            $esc_b = mysqli_real_escape_string($koneksi, strtolower(trim($nama_bidang)));
            $where_bid = "WHERE LOWER(TRIM(COALESCE(`$kol_tujuan`, ''))) LIKE '%$esc_b%'";
        }

        $res_s = mysqli_query($koneksi, "SELECT * FROM `surat_masuk` $where_bid");
        if ($res_s) {
            while ($r = mysqli_fetch_assoc($res_s)) {
                $total_disposisi++;
                $st = strtolower(trim((string)($kol_status !== '' ? ($r[$kol_status] ?? '') : '')));
                if (in_array($st, ['selesai', 'diterima', 'diarsipkan', 'arsip'], true)) {
                    $total_selesai++;
                } elseif (in_array($st, ['diproses', 'verifikasi', 'terverifikasi', 'disposisi', 'didisposisikan'], true)) {
                    $total_diproses++;
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Beranda - <?= htmlspecialchars($badge_bidang, ENT_QUOTES, 'UTF-8'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            background-color: #f1f5f9;
            color: #0f172a;
            font-family: 'Inter', sans-serif;
            margin: 0;
        }
        .sidebar-role {
            width: 260px;
            min-height: 100vh;
            background-color: #051229;
            color: #ffffff;
            position: fixed;
            top: 0;
            left: 0;
            padding: 24px 16px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            z-index: 100;
        }
        .brand-logo-box {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 8px 12px 20px;
            margin-bottom: 12px;
        }
        .brand-logo-box img {
            max-height: 52px;
            max-width: 100%;
            object-fit: contain;
        }
        .brand-logo-icon {
            width: 52px;
            height: 52px;
            border-radius: 14px;
            background-color: rgba(13, 110, 253, 0.16);
            color: #3b82f6;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.65rem;
        }
        .sidebar-role .nav-link {
            color: #e2e8f0;
            border-radius: 10px;
            padding: 12px 16px;
            margin-bottom: 8px;
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
        }
        .sidebar-role .nav-link:hover {
            background-color: rgba(255, 255, 255, 0.08);
            color: #ffffff;
        }
        .sidebar-role .nav-link.active {
            background-color: #0d6efd;
            color: #ffffff;
            font-weight: 500;
        }
        .main-role {
            margin-left: 260px;
            padding: 28px 32px;
        }
        .role-header-card {
            background-color: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 22px 26px;
            box-shadow: 0 1px 3px rgba(15, 23, 42, 0.04);
            margin-bottom: 20px;
        }
        .greeting-banner {
            background: linear-gradient(90deg, #041024 0%, #092147 100%);
            color: #ffffff;
            border-radius: 14px;
            padding: 24px 28px;
            margin-bottom: 24px;
        }
        .stat-box-role {
            background-color: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 22px;
            box-shadow: 0 1px 3px rgba(15, 23, 42, 0.04);
            height: 100%;
        }
        .icon-sq-role {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.15rem;
            flex-shrink: 0;
        }
        @media (max-width: 768px) {
            .sidebar-role {
                position: relative;
                width: 100%;
                min-height: auto;
            }
            .main-role {
                margin-left: 0;
                padding: 18px;
            }
        }
    </style>
</head>
<body>
    <aside class="sidebar-role">
        <div>
            <div class="brand-logo-box">
                <?php if ($url_logo !== ''): ?>
                    <img src="<?= htmlspecialchars($url_logo, ENT_QUOTES, 'UTF-8'); ?>" alt="Logo">
                <?php else: ?>
                    <div class="brand-logo-icon">
                        <i class="bi bi-envelope-paper-fill"></i>
                    </div>
                <?php endif; ?>
            </div>

            <nav class="nav flex-column">
                <a href="dashboard.php" class="nav-link active">
                    <i class="bi bi-house-door"></i>
                    <span>Beranda</span>
                </a>
                <a href="surat_masuk.php" class="nav-link">
                    <i class="bi bi-envelope"></i>
                    <span>Surat Masuk</span>
                </a>
                <a href="riwayat_surat.php" class="nav-link">
                    <i class="bi bi-clock-history"></i>
                    <span>Riwayat Surat</span>
                </a>
            </nav>
        </div>

        <div class="pt-3 border-top border-secondary border-opacity-25">
            <a href="../logout.php" class="nav-link text-danger mb-0">
                <i class="bi bi-box-arrow-right"></i>
                <span>Keluar</span>
            </a>
        </div>
    </aside>

    <main class="main-role">
        <div class="role-header-card d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <h4 class="fw-bold mb-1"><?= htmlspecialchars($label_dashboard, ENT_QUOTES, 'UTF-8'); ?></h4>
                <p class="text-muted mb-0">Kelola disposisi surat masuk dan tindak lanjut dokumen bidang Anda.</p>
            </div>
            <span class="badge bg-primary px-3 py-2 fs-6">
                <i class="bi bi-person-badge me-1"></i> <?= htmlspecialchars($badge_bidang, ENT_QUOTES, 'UTF-8'); ?>
            </span>
        </div>

        <div class="greeting-banner">
            <h4 class="fw-bold mb-1">Hallo <?= htmlspecialchars($nama_user, ENT_QUOTES, 'UTF-8'); ?> &#128075;</h4>
            <div class="small text-white-50">
                Gunakan kartu statistik di bawah atau menu sidebar untuk melihat riwayat surat dan mengelola dokumen.
            </div>
        </div>

        <div class="row g-4">
            <div class="col-md-4">
                <a href="surat_masuk.php" class="d-block text-decoration-none text-reset h-100">
                    <div class="stat-box-role d-flex justify-content-between align-items-start">
                        <div>
                            <div class="text-muted small mb-2">Total Surat Disposisi</div>
                            <div class="fs-2 fw-bold mb-2"><?= (int)$total_disposisi; ?></div>
                            <span class="small text-primary fw-medium">Lihat Surat Masuk &rarr;</span>
                        </div>
                        <div class="icon-sq-role bg-primary bg-opacity-10 text-primary">
                            <i class="bi bi-envelope"></i>
                        </div>
                    </div>
                </a>
            </div>

            <div class="col-md-4">
                <a href="surat_masuk.php" class="d-block text-decoration-none text-reset h-100">
                    <div class="stat-box-role d-flex justify-content-between align-items-start">
                        <div>
                            <div class="text-muted small mb-2">Sedang Diproses</div>
                            <div class="fs-2 fw-bold text-warning mb-2"><?= (int)$total_diproses; ?></div>
                            <span class="small text-primary fw-medium">Tindak Lanjuti &rarr;</span>
                        </div>
                        <div class="icon-sq-role bg-warning bg-opacity-10 text-warning">
                            <i class="bi bi-hourglass-split"></i>
                        </div>
                    </div>
                </a>
            </div>

            <div class="col-md-4">
                <a href="riwayat_surat.php" class="d-block text-decoration-none text-reset h-100">
                    <div class="stat-box-role d-flex justify-content-between align-items-start">
                        <div>
                            <div class="text-muted small mb-2">Selesai / Diarsipkan</div>
                            <div class="fs-2 fw-bold text-success mb-2"><?= (int)$total_selesai; ?></div>
                            <span class="small text-primary fw-medium">Buka Riwayat &rarr;</span>
                        </div>
                        <div class="icon-sq-role bg-success bg-opacity-10 text-success">
                            <i class="bi bi-archive"></i>
                        </div>
                    </div>
                </a>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>