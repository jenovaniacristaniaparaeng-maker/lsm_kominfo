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

$menu_aktif = strtolower(trim((string)($_GET['menu'] ?? 'dashboard')));
if (!in_array($menu_aktif, ['dashboard', 'profil'], true)) {
    $menu_aktif = 'dashboard';
}

$nama_raw = 'SEKRETARIS';
if (!empty($_SESSION['google_name'])) {
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
$nama_login_google = strtoupper(trim((string)$nama_raw));

function cari_logo_sangihe_sek_dash(): string {
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

function kolom_ada_sek_dash(mysqli $koneksi, string $tabel, string $kolom): bool {
    $t = mysqli_real_escape_string($koneksi, $tabel);
    $k = mysqli_real_escape_string($koneksi, $kolom);
    $res = mysqli_query($koneksi, "SHOW COLUMNS FROM `$t` LIKE '$k'");
    return ($res && mysqli_num_rows($res) > 0);
}

function tabel_ada_sek_dash(mysqli $koneksi, string $tabel): bool {
    $t = mysqli_real_escape_string($koneksi, $tabel);
    $res = mysqli_query($koneksi, "SHOW TABLES LIKE '$t'");
    return ($res && mysqli_num_rows($res) > 0);
}

function cari_kolom_sek_dash(mysqli $koneksi, string $tabel, array $kandidat): string {
    foreach ($kandidat as $kolom) {
        if (kolom_ada_sek_dash($koneksi, $tabel, $kolom)) {
            return $kolom;
        }
    }
    return '';
}

function deteksi_pk_sek_dash(mysqli $koneksi, string $tabel): string {
    $t = mysqli_real_escape_string($koneksi, $tabel);
    $res = mysqli_query($koneksi, "SHOW KEYS FROM `$t` WHERE Key_name = 'PRIMARY'");
    if ($res && ($row = mysqli_fetch_assoc($res)) && !empty($row['Column_name'])) {
        return (string)$row['Column_name'];
    }
    return 'id';
}

function muat_konfigurasi_smtp_sek_dash(): array {
    $smtp_host = (string)(getenv('SMTP_HOST') ?: 'smtp.gmail.com');
    $smtp_port = (int)(getenv('SMTP_PORT') ?: 587);
    $smtp_user = (string)(getenv('SMTP_USER') ?: '');
    $smtp_pass = (string)(getenv('SMTP_PASS') ?: '');
    $smtp_from = (string)(getenv('SMTP_FROM') ?: '');
    $smtp_nama = (string)(getenv('SMTP_NAME') ?: 'Portal E-Office Diskominfo Sangihe');

    $root_dir = dirname(__DIR__);
    foreach ([$root_dir . '/config_smtp.php', __DIR__ . '/config_smtp.php'] as $cfg_path) {
        if (file_exists($cfg_path) && is_file($cfg_path)) {
            include_once $cfg_path;
        }
    }

    $host = trim((string)$smtp_host);
    $port = (int)$smtp_port;
    $user = trim((string)$smtp_user);
    $pass = (string)$smtp_pass;
    $from = trim((string)$smtp_from);
    $nama = trim((string)$smtp_nama);

    if ($from === '' || stripos($from, 'akun_pengirim') !== false) {
        $from = $user !== '' ? $user : 'no-reply@diskominfo-sangihe.go.id';
    }

    return [
        'smtp_host' => $host !== '' ? $host : 'smtp.gmail.com',
        'smtp_port' => $port > 0 ? $port : 587,
        'smtp_user' => $user,
        'smtp_pass' => $pass,
        'from'      => $from,
        'nama'      => $nama !== '' ? $nama : 'Portal E-Office Diskominfo Sangihe',
    ];
}

function kirim_smtp_socket_sek_dash(
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

function kirim_kode_otp_sandi_sek_dash(string $email_tujuan, string $kode_otp, string $nama_user): array {
    $cfg = muat_konfigurasi_smtp_sek_dash();
    $subjek = 'Kode OTP Ganti Kata Sandi - Portal E-Office Diskominfo Sangihe';
    $isi_pesan = "Halo " . ($nama_user !== '' ? $nama_user : 'Sekretaris') . ",\n\n"
        . "Berikut kode OTP untuk mengganti kata sandi akun Sekretaris:\n"
        . "KODE OTP: " . $kode_otp . "\n\n"
        . "Kode ini berlaku selama 10 menit. Jangan bagikan kode ini kepada siapa pun.\n\n"
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
        return [
            'terkirim' => false,
            'metode'   => 'smtp_unconfigured',
            'error'    => 'SMTP sistem belum dikonfigurasi di config_smtp.php.'
        ];
    }

    $autoload = dirname(__DIR__) . '/vendor/autoload.php';
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
                $mail->addAddress($email_tujuan, $nama_user !== '' ? $nama_user : 'Sekretaris');
                $mail->Subject = $subjek;
                $mail->Body    = $isi_pesan;

                $ok = $mail->send();
                return ['terkirim' => (bool)$ok, 'metode' => 'phpmailer_smtp', 'error' => $ok ? '' : $mail->ErrorInfo];
            } catch (\Throwable $e) {
                return ['terkirim' => false, 'metode' => 'phpmailer_smtp', 'error' => $e->getMessage()];
            }
        }
    }

    $err_detail = '';
    $ok = kirim_smtp_socket_sek_dash(
        $smtp_host,
        $smtp_port,
        $smtp_user,
        $smtp_pass,
        $from_email,
        $from_name,
        $email_tujuan,
        $subjek,
        $isi_pesan,
        $err_detail
    );

    return [
        'terkirim' => $ok,
        'metode'   => 'smtp_socket',
        'error'    => $ok ? '' : $err_detail
    ];
}

$url_logo = cari_logo_sangihe_sek_dash();

$pesan_sukses = '';
$pesan_error  = '';

$pk_users       = tabel_ada_sek_dash($koneksi, 'users') ? deteksi_pk_sek_dash($koneksi, 'users') : 'id';
$kol_u_nama     = tabel_ada_sek_dash($koneksi, 'users') ? cari_kolom_sek_dash($koneksi, 'users', ['nama', 'nama_lengkap', 'full_name']) : '';
$kol_u_nip      = tabel_ada_sek_dash($koneksi, 'users') ? cari_kolom_sek_dash($koneksi, 'users', ['nip']) : '';
$kol_u_email    = tabel_ada_sek_dash($koneksi, 'users') ? cari_kolom_sek_dash($koneksi, 'users', ['email']) : '';
$kol_u_username = tabel_ada_sek_dash($koneksi, 'users') ? cari_kolom_sek_dash($koneksi, 'users', ['username', 'user']) : '';
$kol_u_password = tabel_ada_sek_dash($koneksi, 'users') ? cari_kolom_sek_dash($koneksi, 'users', ['password', 'sandi', 'kata_sandi']) : '';
$kol_u_role     = tabel_ada_sek_dash($koneksi, 'users') ? cari_kolom_sek_dash($koneksi, 'users', ['role', 'level']) : '';

$data_akun_sek = [];
if (tabel_ada_sek_dash($koneksi, 'users')) {
    $pk_u_esc = mysqli_real_escape_string($koneksi, $pk_users);
    $id_sesi  = (int)($_SESSION['id_user'] ?? ($_SESSION['user_id'] ?? ($_SESSION['id'] ?? 0)));
    $usr_sesi = trim((string)($_SESSION['username'] ?? ''));

    if ($id_sesi > 0) {
        $res_u = mysqli_query($koneksi, "SELECT * FROM `users` WHERE `$pk_u_esc` = $id_sesi LIMIT 1");
        if ($res_u && ($r_u = mysqli_fetch_assoc($res_u))) {
            $data_akun_sek = $r_u;
        }
    }
    if (empty($data_akun_sek) && $kol_u_username !== '' && $usr_sesi !== '') {
        $u_esc = mysqli_real_escape_string($koneksi, $usr_sesi);
        $res_u = mysqli_query($koneksi, "SELECT * FROM `users` WHERE LOWER(TRIM(`$kol_u_username`)) = LOWER('$u_esc') LIMIT 1");
        if ($res_u && ($r_u = mysqli_fetch_assoc($res_u))) {
            $data_akun_sek = $r_u;
        }
    }
    if (empty($data_akun_sek) && $kol_u_role !== '') {
        $res_u = mysqli_query($koneksi, "SELECT * FROM `users` WHERE LOWER(TRIM(`$kol_u_role`)) = 'sekretaris' LIMIT 1");
        if ($res_u && ($r_u = mysqli_fetch_assoc($res_u))) {
            $data_akun_sek = $r_u;
        }
    }
}

$nama_profil_tampil = trim((string)(
    ($kol_u_nama !== '' ? ($data_akun_sek[$kol_u_nama] ?? '') : '') ?:
    ($_SESSION['google_name'] ??
    ($_SESSION['nama'] ??
    ($_SESSION['name'] ??
    ($_SESSION['username'] ?? 'Sekretaris'))))
));
if ($nama_profil_tampil === '') {
    $nama_profil_tampil = 'Sekretaris';
}

$nip_login      = trim((string)($kol_u_nip !== '' ? ($data_akun_sek[$kol_u_nip] ?? '') : ($_SESSION['nip'] ?? '')));
$email_login    = trim((string)($kol_u_email !== '' ? ($data_akun_sek[$kol_u_email] ?? '') : ($_SESSION['email'] ?? '')));
$username_login = trim((string)($kol_u_username !== '' ? ($data_akun_sek[$kol_u_username] ?? '') : ($_SESSION['username'] ?? 'sekretaris')));

$draft_sandi_sek  = $_SESSION['draft_sandi_sek'] ?? [];
$otp_sandi_sek    = $_SESSION['otp_sandi_sek'] ?? null;
$id_akun_saat_ini = (int)($data_akun_sek[$pk_users] ?? ($_SESSION['id_user'] ?? ($_SESSION['user_id'] ?? ($_SESSION['id'] ?? 0))));

// Kirim kode OTP ke email aktif (khusus saat mengganti kata sandi)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['kirim_kode_otp_sandi_sek'])) {
    $menu_aktif   = 'profil';
    $nama_input   = trim((string)($_POST['nama_profil'] ?? $nama_profil_tampil));
    $email_tujuan = trim((string)($_POST['email_profil'] ?? $email_login));
    $sandi_baru   = (string)($_POST['sandi_baru_profil'] ?? '');
    $sandi_konf   = (string)($_POST['sandi_konfirmasi_profil'] ?? '');

    if ($email_tujuan === '' || !filter_var($email_tujuan, FILTER_VALIDATE_EMAIL)) {
        $pesan_error = 'Masukkan alamat email aktif yang valid untuk menerima kode OTP penggantian kata sandi.';
    } elseif ($sandi_baru === '' || strlen($sandi_baru) < 6) {
        $pesan_error = 'Isi kata sandi baru (minimal 6 karakter) terlebih dahulu sebelum meminta kode OTP.';
    } elseif ($sandi_baru !== $sandi_konf) {
        $pesan_error = 'Konfirmasi kata sandi baru tidak sama.';
    } else {
        $kode_otp = (string)random_int(100000, 999999);
        $draft_baru = [
            'email'         => $email_tujuan,
            'password_hash' => password_hash($sandi_baru, PASSWORD_DEFAULT),
        ];
        $otp_baru = [
            'email'      => $email_tujuan,
            'kode'       => $kode_otp,
            'expires_at' => time() + 600,
        ];

        $_SESSION['draft_sandi_sek'] = $draft_baru;
        $_SESSION['otp_sandi_sek']   = $otp_baru;
        $draft_sandi_sek = $draft_baru;
        $otp_sandi_sek   = $otp_baru;

        $hasil_kirim = kirim_kode_otp_sandi_sek_dash($email_tujuan, $kode_otp, $nama_input !== '' ? $nama_input : $nama_profil_tampil);
        if ($hasil_kirim['terkirim']) {
            $pesan_sukses = 'Kode OTP ganti kata sandi telah dikirim ke ' . $email_tujuan . '. Masukkan kode OTP lalu klik Simpan.';
        } else {
            $detail_err = trim((string)($hasil_kirim['error'] ?? ''));
            $pesan_error  = 'Gagal mengirim kode OTP ke ' . $email_tujuan . ($detail_err !== '' ? ' (' . $detail_err . ')' : '') . '.';
        }
    }
}

// Batalkan permintaan OTP ganti kata sandi
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['batal_otp_sandi_sek'])) {
    $menu_aktif = 'profil';
    unset($_SESSION['draft_sandi_sek'], $_SESSION['otp_sandi_sek']);
    $draft_sandi_sek = [];
    $otp_sandi_sek   = null;
    $pesan_sukses    = 'Permintaan kode OTP ganti kata sandi dibatalkan.';
}

// Simpan perubahan profil (tanpa OTP) & ganti kata sandi (wajib OTP jika kata sandi baru diisi)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['simpan_profil_sekretaris'])) {
    $menu_aktif    = 'profil';
    $nama_baru     = trim((string)($_POST['nama_profil'] ?? ''));
    $nip_baru      = trim((string)($_POST['nip_profil'] ?? ''));
    $email_baru    = trim((string)($_POST['email_profil'] ?? ''));
    $username_baru = trim((string)($_POST['username_profil'] ?? ''));
    $sandi_baru    = (string)($_POST['sandi_baru_profil'] ?? '');
    $sandi_konf    = (string)($_POST['sandi_konfirmasi_profil'] ?? '');
    $kode_otp_in   = trim((string)($_POST['kode_otp_sandi'] ?? ''));

    $ingin_ganti_sandi = ($sandi_baru !== '' || $sandi_konf !== '' || !empty($draft_sandi_sek) || $kode_otp_in !== '');
    $hash_sandi_final  = '';

    if ($nama_baru === '') {
        $pesan_error = 'Nama Sekretaris wajib diisi.';
    } elseif ($kol_u_username !== '' && $username_baru === '') {
        $pesan_error = 'Username wajib diisi.';
    } elseif ($email_baru !== '' && !filter_var($email_baru, FILTER_VALIDATE_EMAIL)) {
        $pesan_error = 'Format alamat email tidak valid.';
    } elseif ($ingin_ganti_sandi) {
        if ($sandi_baru !== '') {
            if (strlen($sandi_baru) < 6) {
                $pesan_error = 'Kata sandi baru minimal 6 karakter.';
            } elseif ($sandi_baru !== $sandi_konf) {
                $pesan_error = 'Konfirmasi kata sandi baru tidak sama.';
            }
        } elseif (empty($draft_sandi_sek['password_hash'])) {
            $pesan_error = 'Isi kata sandi baru dan konfirmasinya terlebih dahulu.';
        }

        if ($pesan_error === '') {
            if (!is_array($otp_sandi_sek) || empty($draft_sandi_sek['password_hash'])) {
                $pesan_error = 'Mengganti kata sandi membutuhkan kode OTP. Klik "Kirim Kode OTP" ke email aktif terlebih dahulu.';
            } elseif ($kode_otp_in === '') {
                $pesan_error = 'Masukkan kode OTP yang dikirim ke email aktif untuk mengganti kata sandi.';
            } elseif (time() > (int)($otp_sandi_sek['expires_at'] ?? 0)) {
                $pesan_error = 'Kode OTP sudah kedaluwarsa. Klik "Kirim Kode OTP" untuk meminta kode baru.';
            } elseif (!hash_equals((string)($otp_sandi_sek['kode'] ?? ''), $kode_otp_in)) {
                $pesan_error = 'Kode OTP tidak sesuai. Periksa kembali kode di email aktif Anda.';
            } else {
                $hash_sandi_final = ($sandi_baru !== '')
                    ? password_hash($sandi_baru, PASSWORD_DEFAULT)
                    : (string)$draft_sandi_sek['password_hash'];
            }
        }
    }

    if ($pesan_error === '') {
        if (!tabel_ada_sek_dash($koneksi, 'users') || empty($data_akun_sek) || $id_akun_saat_ini <= 0) {
            $pesan_error = 'Data akun pengguna pada tabel users tidak ditemukan.';
        } else {
            $set_u = [];
            if ($kol_u_nama !== '') {
                $set_u[] = "`$kol_u_nama` = '" . mysqli_real_escape_string($koneksi, $nama_baru) . "'";
            }
            if ($kol_u_nip !== '') {
                $set_u[] = "`$kol_u_nip` = '" . mysqli_real_escape_string($koneksi, $nip_baru) . "'";
            }
            if ($kol_u_email !== '') {
                $set_u[] = "`$kol_u_email` = '" . mysqli_real_escape_string($koneksi, $email_baru) . "'";
            }
            if ($kol_u_username !== '' && $username_baru !== '') {
                $set_u[] = "`$kol_u_username` = '" . mysqli_real_escape_string($koneksi, $username_baru) . "'";
            }
            if ($kol_u_password !== '' && $hash_sandi_final !== '') {
                $set_u[] = "`$kol_u_password` = '" . mysqli_real_escape_string($koneksi, $hash_sandi_final) . "'";
            }

            if (empty($set_u)) {
                $pesan_error = 'Tidak ada kolom profil yang dapat diperbarui.';
            } else {
                $pk_u_esc = mysqli_real_escape_string($koneksi, $pk_users);
                $sql_u = "UPDATE `users` SET " . implode(', ', $set_u) . " WHERE `$pk_u_esc` = $id_akun_saat_ini LIMIT 1";
                if (mysqli_query($koneksi, $sql_u)) {
                    $_SESSION['nama']        = $nama_baru;
                    $_SESSION['google_name'] = $nama_baru;
                    $_SESSION['nip']         = $nip_baru;
                    if ($username_baru !== '') {
                        $_SESSION['username'] = $username_baru;
                    }
                    if ($email_baru !== '') {
                        $_SESSION['email'] = $email_baru;
                    }

                    if ($hash_sandi_final !== '') {
                        unset($_SESSION['draft_sandi_sek'], $_SESSION['otp_sandi_sek']);
                        $draft_sandi_sek = [];
                        $otp_sandi_sek   = null;
                    }

                    $res_u_fresh = mysqli_query($koneksi, "SELECT * FROM `users` WHERE `$pk_u_esc` = $id_akun_saat_ini LIMIT 1");
                    if ($res_u_fresh && ($r_fresh = mysqli_fetch_assoc($res_u_fresh))) {
                        $data_akun_sek       = $r_fresh;
                        $nama_profil_tampil  = trim((string)($data_akun_sek[$kol_u_nama] ?? $nama_baru));
                        $nip_login           = trim((string)($data_akun_sek[$kol_u_nip] ?? $nip_baru));
                        $email_login         = trim((string)($data_akun_sek[$kol_u_email] ?? $email_baru));
                        $username_login      = trim((string)($data_akun_sek[$kol_u_username] ?? $username_baru));
                        $nama_login_google   = strtoupper($nama_profil_tampil !== '' ? $nama_profil_tampil : 'SEKRETARIS');
                    }

                    $pesan_sukses = ($hash_sandi_final !== '')
                        ? 'Profil dan kata sandi baru berhasil disimpan setelah verifikasi OTP.'
                        : 'Perubahan profil berhasil disimpan.';
                } else {
                    $pesan_error = 'Gagal menyimpan perubahan profil: ' . mysqli_error($koneksi);
                }
            }
        }
    }
}

$val_nama_form     = $nama_profil_tampil;
$val_nip_form      = $nip_login;
$val_username_form = $username_login;
$val_email_form    = (string)($draft_sandi_sek['email'] ?? $email_login);
$menunggu_otp_sek  = is_array($otp_sandi_sek) && !empty($draft_sandi_sek);

$cek_penerima = mysqli_query($koneksi, "SHOW COLUMNS FROM surat_masuk LIKE 'penerima_sekarang'");
$ada_penerima = ($cek_penerima && mysqli_num_rows($cek_penerima) > 0);

if ($ada_penerima) {
    $q_masuk = mysqli_query($koneksi, "SELECT COUNT(*) as total FROM surat_masuk WHERE status_surat = 'Diproses' AND (LOWER(penerima_sekarang) = 'sekretaris' OR LOWER(disposisi_kepada) = 'sekretaris')");
} else {
    $q_masuk = mysqli_query($koneksi, "SELECT COUNT(*) as total FROM surat_masuk WHERE status_surat = 'Diproses' AND LOWER(disposisi_kepada) = 'sekretaris'");
}
$d_masuk = mysqli_fetch_assoc($q_masuk);
$total_surat_masuk = (int)($d_masuk['total'] ?? 0);

$q_proses = mysqli_query($koneksi, "SELECT COUNT(*) as total FROM surat_masuk WHERE status_surat = 'Diproses'");
$d_proses = mysqli_fetch_assoc($q_proses);
$total_dalam_proses = (int)($d_proses['total'] ?? 0);

$q_arsip = mysqli_query($koneksi, "SELECT COUNT(*) as total FROM surat_masuk WHERE status_surat = 'Selesai'");
$d_arsip = mysqli_fetch_assoc($q_arsip);
$total_arsip = (int)($d_arsip['total'] ?? 0);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $menu_aktif === 'profil' ? 'Pengaturan Profil - Sekretaris' : 'Dashboard Sekretaris - E-Office'; ?></title>
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
        .card-clickable {
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            text-decoration: none !important;
        }
        .card-clickable:hover {
            transform: translateY(-4px);
            box-shadow: 0 .5rem 1rem rgba(0,0,0,.12) !important;
        }
        .dashboard-banner-sek {
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
        .greeting-card-sek,
        .kartu-profil-putih-sek {
            background-color: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            color: #1e293b;
        }
        .greeting-card-sek {
            border-left: 5px solid #3b6998;
            padding: 18px 24px;
        }
        .avatar-profil-dash-sek {
            width: 60px;
            height: 60px;
            border-radius: 16px;
            background-color: #e8f0fe;
            color: #3b6998;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.6rem;
            flex-shrink: 0;
        }
        .sandi-wrapper-dash-sek { position: relative; }
        .sandi-wrapper-dash-sek .form-control { padding-right: 42px; }
        .btn-toggle-sandi-dash-sek {
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
        .btn-toggle-sandi-dash-sek:hover {
            color: #3b6998;
            background-color: rgba(59, 105, 152, 0.1);
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
                <li class="nav-item"><a href="dashboard.php" class="nav-link <?= $menu_aktif === 'dashboard' ? 'active' : ''; ?>"><i class="bi bi-house-door-fill me-2"></i> Dashboard</a></li>
                <li class="nav-item"><a href="disposisi_masuk.php" class="nav-link"><i class="bi bi-envelope-paper-fill me-2"></i> Surat Masuk</a></li>
                <li class="nav-item"><a href="verifikasi_surat.php" class="nav-link"><i class="bi bi-check2-circle me-2"></i> Verifikasi Surat</a></li>
                <li class="nav-item"><a href="lembar_kendali.php" class="nav-link"><i class="bi bi-diagram-3-fill me-2"></i> Lembar Kendali</a></li>
                <li class="nav-item"><a href="laporan.php" class="nav-link"><i class="bi bi-file-earmark-bar-graph-fill me-2"></i> Laporan</a></li>
                <li class="nav-item"><a href="arsip.php" class="nav-link"><i class="bi bi-folder-fill me-2"></i> Arsip Surat</a></li>
                <li class="nav-item"><a href="dashboard.php?menu=profil" class="nav-link <?= $menu_aktif === 'profil' ? 'active' : ''; ?>"><i class="bi bi-gear-fill me-2"></i> Pengaturan Profil</a></li>
            </ul>
        </div>
        <hr class="text-secondary">
        <div><a href="../logout.php" class="nav-link text-danger"><i class="bi bi-box-arrow-left me-2"></i> Keluar Sistem</a></div>
    </div>

    <div class="main-content">
        <div class="container-fluid">
            <?php if ($pesan_sukses !== ''): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?= htmlspecialchars($pesan_sukses, ENT_QUOTES, 'UTF-8'); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if ($pesan_error !== ''): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?= htmlspecialchars($pesan_error, ENT_QUOTES, 'UTF-8'); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if ($menu_aktif === 'profil'): ?>
                <div class="dashboard-banner-sek mb-4">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                        <div>
                            <h4 class="fw-bold mb-1 text-white">Pengaturan Profil Sekretaris</h4>
                            <p class="mb-0 small" style="color: #dbeafe;">Perbarui identitas pejabat, email aktif, dan kata sandi akun Sekretaris.</p>
                        </div>
                        <div>
                            <span class="badge-putih-sek"><i class="bi bi-person-badge-fill me-1"></i> SEKRETARIS</span>
                        </div>
                    </div>
                </div>

                <div class="row g-4">
                    <div class="col-lg-4">
                        <div class="kartu-profil-putih-sek shadow-sm p-4 h-100">
                            <div class="d-flex align-items-center gap-3 mb-3">
                                <div class="avatar-profil-dash-sek">
                                    <i class="bi bi-person-badge"></i>
                                </div>
                                <div>
                                    <h6 class="fw-bold mb-1 text-dark"><?= htmlspecialchars($nama_profil_tampil, ENT_QUOTES, 'UTF-8'); ?></h6>
                                    <span class="badge bg-primary">Sekretaris</span>
                                </div>
                            </div>
                            <hr>
                            <div class="small mb-2">
                                <div class="text-muted">Jabatan / Peran</div>
                                <div class="fw-semibold text-dark">Sekretaris</div>
                            </div>
                            <div class="small mb-2">
                                <div class="text-muted">Username Akun</div>
                                <div class="fw-semibold text-dark"><?= htmlspecialchars($username_login !== '' ? $username_login : '-', ENT_QUOTES, 'UTF-8'); ?></div>
                            </div>
                            <div class="small mb-2">
                                <div class="text-muted">NIP</div>
                                <div class="fw-semibold text-dark"><?= htmlspecialchars($nip_login !== '' ? $nip_login : '-', ENT_QUOTES, 'UTF-8'); ?></div>
                            </div>
                            <div class="small">
                                <div class="text-muted">Email Aktif</div>
                                <div class="fw-semibold text-dark"><?= htmlspecialchars($email_login !== '' ? $email_login : '-', ENT_QUOTES, 'UTF-8'); ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-8">
                        <div class="kartu-profil-putih-sek shadow-sm p-4">
                            <h6 class="fw-bold mb-2 text-dark"><i class="bi bi-person-gear me-2 text-primary"></i>Perbarui Data Profil &amp; Kata Sandi</h6>
                            <p class="small text-muted mb-3">Perubahan profil langsung tersimpan. <strong>Kode OTP</strong> hanya diperlukan jika Anda mengisi <strong>Kata Sandi Baru</strong>.</p>

                            <form method="POST" action="dashboard.php?menu=profil" autocomplete="off">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label small fw-semibold text-dark">Nama Lengkap / Nama Pejabat</label>
                                        <input type="text" name="nama_profil" class="form-control" value="<?= htmlspecialchars($val_nama_form, ENT_QUOTES, 'UTF-8'); ?>" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-semibold text-dark">NIP</label>
                                        <input type="text" name="nip_profil" class="form-control" value="<?= htmlspecialchars($val_nip_form, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Masukkan NIP...">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-semibold text-dark">Username Login</label>
                                        <input type="text" name="username_profil" class="form-control" value="<?= htmlspecialchars($val_username_form, ENT_QUOTES, 'UTF-8'); ?>" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-semibold text-dark">Email Aktif</label>
                                        <input type="email" name="email_profil" class="form-control" value="<?= htmlspecialchars($val_email_form, ENT_QUOTES, 'UTF-8'); ?>" placeholder="nama@emailaktif.com">
                                    </div>

                                    <div class="col-12"><hr class="my-1"><div class="small fw-bold text-primary"><i class="bi bi-key me-1"></i>Ganti Kata Sandi (Opsional — Wajib Kode OTP)</div></div>

                                    <div class="col-md-6">
                                        <label class="form-label small fw-semibold text-dark">Kata Sandi Baru</label>
                                        <div class="sandi-wrapper-dash-sek">
                                            <input type="password" id="sandiBaruProfilDashSek" name="sandi_baru_profil" class="form-control" placeholder="Kosongkan jika tidak ganti sandi..." minlength="6" autocomplete="new-password">
                                            <button type="button" class="btn-toggle-sandi-dash-sek toggle-sandi-dash-sek" data-target="sandiBaruProfilDashSek" aria-label="Tampilkan sandi" title="Tampilkan sandi">
                                                <i class="bi bi-eye"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-semibold text-dark">Konfirmasi Kata Sandi Baru</label>
                                        <div class="sandi-wrapper-dash-sek">
                                            <input type="password" id="sandiKonfirmasiProfilDashSek" name="sandi_konfirmasi_profil" class="form-control" placeholder="Ulangi kata sandi baru..." minlength="6" autocomplete="new-password">
                                            <button type="button" class="btn-toggle-sandi-dash-sek toggle-sandi-dash-sek" data-target="sandiKonfirmasiProfilDashSek" aria-label="Tampilkan sandi" title="Tampilkan sandi">
                                                <i class="bi bi-eye"></i>
                                            </button>
                                        </div>
                                    </div>

                                    <div class="col-md-6">
                                        <label class="form-label small fw-semibold text-dark">Kode OTP Ganti Sandi (6 Digit dari Email)</label>
                                        <input type="text" name="kode_otp_sandi" class="form-control" maxlength="6" pattern="[0-9]{6}" placeholder="Hanya diisi saat ganti sandi..." autocomplete="one-time-code">
                                        <div class="form-text">
                                            <?php if ($menunggu_otp_sek): ?>
                                                Kode OTP dikirim ke <strong><?= htmlspecialchars((string)$otp_sandi_sek['email'], ENT_QUOTES, 'UTF-8'); ?></strong>.
                                            <?php else: ?>
                                                Tidak perlu diisi jika hanya mengubah data profil.
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="col-md-6 d-flex align-items-start pt-md-4 gap-2 flex-wrap">
                                        <button type="submit" name="kirim_kode_otp_sandi_sek" value="1" formnovalidate class="btn btn-outline-primary fw-semibold">
                                            <i class="bi bi-envelope-check me-1"></i> <?= $menunggu_otp_sek ? 'Kirim Ulang Kode OTP' : 'Kirim Kode OTP'; ?>
                                        </button>
                                        <?php if ($menunggu_otp_sek): ?>
                                            <button type="submit" name="batal_otp_sandi_sek" value="1" formnovalidate class="btn btn-outline-danger btn-sm mt-1">
                                                Batalkan OTP
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="mt-4 d-flex justify-content-end gap-2">
                                    <a href="dashboard.php" class="btn btn-outline-secondary">Kembali ke Dashboard</a>
                                    <button type="submit" name="simpan_profil_sekretaris" value="1" class="btn btn-primary fw-semibold px-4">
                                        <i class="bi bi-check2-circle me-1"></i> Simpan Perubahan
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="dashboard-banner-sek mb-4">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                        <div>
                            <h4 class="fw-bold mb-1 text-white">Dashboard Sekretaris</h4>
                            <p class="mb-0 small" style="color: #dbeafe;">Kelola surat masuk, pelacakan, dan penyelesaian surat.</p>
                        </div>
                        <div>
                            <span class="badge-putih-sek"><i class="bi bi-person-badge-fill me-1"></i> SEKRETARIS</span>
                        </div>
                    </div>
                </div>

                <div class="greeting-card-sek shadow-sm mb-4">
                    <h5 class="fw-bold mb-1 text-dark">Hallo, <?= htmlspecialchars($nama_login_google); ?> 👋</h5>
                    <p class="mb-0 text-muted small">Gunakan kartu statistik di bawah atau menu sidebar untuk melihat riwayat surat dan mengelola dokumen.</p>
                </div>

                <div class="row g-4">
                    <div class="col-md-4">
                        <a href="disposisi_masuk.php" class="card card-clickable border-0 shadow-sm p-4 h-100 bg-white d-block">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <span class="text-muted small fw-bold text-uppercase">Surat Masuk</span>
                                    <h2 class="fw-bold mt-2 mb-0 text-dark"><?= $total_surat_masuk; ?></h2>
                                    <small class="text-primary d-block mt-1">Lihat surat masuk <i class="bi bi-arrow-right-short"></i></small>
                                </div>
                                <div class="p-3 bg-primary bg-opacity-10 rounded text-primary">
                                    <i class="bi bi-envelope-check-fill fs-3"></i>
                                </div>
                            </div>
                        </a>
                    </div>

                    <div class="col-md-4">
                        <a href="lembar_kendali.php" class="card card-clickable border-0 shadow-sm p-4 h-100 bg-white d-block">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <span class="text-muted small fw-bold text-uppercase">Dalam Proses (Kendali)</span>
                                    <h2 class="fw-bold mt-2 mb-0 text-dark"><?= $total_dalam_proses; ?></h2>
                                    <small class="text-warning d-block mt-1">Pantau lembar kendali <i class="bi bi-arrow-right-short"></i></small>
                                </div>
                                <div class="p-3 bg-warning bg-opacity-10 rounded text-warning">
                                    <i class="bi bi-arrow-repeat fs-3"></i>
                                </div>
                            </div>
                        </a>
                    </div>

                    <div class="col-md-4">
                        <a href="arsip.php" class="card card-clickable border-0 shadow-sm p-4 h-100 bg-white d-block">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <span class="text-muted small fw-bold text-uppercase">Total Arsip (Selesai)</span>
                                    <h2 class="fw-bold mt-2 mb-0 text-dark"><?= $total_arsip; ?></h2>
                                    <small class="text-success d-block mt-1">Buka arsip dokumen <i class="bi bi-arrow-right-short"></i></small>
                                </div>
                                <div class="p-3 bg-success bg-opacity-10 rounded text-success">
                                    <i class="bi bi-folder-check fs-3"></i>
                                </div>
                            </div>
                        </a>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.querySelectorAll('.toggle-sandi-dash-sek').forEach(function (btn) {
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