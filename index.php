<?php
session_start();

function cari_logo_sangihe_idx(): string {
    $kandidat = [
        'assets/logo_sangihe.png',
        'logo_sangihe.png',
        'assets/img/logo_sangihe.png',
        '../assets/logo_sangihe.png',
    ];
    foreach ($kandidat as $rel) {
        if (file_exists(__DIR__ . '/' . $rel) && is_file(__DIR__ . '/' . $rel)) {
            return $rel;
        }
    }
    return 'assets/logo_sangihe.png';
}

$url_logo = cari_logo_sangihe_idx();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Selamat Datang - Layanan Surat Diskominfo Sangihe</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            background-color: #eef2f7;
            color: #1e293b;
            font-family: 'Inter', sans-serif;
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            margin: 0;
            padding: 16px;
        }
        .card-portal {
            background-color: #3b6998;
            border: 1px solid #2f5780;
            border-radius: 16px;
            width: 100%;
            max-width: 480px;
            box-shadow: 0 14px 32px rgba(47, 87, 128, 0.25);
            padding: 24px 26px;
            text-align: center;
        }
        .header-box {
            background-color: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 6px;
            height: 155px;
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(15, 23, 42, 0.14);
            display: flex;
            justify-content: center;
            align-items: center;
            margin-bottom: 18px;
        }
        .logo-img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            transform: scale(1.12);
            display: block;
            background-color: #ffffff;
        }
        .judul-portal {
            color: #ffffff;
            font-size: 1.08rem;
            font-weight: 700;
            letter-spacing: 0.3px;
            margin-bottom: 6px;
        }
        .sub-portal {
            color: #dbeafe;
            font-size: 0.88rem;
            margin-bottom: 18px;
        }
        .btn-portal {
            background-color: #2b537c;
            border: 1px solid #224364;
            border-radius: 8px;
            padding: 10px 16px;
            font-size: 0.94rem;
            font-weight: 600;
            color: #ffffff;
            width: 100%;
            display: inline-block;
            text-decoration: none;
            transition: all 0.2s ease;
            box-shadow: 0 4px 12px rgba(20, 42, 66, 0.28);
        }
        .btn-portal:hover {
            background-color: #4372a1;
            border-color: #325980;
            color: #ffffff;
            transform: translateY(-1px);
        }
    </style>
</head>
<body>
    <div class="card-portal">
        <div class="header-box">
            <img src="<?= htmlspecialchars($url_logo, ENT_QUOTES, 'UTF-8'); ?>" alt="Logo LSM Diskominfo Sangihe" class="logo-img">
        </div>

        <div class="judul-portal">SELAMAT DATANG DI LAYANAN SURAT</div>
        <div class="sub-portal">Silakan masuk untuk mengakses sistem administrasi persuratan</div>

        <a href="login.php" class="btn-portal">Masuk Ke Portal →</a>
    </div>
</body>
</html>