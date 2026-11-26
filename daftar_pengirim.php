<?php
session_start();
include 'koneksi.php';

$tabel_akun       = 'users';
$error_msg        = '';
$info_msg         = '';
$otp_lokal_hint   = '';
$durasi_otp_detik = 240; // 4 menit

// Konfigurasi SMTP sistem (diatur satu kali di environment hosting atau config_smtp.php)
$smtp_host = (string)(getenv('SMTP_HOST') ?: 'smtp.gmail.com');
$smtp_port = (int)(getenv('SMTP_PORT') ?: 587);
$smtp_user = (string)(getenv('SMTP_USER') ?: '');
$smtp_pass = (string)(getenv('SMTP_PASS') ?: '');
$smtp_from = (string)(getenv('SMTP_FROM') ?: '');
$smtp_nama = (string)(getenv('SMTP_NAME') ?: 'Portal E-Office Diskominfo Sangihe');

if (file_exists(__DIR__ . '/config_smtp.php')) {
    include_once __DIR__ . '/config_smtp.php';
}

function kolom_ada_dp($koneksi, string $tabel, string $kolom): bool {
    if (!($koneksi instanceof mysqli)) {
        return false;
    }
    $t = mysqli_real_escape_string($koneksi, $tabel);
    $k = mysqli_real_escape_string($koneksi, $kolom);
    $res = mysqli_query($koneksi, "SHOW COLUMNS FROM `$t` LIKE '$k'");
    return ($res && mysqli_num_rows($res) > 0);
}

function username_sudah_terdaftar_dp($koneksi, string $tabel, string $username): bool {
    if (!($koneksi instanceof mysqli)) {
        return false;
    }
    $stmt = mysqli_prepare($koneksi, "SELECT id FROM `$tabel` WHERE LOWER(TRIM(username)) = LOWER(TRIM(?)) LIMIT 1");
    if (!$stmt) {
        return false;
    }
    mysqli_stmt_bind_param($stmt, 's', $username);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $ada = ($res && mysqli_num_rows($res) > 0);
    mysqli_stmt_close($stmt);
    return $ada;
}

function cari_logo_sangihe_dp(): string {
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

function kirim_smtp_socket_dp(
    string $host,
    int $port,
    string $user,
    string $pass,
    string $from_email,
    string $from_name,
    string $to_email,
    string $subject,
    string $body,
    string &$err_detail = ''
): bool {
    $baca_resp = function ($fp) {
        $data = '';
        while (!feof($fp)) {
            $line = fgets($fp, 515);
            if ($line === false) {
                break;
            }
            $data .= $line;
            if (strlen($line) >= 4 && $line[3] === ' ') {
                break;
            }
        }
        return $data;
    };

    $kirim_cmd = function ($fp, string $cmd, array $codes_ok) use ($baca_resp, &$err_detail): bool {
        fwrite($fp, $cmd . "\r\n");
        $resp = $baca_resp($fp);
        $code = (int)substr(trim($resp), 0, 3);
        if (!in_array($code, $codes_ok, true)) {
            $err_detail = trim($resp);
            return false;
        }
        return true;
    };

    $transport = ($port === 465) ? "ssl://{$host}:{$port}" : "tcp://{$host}:{$port}";
    $ctx = stream_context_create([
        'ssl' => [
            'verify_peer'       => false,
            'verify_peer_name'  => false,
            'allow_self_signed' => true,
        ],
    ]);

    $errno = 0;
    $errstr = '';
    $fp = @stream_socket_client($transport, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) {
        $err_detail = "Koneksi SMTP gagal ({$errno}): {$errstr}";
        return false;
    }
    stream_set_timeout($fp, 15);

    $greet = $baca_resp($fp);
    if ((int)substr(trim($greet), 0, 3) !== 220) {
        $err_detail = "Greeting SMTP gagal: " . trim($greet);
        fclose($fp);
        return false;
    }

    $hostname = gethostname() ?: 'localhost';
    if (!$kirim_cmd($fp, "EHLO {$hostname}", [250])) {
        fclose($fp);
        return false;
    }

    if ($port === 587) {
        if (!$kirim_cmd($fp, "STARTTLS", [220])) {
            fclose($fp);
            return false;
        }
        $crypto_method = defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')
            ? STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT
            : STREAM_CRYPTO_METHOD_TLS_CLIENT;
        $crypto_ok = @stream_socket_enable_crypto($fp, true, $crypto_method);
        if ($crypto_ok !== true) {
            $crypto_ok = @stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        }
        if ($crypto_ok !== true) {
            $err_detail = "Gagal mengaktifkan enkripsi TLS STARTTLS.";
            fclose($fp);
            return false;
        }
        if (!$kirim_cmd($fp, "EHLO {$hostname}", [250])) {
            fclose($fp);
            return false;
        }
    }

    if ($user !== '' && $pass !== '') {
        if (!$kirim_cmd($fp, "AUTH LOGIN", [334])) {
            fclose($fp);
            return false;
        }
        if (!$kirim_cmd($fp, base64_encode($user), [334])) {
            fclose($fp);
            return false;
        }
        if (!$kirim_cmd($fp, base64_encode(str_replace(' ', '', $pass)), [235])) {
            fclose($fp);
            return false;
        }
    }

    if (!$kirim_cmd($fp, "MAIL FROM:<{$from_email}>", [250])) {
        fclose($fp);
        return false;
    }
    if (!$kirim_cmd($fp, "RCPT TO:<{$to_email}>", [250, 251])) {
        fclose($fp);
        return false;
    }
    if (!$kirim_cmd($fp, "DATA", [354])) {
        fclose($fp);
        return false;
    }

    $safe_subject    = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $safe_from_name  = '=?UTF-8?B?' . base64_encode($from_name) . '?=';
    $normalized_body = preg_replace("/\r\n|\r|\n/", "\r\n", $body);
    $normalized_body = preg_replace('/^\./m', '..', $normalized_body);

    $msg = "Date: " . date('r') . "\r\n"
         . "From: {$safe_from_name} <{$from_email}>\r\n"
         . "To: <{$to_email}>\r\n"
         . "Subject: {$safe_subject}\r\n"
         . "MIME-Version: 1.0\r\n"
         . "Content-Type: text/plain; charset=UTF-8\r\n"
         . "Content-Transfer-Encoding: 8bit\r\n\r\n"
         . $normalized_body . "\r\n.\r\n";

    fwrite($fp, $msg);
    $data_resp = $baca_resp($fp);
    if ((int)substr(trim($data_resp), 0, 3) !== 250) {
        $err_detail = "Pengiriman isi email gagal: " . trim($data_resp);
        fclose($fp);
        return false;
    }

    @fwrite($fp, "QUIT\r\n");
    fclose($fp);
    return true;
}

function kirim_otp_email_dp(
    string $email_tujuan,
    string $nama_tujuan,
    string $kode_otp,
    bool $is_ulang = false,
    array $cfg = [],
    string &$catatan_error = ''
): bool {
    $catatan_error = '';
    $subjek = ($is_ulang ? 'Kode OTP Baru - ' : 'Verifikasi Email Aktif - ')
            . 'Portal E-Office Diskominfo Sangihe';
    $isi    = "Halo " . ($nama_tujuan !== '' ? $nama_tujuan : 'Pengirim') . ",\n\n"
            . ($is_ulang ? "Berikut kode OTP baru yang Anda minta:\n" : "Berikut kode verifikasi untuk mengaktifkan pendaftaran akun Anda:\n")
            . "KODE OTP: " . $kode_otp . "\n\n"
            . "Kode ini berlaku selama 4 menit. Jangan bagikan kode ini kepada siapa pun.\n\n"
            . "Dinas Komunikasi dan Informatika Daerah Kabupaten Kepulauan Sangihe";

    $smtp_host  = trim((string)($cfg['smtp_host'] ?? ''));
    $smtp_port  = (int)($cfg['smtp_port'] ?? 587);
    $smtp_user  = trim((string)($cfg['smtp_user'] ?? ''));
    $smtp_pass  = (string)($cfg['smtp_pass'] ?? '');
    $from_email = trim((string)($cfg['from'] ?? ''));
    if ($from_email === '') {
        $from_email = $smtp_user !== '' ? $smtp_user : 'no-reply@diskominfo-sangihe.go.id';
    }
    $from_name  = (string)($cfg['nama'] ?? 'Portal E-Office Diskominfo Sangihe');

    if ($smtp_host === '' || $smtp_user === '' || trim($smtp_pass) === '') {
        $catatan_error = 'SMTP sistem belum dikonfigurasi di server/hosting.';
        return false;
    }

    $autoload = __DIR__ . '/vendor/autoload.php';
    if (file_exists($autoload)) {
        require_once $autoload;
        if (class_exists('\PHPMailer\PHPMailer\PHPMailer')) {
            try {
                $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
                $mail->isSMTP();
                $mail->Host       = $smtp_host;
                $mail->SMTPAuth   = true;
                $mail->Username   = $smtp_user;
                $mail->Password   = str_replace(' ', '', $smtp_pass);
                $mail->SMTPSecure = ($smtp_port === 465)
                    ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
                    : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port       = $smtp_port;
                $mail->CharSet    = 'UTF-8';

                $mail->setFrom($from_email, $from_name);
                $mail->addAddress($email_tujuan, $nama_tujuan !== '' ? $nama_tujuan : 'Pengirim');
                $mail->Subject = $subjek;
                $mail->Body    = $isi;

                return $mail->send();
            } catch (\Throwable $e) {
                $catatan_error = $e->getMessage();
                return false;
            }
        }
    }

    return kirim_smtp_socket_dp(
        $smtp_host,
        $smtp_port,
        $smtp_user,
        $smtp_pass,
        $from_email,
        $from_name,
        $email_tujuan,
        $subjek,
        $isi,
        $catatan_error
    );
}

$cfg_mail = [
    'smtp_host' => $smtp_host,
    'smtp_port' => $smtp_port,
    'smtp_user' => $smtp_user,
    'smtp_pass' => $smtp_pass,
    'from'      => $smtp_from,
    'nama'      => $smtp_nama,
];

$url_logo     = cari_logo_sangihe_dp();
$tabel_bersih = preg_replace('/[^a-zA-Z0-9_]/', '', $tabel_akun) ?: 'users';
$ada_email    = kolom_ada_dp($koneksi, $tabel_bersih, 'email');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['batal_otp'])) {
        unset($_SESSION['otp_daftar_pengirim']);
        $info_msg = 'Verifikasi email dibatalkan. Silakan masukkan data dan email aktif kembali.';
    } elseif (isset($_POST['kirim_ulang_otp'])) {
        if (!empty($_SESSION['otp_daftar_pengirim']) && is_array($_SESSION['otp_daftar_pengirim'])) {
            $kode_baru = (string)random_int(100000, 999999);
            $_SESSION['otp_daftar_pengirim']['kode']       = $kode_baru;
            $_SESSION['otp_daftar_pengirim']['expired_at'] = time() + $durasi_otp_detik;

            $em_p = (string)$_SESSION['otp_daftar_pengirim']['email'];
            $nm_p = (string)$_SESSION['otp_daftar_pengirim']['nama'];
            $err_smtp = '';
            if (kirim_otp_email_dp($em_p, $nm_p, $kode_baru, true, $cfg_mail, $err_smtp)) {
                $info_msg = 'Kode OTP baru (berlaku 4 menit) telah dikirim ke email ' . $em_p . '.';
            } else {
                $error_msg = 'Gagal mengirim OTP ke email ' . $em_p . ($err_smtp !== '' ? ' (' . $err_smtp . ')' : '') . '.';
                $otp_lokal_hint = 'Kode OTP (uji lokal): ' . $kode_baru;
            }
        } else {
            $error_msg = 'Sesi verifikasi tidak ditemukan. Silakan isi formulir pendaftaran.';
        }
    } elseif (isset($_POST['konfirmasi_otp'])) {
        $pending    = $_SESSION['otp_daftar_pengirim'] ?? null;
        $kode_input = trim((string)($_POST['kode_otp'] ?? ''));

        if (!is_array($pending) || empty($pending['email'])) {
            $error_msg = 'Sesi pendaftaran sudah berakhir. Silakan isi ulang formulir.';
        } elseif (time() > (int)($pending['expired_at'] ?? 0)) {
            $error_msg = 'Kode OTP sudah kedaluwarsa (masa berlaku 4 menit). Klik "Kirim Ulang Kode" untuk menerima kode OTP baru.';
        } elseif ($kode_input === '' || !hash_equals((string)($pending['kode'] ?? ''), $kode_input)) {
            $error_msg = 'Kode OTP tidak sesuai dengan kode terbaru yang dikirim ke email.';
        } else {
            $nama_lengkap = (string)$pending['nama'];
            $username     = (string)$pending['username'];
            $email        = strtolower(trim((string)$pending['email']));
            $hash_pass    = (string)$pending['password_hash'];

            if (username_sudah_terdaftar_dp($koneksi, $tabel_bersih, $username)) {
                $error_msg = 'Username sudah digunakan. Silakan batalkan dan pilih username lain.';
            } else {
                if ($ada_email) {
                    $stmt_ins = mysqli_prepare(
                        $koneksi,
                        "INSERT INTO `$tabel_bersih` (nama, username, email, password, role)
                         VALUES (?, ?, ?, ?, 'pengirim')"
                    );
                    mysqli_stmt_bind_param($stmt_ins, 'ssss', $nama_lengkap, $username, $email, $hash_pass);
                } else {
                    $stmt_ins = mysqli_prepare(
                        $koneksi,
                        "INSERT INTO `$tabel_bersih` (nama, username, password, role)
                         VALUES (?, ?, ?, 'pengirim')"
                    );
                    mysqli_stmt_bind_param($stmt_ins, 'sss', $nama_lengkap, $username, $hash_pass);
                }

                if ($stmt_ins && mysqli_stmt_execute($stmt_ins)) {
                    mysqli_stmt_close($stmt_ins);
                    unset($_SESSION['otp_daftar_pengirim']);
                    header("Location: login.php?terdaftar=1");
                    exit();
                } else {
                    $error_msg = 'Gagal membuat akun pengirim: ' . mysqli_error($koneksi);
                }
            }
        }
    } else {
        $nama_lengkap = trim((string)($_POST['nama'] ?? ''));
        $username     = trim((string)($_POST['username'] ?? ''));
        $email        = strtolower(trim((string)($_POST['email'] ?? '')));
        $password     = (string)($_POST['password'] ?? '');
        $konfirmasi   = (string)($_POST['konfirmasi_password'] ?? '');

        if ($nama_lengkap === '' || $username === '' || $email === '' || $password === '') {
            $error_msg = 'Nama/Instansi, username, email aktif, dan kata sandi wajib diisi.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error_msg = 'Masukkan alamat email yang valid dan aktif (contoh: nama@gmail.com).';
        } elseif (!preg_match('/^[a-zA-Z0-9._-]{4,40}$/', $username)) {
            $error_msg = 'Username hanya boleh berisi huruf, angka, titik, underscore, atau strip (4-40 karakter).';
        } elseif (strlen($password) < 6) {
            $error_msg = 'Kata sandi minimal 6 karakter.';
        } elseif ($password !== $konfirmasi) {
            $error_msg = 'Konfirmasi kata sandi tidak cocok.';
        } elseif (username_sudah_terdaftar_dp($koneksi, $tabel_bersih, $username)) {
            $error_msg = 'Username sudah digunakan. Silakan pilih username lain.';
        } else {
            $kode_otp = (string)random_int(100000, 999999);
            $_SESSION['otp_daftar_pengirim'] = [
                'nama'          => $nama_lengkap,
                'username'      => $username,
                'email'         => $email,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'kode'          => $kode_otp,
                'expired_at'    => time() + $durasi_otp_detik,
            ];

            $err_smtp = '';
            if (kirim_otp_email_dp($email, $nama_lengkap, $kode_otp, false, $cfg_mail, $err_smtp)) {
                $info_msg = 'Kode OTP (berlaku 4 menit) telah dikirim ke email aktif ' . $email . '. Masukkan kode di bawah untuk menyelesaikan pendaftaran.';
            } else {
                $error_msg = 'Gagal mengirim OTP ke email ' . $email . ($err_smtp !== '' ? ' (' . $err_smtp . ')' : '') . '.';
                $otp_lokal_hint = 'Kode OTP (uji lokal): ' . $kode_otp;
            }
        }
    }
}

$menunggu_otp   = !empty($_SESSION['otp_daftar_pengirim']) && is_array($_SESSION['otp_daftar_pengirim']);
$email_menunggu = $menunggu_otp ? (string)$_SESSION['otp_daftar_pengirim']['email'] : '';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Buat Akun Pengirim - Portal E-Office Diskominfo Sangihe</title>
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
            padding: 14px 16px;
        }
        .card-custom {
            background-color: #3b6998;
            border: 1px solid #2f5780;
            border-radius: 16px;
            width: 100%;
            max-width: 460px;
            box-shadow: 0 14px 32px rgba(47, 87, 128, 0.25);
            padding: 22px 26px;
        }
        .header-box {
            background-color: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 4px;
            height: 110px;
            overflow: hidden;
            text-align: center;
            box-shadow: 0 4px 12px rgba(15, 23, 42, 0.14);
            display: flex;
            justify-content: center;
            align-items: center;
            margin-bottom: 14px;
        }
        .logo-img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            transform: scale(1.12);
            display: block;
            background-color: #ffffff;
        }
        .form-label {
            color: #f8fafc;
            font-size: 0.83rem;
            font-weight: 500;
            margin-bottom: 4px;
        }
        .form-control {
            background-color: #eef2f7;
            border: 1px solid #cbd5e1;
            color: #0f172a;
            border-radius: 8px;
            padding: 8px 12px;
            font-size: 0.92rem;
        }
        .form-control:focus {
            background-color: #ffffff;
            border-color: #93c5fd;
            color: #0f172a;
            box-shadow: 0 0 0 3px rgba(255, 255, 255, 0.25);
        }
        .sandi-wrapper {
            position: relative;
        }
        .sandi-wrapper .form-control {
            padding-right: 42px;
        }
        .btn-toggle-sandi {
            position: absolute;
            top: 50%;
            right: 8px;
            transform: translateY(-50%);
            background: transparent;
            border: none;
            color: #3b6998;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 28px;
            height: 28px;
            padding: 0;
            cursor: pointer;
            border-radius: 6px;
        }
        .btn-toggle-sandi:hover {
            color: #26486b;
            background-color: rgba(59, 105, 152, 0.08);
        }
        .btn-daftar {
            background-color: #2b537c;
            border: 1px solid #224364;
            border-radius: 8px;
            padding: 10px;
            font-size: 0.92rem;
            font-weight: 600;
            color: #ffffff;
            width: 100%;
            margin-top: 6px;
            transition: all 0.2s ease;
        }
        .btn-daftar:hover {
            background-color: #4372a1;
            border-color: #325980;
            color: #ffffff;
        }
        .back-link {
            display: block;
            text-align: center;
            color: #e2e8f0;
            font-size: 0.83rem;
            text-decoration: none;
            margin-top: 14px;
        }
        .back-link:hover {
            color: #ffffff;
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="card card-custom">
        <div class="header-box">
            <img src="<?= htmlspecialchars($url_logo, ENT_QUOTES, 'UTF-8'); ?>" alt="Logo Diskominfo Sangihe" class="logo-img">
        </div>

        <h5 class="text-white fw-bold mb-1">Buat Akun Pengirim Surat</h5>
        <p class="small mb-3" style="color: #dbeafe;">Wajib menggunakan email aktif untuk verifikasi OTP (berlaku 4 menit); 1 email boleh dipakai untuk beberapa akun berbeda.</p>

        <?php if ($info_msg !== ''): ?>
            <div class="alert alert-info py-2 px-3 small mb-2" role="alert">
                <?= htmlspecialchars($info_msg, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <?php if ($error_msg !== ''): ?>
            <div class="alert alert-danger py-2 px-3 small mb-2" role="alert">
                <?= htmlspecialchars($error_msg, ENT_QUOTES, 'UTF-8'); ?>
                <?php if ($otp_lokal_hint !== ''): ?>
                    <div class="fw-bold mt-1"><?= htmlspecialchars($otp_lokal_hint, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($menunggu_otp): ?>
            <form method="POST" action="daftar_pengirim.php" autocomplete="off">
                <div class="mb-2">
                    <label class="form-label">Email Aktif</label>
                    <input type="text" class="form-control" value="<?= htmlspecialchars($email_menunggu, ENT_QUOTES, 'UTF-8'); ?>" readonly>
                </div>
                <div class="mb-3">
                    <label class="form-label">Kode OTP Email (6 Digit &bull; Berlaku 4 Menit)</label>
                    <input type="text" name="kode_otp" class="form-control" required maxlength="6" pattern="[0-9]{6}" autocomplete="one-time-code" placeholder="Masukkan 6 digit kode OTP...">
                </div>
                <button type="submit" name="konfirmasi_otp" class="btn-daftar">Verifikasi &amp; Buat Akun</button>
            </form>

            <form method="POST" action="daftar_pengirim.php" class="d-flex gap-2 mt-2">
                <button type="submit" name="kirim_ulang_otp" class="btn btn-sm btn-outline-light w-50">Kirim Ulang Kode</button>
                <button type="submit" name="batal_otp" class="btn btn-sm btn-outline-light w-50">Ganti Email</button>
            </form>
        <?php else: ?>
            <form method="POST" action="daftar_pengirim.php" autocomplete="off">
                <div class="mb-2">
                    <label class="form-label">Nama Lengkap / Nama Instansi</label>
                    <input type="text" name="nama" class="form-control" required autocomplete="off"
                        value="<?= htmlspecialchars((string)($_POST['nama'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                        placeholder="Contoh: Dinas Pendidikan / Budi Santoso">
                </div>
                <div class="mb-2">
                    <label class="form-label">Email Aktif (Wajib)</label>
                    <input type="email" name="email" class="form-control" required autocomplete="off"
                        value="<?= htmlspecialchars((string)($_POST['email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                        placeholder="nama@email.com">
                </div>
                <div class="mb-2">
                    <label class="form-label">Username Akun Pengirim</label>
                    <input type="text" id="regUsername" name="username" class="form-control" required autocomplete="off"
                        value="<?= htmlspecialchars((string)($_POST['username'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                        placeholder="Buat username unik...">
                </div>
                <div class="mb-2">
                    <label class="form-label">Kata Sandi</label>
                    <div class="sandi-wrapper">
                        <input type="password" id="regPass" name="password" class="form-control" required autocomplete="new-password" placeholder="Minimal 6 karakter">
                        <button type="button" class="btn-toggle-sandi toggle-sandi-dp" data-target="regPass" aria-label="Tampilkan sandi" title="Tampilkan sandi"></button>
                    </div>
                </div>
                <div class="mb-2">
                    <label class="form-label">Konfirmasi Kata Sandi</label>
                    <div class="sandi-wrapper">
                        <input type="password" id="regKonfPass" name="konfirmasi_password" class="form-control" required autocomplete="new-password" placeholder="Ulangi kata sandi">
                        <button type="button" class="btn-toggle-sandi toggle-sandi-dp" data-target="regKonfPass" aria-label="Tampilkan sandi" title="Tampilkan sandi"></button>
                    </div>
                </div>
                <button type="submit" class="btn-daftar">Kirim Kode OTP Email</button>
            </form>
        <?php endif; ?>

        <a href="login.php" class="back-link">← Sudah punya akun? Kembali ke Login</a>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const svgMataTerbuka = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
            const svgMataTertutup = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.94 10.94 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>';

            document.querySelectorAll('.toggle-sandi-dp').forEach(function (btn) {
                const target = document.getElementById(btn.getAttribute('data-target'));
                if (!target) return;
                btn.innerHTML = svgMataTerbuka;
                btn.addEventListener('click', function () {
                    const tampil = target.type === 'password';
                    target.type = tampil ? 'text' : 'password';
                    btn.innerHTML = tampil ? svgMataTertutup : svgMataTerbuka;
                });
            });
        });
    </script>
</body>
</html>