<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include '../koneksi.php';

if (
    !isset($_SESSION['user']) &&
    !isset($_SESSION['id_user']) &&
    !isset($_SESSION['username']) &&
    !isset($_SESSION['email'])
) {
    header("Location: ../login.php");
    exit();
}

$role_session = strtolower(trim((string)($_SESSION['role'] ?? ($_SESSION['level'] ?? ''))));
if (!in_array($role_session, ['kepala_dinas', 'kepala dinas', 'kadis'], true)) {
    echo "<script>alert('Akses ditolak! Halaman ini khusus untuk Kepala Dinas.'); window.location='../login.php';</script>";
    exit();
}

$menu_aktif = strtolower(trim((string)($_GET['menu'] ?? 'dashboard')));
if (!in_array($menu_aktif, ['dashboard', 'profil'], true)) {
    $menu_aktif = 'dashboard';
}

function kolom_ada($koneksi, $tabel, $kolom) {
    $t = mysqli_real_escape_string($koneksi, $tabel);
    $k = mysqli_real_escape_string($koneksi, $kolom);
    $res = mysqli_query($koneksi, "SHOW COLUMNS FROM `$t` LIKE '$k'");
    return ($res && mysqli_num_rows($res) > 0);
}

function tabel_ada_kd($koneksi, $tabel) {
    $t = mysqli_real_escape_string($koneksi, $tabel);
    $res = mysqli_query($koneksi, "SHOW TABLES LIKE '$t'");
    return ($res && mysqli_num_rows($res) > 0);
}

function deteksi_pk_kd($koneksi, $tabel) {
    $t = mysqli_real_escape_string($koneksi, $tabel);
    $res = mysqli_query($koneksi, "SHOW KEYS FROM `$t` WHERE Key_name = 'PRIMARY'");
    if ($res && ($row = mysqli_fetch_assoc($res)) && !empty($row['Column_name'])) {
        return $row['Column_name'];
    }
    return 'id';
}

function deteksi_kolom_pertama_kd($koneksi, $tabel, array $kandidat) {
    foreach ($kandidat as $col) {
        if (kolom_ada($koneksi, $tabel, $col)) {
            return $col;
        }
    }
    return null;
}

function muat_konfigurasi_smtp_kd(): array {
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

function kirim_smtp_socket_kd(
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

function kirim_kode_verifikasi_profil_kd(string $email_tujuan, string $kode_otp, string $nama_user): array {
    $cfg = muat_konfigurasi_smtp_kd();
    $subjek = 'Kode Persetujuan Pembaruan Profil - Portal E-Office Diskominfo Sangihe';
    $isi_pesan = "Halo " . ($nama_user !== '' ? $nama_user : 'Kepala Dinas') . ",\n\n"
        . "Berikut kode persetujuan untuk memperbarui data profil dan/atau keamanan akun Kepala Dinas:\n"
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
                $mail->addAddress($email_tujuan, $nama_user !== '' ? $nama_user : 'Kepala Dinas');
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
    $ok = kirim_smtp_socket_kd(
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

$pesan_sukses = '';
$pesan_error  = '';

// Muat struktur & data akun Kepala Dinas dari tabel users jika tersedia
$pk_users       = tabel_ada_kd($koneksi, 'users') ? deteksi_pk_kd($koneksi, 'users') : 'id';
$kol_u_nama     = tabel_ada_kd($koneksi, 'users') ? deteksi_kolom_pertama_kd($koneksi, 'users', ['nama', 'nama_lengkap', 'full_name']) : null;
$kol_u_nip      = tabel_ada_kd($koneksi, 'users') ? deteksi_kolom_pertama_kd($koneksi, 'users', ['nip']) : null;
$kol_u_email    = tabel_ada_kd($koneksi, 'users') ? deteksi_kolom_pertama_kd($koneksi, 'users', ['email']) : null;
$kol_u_username = tabel_ada_kd($koneksi, 'users') ? deteksi_kolom_pertama_kd($koneksi, 'users', ['username', 'user']) : null;
$kol_u_password = tabel_ada_kd($koneksi, 'users') ? deteksi_kolom_pertama_kd($koneksi, 'users', ['password', 'sandi', 'kata_sandi']) : null;
$kol_u_role     = tabel_ada_kd($koneksi, 'users') ? deteksi_kolom_pertama_kd($koneksi, 'users', ['role', 'level']) : null;

$data_akun_kd = [];
if (tabel_ada_kd($koneksi, 'users')) {
    $pk_u_esc = mysqli_real_escape_string($koneksi, $pk_users);
    $id_sesi  = (int)($_SESSION['id_user'] ?? ($_SESSION['user_id'] ?? 0));
    $usr_sesi = trim((string)($_SESSION['username'] ?? ''));

    if ($id_sesi > 0) {
        $res_u = mysqli_query($koneksi, "SELECT * FROM `users` WHERE `$pk_u_esc` = $id_sesi LIMIT 1");
        if ($res_u && ($r_u = mysqli_fetch_assoc($res_u))) {
            $data_akun_kd = $r_u;
        }
    }
    if (empty($data_akun_kd) && $kol_u_username !== null && $usr_sesi !== '') {
        $u_esc = mysqli_real_escape_string($koneksi, $usr_sesi);
        $res_u = mysqli_query($koneksi, "SELECT * FROM `users` WHERE LOWER(TRIM(`$kol_u_username`)) = LOWER('$u_esc') LIMIT 1");
        if ($res_u && ($r_u = mysqli_fetch_assoc($res_u))) {
            $data_akun_kd = $r_u;
        }
    }
    if (empty($data_akun_kd) && $kol_u_role !== null) {
        $res_u = mysqli_query($koneksi, "SELECT * FROM `users` WHERE LOWER(TRIM(`$kol_u_role`)) IN ('kepala_dinas', 'kepala dinas', 'kadis') LIMIT 1");
        if ($res_u && ($r_u = mysqli_fetch_assoc($res_u))) {
            $data_akun_kd = $r_u;
        }
    }
}

$nama_login = trim((string)(
    ($kol_u_nama !== null ? ($data_akun_kd[$kol_u_nama] ?? '') : '') ?:
    ($_SESSION['google_name'] ??
    ($_SESSION['nama'] ??
    ($_SESSION['name'] ??
    ($_SESSION['username'] ?? 'Ronald Lumiu SH'))))
));
$nama_login = rtrim($nama_login, " \t\n\r\0\x0B!");
if ($nama_login === '') {
    $nama_login = 'Ronald Lumiu SH';
}

$nip_login      = trim((string)($kol_u_nip !== null ? ($data_akun_kd[$kol_u_nip] ?? '') : ($_SESSION['nip'] ?? '')));
$email_login    = trim((string)($kol_u_email !== null ? ($data_akun_kd[$kol_u_email] ?? '') : ($_SESSION['email'] ?? '')));
$username_login = trim((string)($kol_u_username !== null ? ($data_akun_kd[$kol_u_username] ?? '') : ($_SESSION['username'] ?? 'kadis')));

$draft_profil_kd = $_SESSION['draft_profil_kd'] ?? [];
$otp_aktif_kd    = $_SESSION['otp_profil_kd'] ?? null;

// Langkah 1: Kirim kode persetujuan ke email aktif yang diisikan
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['kirim_kode_profil_kd'])) {
    $menu_aktif    = 'profil';
    $nama_baru     = rtrim(trim((string)($_POST['nama_profil'] ?? '')), " \t\n\r\0\x0B!");
    $nip_baru      = trim((string)($_POST['nip_profil'] ?? ''));
    $email_baru    = trim((string)($_POST['email_profil'] ?? ''));
    $username_baru = trim((string)($_POST['username_profil'] ?? ''));
    $sandi_baru    = (string)($_POST['sandi_baru_profil'] ?? '');
    $sandi_konf    = (string)($_POST['sandi_konfirmasi_profil'] ?? '');

    if ($nama_baru === '') {
        $pesan_error = 'Nama Kepala Dinas wajib diisi.';
    } elseif ($kol_u_username !== null && $username_baru === '') {
        $pesan_error = 'Username wajib diisi.';
    } elseif ($email_baru === '' || !filter_var($email_baru, FILTER_VALIDATE_EMAIL)) {
        $pesan_error = 'Masukkan alamat email aktif yang valid untuk menerima kode persetujuan.';
    } elseif ($sandi_baru !== '' && strlen($sandi_baru) < 6) {
        $pesan_error = 'Kata sandi baru minimal 6 karakter.';
    } elseif ($sandi_baru !== '' && $sandi_baru !== $sandi_konf) {
        $pesan_error = 'Konfirmasi kata sandi baru tidak sama.';
    } else {
        $kode_otp = (string)random_int(100000, 999999);
        $draft_baru = [
            'nama'          => $nama_baru,
            'nip'           => $nip_baru,
            'email'         => $email_baru,
            'username'      => $username_baru,
            'password_hash' => ($sandi_baru !== '') ? password_hash($sandi_baru, PASSWORD_DEFAULT) : '',
        ];
        $otp_baru = [
            'email'      => $email_baru,
            'kode'       => $kode_otp,
            'expires_at' => time() + 600,
        ];

        $_SESSION['draft_profil_kd'] = $draft_baru;
        $_SESSION['otp_profil_kd']   = $otp_baru;
        $draft_profil_kd = $draft_baru;
        $otp_aktif_kd    = $otp_baru;

        $hasil_kirim = kirim_kode_verifikasi_profil_kd($email_baru, $kode_otp, $nama_baru);
        if ($hasil_kirim['terkirim']) {
            $pesan_sukses = 'Kode persetujuan telah dikirim ke email aktif ' . $email_baru . '. Silakan cek Inbox atau folder Spam, lalu masukkan kodenya.';
        } else {
            $detail_err = trim((string)($hasil_kirim['error'] ?? ''));
            $pesan_error  = 'Gagal mengirim OTP ke email ' . $email_baru . ($detail_err !== '' ? ' (' . $detail_err . ')' : '') . '.';
        }
    }
}

// Batalkan draft / kode persetujuan
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['batal_kode_profil_kd'])) {
    $menu_aktif = 'profil';
    unset($_SESSION['draft_profil_kd'], $_SESSION['otp_profil_kd']);
    $draft_profil_kd = [];
    $otp_aktif_kd    = null;
    $pesan_sukses    = 'Permintaan verifikasi email dibatalkan.';
}

// Langkah 2: Verifikasi kode persetujuan email & simpan perubahan profil ke database
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['simpan_profil_kadis'])) {
    $menu_aktif = 'profil';
    $kode_input = trim((string)($_POST['kode_persetujuan_email'] ?? ''));

    if (!is_array($otp_aktif_kd) || empty($draft_profil_kd)) {
        $pesan_error = 'Pembaruan profil membutuhkan persetujuan email aktif. Isi email aktif lalu klik "Kirim Kode ke Email" terlebih dahulu.';
    } elseif ($kode_input === '') {
        $pesan_error = 'Masukkan kode persetujuan yang dikirim ke email aktif Anda.';
    } elseif (time() > (int)($otp_aktif_kd['expires_at'] ?? 0)) {
        $pesan_error = 'Kode persetujuan sudah kedaluwarsa. Klik "Kirim Kode ke Email" untuk meminta kode baru.';
    } elseif (!hash_equals((string)($otp_aktif_kd['kode'] ?? ''), $kode_input)) {
        $pesan_error = 'Kode persetujuan email tidak sesuai. Periksa kembali kode di email aktif Anda.';
    } elseif (!tabel_ada_kd($koneksi, 'users') || empty($data_akun_kd)) {
        $pesan_error = 'Data akun pengguna pada tabel users tidak ditemukan.';
    } else {
        $nama_baru     = (string)($draft_profil_kd['nama'] ?? '');
        $nip_baru      = (string)($draft_profil_kd['nip'] ?? '');
        $email_baru    = (string)($draft_profil_kd['email'] ?? '');
        $username_baru = (string)($draft_profil_kd['username'] ?? '');
        $hash_baru     = (string)($draft_profil_kd['password_hash'] ?? '');

        $id_target_u = (int)($data_akun_kd[$pk_users] ?? ($_SESSION['id_user'] ?? ($_SESSION['user_id'] ?? 0)));
        $set_u = [];

        if ($kol_u_nama !== null) {
            $set_u[] = "`$kol_u_nama` = '" . mysqli_real_escape_string($koneksi, $nama_baru) . "'";
        }
        if ($kol_u_nip !== null) {
            $set_u[] = "`$kol_u_nip` = '" . mysqli_real_escape_string($koneksi, $nip_baru) . "'";
        }
        if ($kol_u_email !== null && $email_baru !== '') {
            $set_u[] = "`$kol_u_email` = '" . mysqli_real_escape_string($koneksi, $email_baru) . "'";
        }
        if ($kol_u_username !== null && $username_baru !== '') {
            $set_u[] = "`$kol_u_username` = '" . mysqli_real_escape_string($koneksi, $username_baru) . "'";
        }
        if ($kol_u_password !== null && $hash_baru !== '') {
            $set_u[] = "`$kol_u_password` = '" . mysqli_real_escape_string($koneksi, $hash_baru) . "'";
        }

        if (empty($set_u) || $id_target_u <= 0) {
            $pesan_error = 'Tidak ada kolom profil yang dapat diperbarui.';
        } else {
            $pk_u_esc = mysqli_real_escape_string($koneksi, $pk_users);
            $sql_u = "UPDATE `users` SET " . implode(', ', $set_u) . " WHERE `$pk_u_esc` = $id_target_u LIMIT 1";
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

                unset($_SESSION['draft_profil_kd'], $_SESSION['otp_profil_kd']);
                $draft_profil_kd = [];
                $otp_aktif_kd    = null;

                $res_u_fresh = mysqli_query($koneksi, "SELECT * FROM `users` WHERE `$pk_u_esc` = $id_target_u LIMIT 1");
                if ($res_u_fresh && ($r_fresh = mysqli_fetch_assoc($res_u_fresh))) {
                    $data_akun_kd   = $r_fresh;
                    $nama_login     = rtrim(trim((string)($data_akun_kd[$kol_u_nama ?? ''] ?? $nama_baru)), " \t\n\r\0\x0B!");
                    $nip_login      = trim((string)($data_akun_kd[$kol_u_nip ?? ''] ?? $nip_baru));
                    $email_login    = trim((string)($data_akun_kd[$kol_u_email ?? ''] ?? $email_baru));
                    $username_login = trim((string)($data_akun_kd[$kol_u_username ?? ''] ?? $username_baru));
                }

                $pesan_sukses = 'Persetujuan email berhasil diverifikasi. Profil dan keamanan akun Kepala Dinas telah diperbarui.' . ($hash_baru !== '' ? ' Kata sandi baru juga aktif.' : '');
            } else {
                $pesan_error = 'Gagal menyimpan perubahan profil: ' . mysqli_error($koneksi);
            }
        }
    }
}

$val_nama_form     = (string)($draft_profil_kd['nama'] ?? $nama_login);
$val_nip_form      = (string)($draft_profil_kd['nip'] ?? $nip_login);
$val_username_form = (string)($draft_profil_kd['username'] ?? $username_login);
$val_email_form    = (string)($draft_profil_kd['email'] ?? $email_login);
$menunggu_otp_kd   = is_array($otp_aktif_kd) && !empty($draft_profil_kd);

$kandidat_logo = [
    '../assets/logo_sangihe.png',
    'assets/logo_sangihe.png',
    '../logo_sangihe.png',
    '../assets/img/logo_sangihe.png',
];
$url_logo = '../assets/logo_sangihe.png';
foreach ($kandidat_logo as $rel) {
    if (file_exists(__DIR__ . '/' . $rel) && is_file(__DIR__ . '/' . $rel)) {
        $url_logo = $rel;
        break;
    }
}

$ada_kadis     = kolom_ada($koneksi, 'surat_masuk', 'diproses_kadis');
$ada_penerima  = kolom_ada($koneksi, 'surat_masuk', 'penerima_sekarang');
$ada_disposisi = kolom_ada($koneksi, 'surat_masuk', 'disposisi_kepada');

if ($ada_kadis && $ada_penerima && $ada_disposisi) {
    $q_masuk = "SELECT COUNT(*) AS total FROM surat_masuk
                WHERE diproses_kadis = 0
                AND LOWER(TRIM(COALESCE(status_surat, ''))) NOT IN ('selesai', 'diarsipkan', 'arsip')
                AND (LOWER(TRIM(COALESCE(penerima_sekarang, ''))) IN ('kepala_dinas', 'kepala dinas', 'kadis')
                     OR LOWER(TRIM(COALESCE(disposisi_kepada, ''))) IN ('kepala_dinas', 'kepala dinas', 'kadis'))";
} elseif ($ada_kadis) {
    $q_masuk = "SELECT COUNT(*) AS total FROM surat_masuk
                WHERE diproses_kadis = 0
                AND LOWER(TRIM(COALESCE(status_surat, ''))) NOT IN ('selesai', 'diarsipkan', 'arsip')";
} else {
    $q_masuk = "SELECT COUNT(*) AS total FROM surat_masuk
                WHERE LOWER(TRIM(COALESCE(status_surat, ''))) NOT IN ('diproses', 'selesai', 'diarsipkan', 'arsip')";
}

if ($ada_kadis) {
    $q_proses = "SELECT COUNT(*) AS total FROM surat_masuk
                 WHERE diproses_kadis = 1
                 AND LOWER(TRIM(COALESCE(status_surat, ''))) NOT IN ('selesai', 'diarsipkan', 'arsip')";
} else {
    $q_proses = "SELECT COUNT(*) AS total FROM surat_masuk
                 WHERE LOWER(TRIM(COALESCE(status_surat, ''))) = 'diproses'";
}

$q_selesai = "SELECT COUNT(*) AS total FROM surat_masuk
              WHERE LOWER(TRIM(COALESCE(status_surat, ''))) IN ('selesai', 'diarsipkan', 'arsip')";

$jml_masuk   = (int)(mysqli_fetch_assoc(mysqli_query($koneksi, $q_masuk))['total'] ?? 0);
$jml_proses  = (int)(mysqli_fetch_assoc(mysqli_query($koneksi, $q_proses))['total'] ?? 0);
$jml_selesai = (int)(mysqli_fetch_assoc(mysqli_query($koneksi, $q_selesai))['total'] ?? 0);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $menu_aktif === 'profil' ? 'Pengaturan Profil Kepala Dinas' : 'Dashboard Kepala Dinas'; ?> - E-Office</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            background-color: #f4f7fb;
            color: #1e293b;
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            margin: 0;
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
            padding: 18px 14px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        .sidebar-logo-box {
            background-color: #ffffff;
            border-radius: 10px;
            padding: 4px 8px;
            width: 100%;
            height: 102px;
            overflow: hidden;
            display: flex;
            justify-content: center;
            align-items: center;
            margin-bottom: 18px;
        }
        .sidebar-logo-img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            transform: scale(1.08);
            display: block;
            background-color: #ffffff;
        }
        .sidebar .nav-link {
            color: #334155;
            padding: 11px 14px;
            border-radius: 10px;
            margin-bottom: 6px;
            font-size: 0.92rem;
            font-weight: 500;
            transition: all 0.2s ease;
        }
        .sidebar .nav-link:hover {
            background-color: #f1f5f9;
            color: #0f172a;
        }
        .sidebar .nav-link.active {
            background-color: #0d6efd;
            color: #ffffff;
            font-weight: 600;
            box-shadow: 0 4px 12px rgba(13, 110, 253, 0.22);
        }
        .sidebar .logout-link {
            color: #dc2626 !important;
            font-weight: 500;
            padding: 10px 14px;
            border-radius: 10px;
            text-decoration: none;
            display: flex;
            align-items: center;
        }
        .sidebar .logout-link:hover {
            background-color: #fef2f2;
        }
        .main-content {
            margin-left: 260px;
            padding: 26px 30px;
        }
        .header-biru-kadis {
            background-color: #3b6998;
            border: 1px solid #2f5780;
            color: #ffffff;
            border-radius: 14px;
            padding: 22px 26px;
            box-shadow: 0 8px 20px rgba(47, 87, 128, 0.16);
        }
        .badge-putih-kadis {
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
        .welcome-card-kadis {
            background-color: #ffffff;
            border: 1px solid #e2e8f0;
            border-left: 5px solid #2f5780;
            border-radius: 12px;
            padding: 18px 24px;
            box-shadow: 0 4px 14px rgba(15, 23, 42, 0.04);
        }
        .stat-card-link {
            display: block;
            text-decoration: none;
            color: inherit;
            height: 100%;
        }
        .stat-card {
            background-color: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            box-shadow: 0 4px 14px rgba(15, 23, 42, 0.04);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            cursor: pointer;
        }
        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 10px 22px rgba(47, 87, 128, 0.12);
        }
        .kartu-profil-putih {
            background-color: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            box-shadow: 0 4px 14px rgba(15, 23, 42, 0.04);
            color: #1e293b;
        }
        .avatar-profil-kd {
            width: 64px;
            height: 64px;
            border-radius: 16px;
            background-color: #e8f0fe;
            color: #1a73e8;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            flex-shrink: 0;
        }
        .sandi-wrapper-kd { position: relative; }
        .sandi-wrapper-kd .form-control { padding-right: 42px; }
        .btn-toggle-sandi-kd {
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
        .btn-toggle-sandi-kd:hover {
            color: #0d6efd;
            background-color: rgba(13, 110, 253, 0.08);
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
    <aside class="sidebar">
        <div>
            <div class="sidebar-logo-box">
                <img src="<?= htmlspecialchars($url_logo, ENT_QUOTES, 'UTF-8'); ?>" alt="Logo Sangihe" class="sidebar-logo-img">
            </div>
            <ul class="nav nav-pills flex-column mb-auto">
                <li class="nav-item">
                    <a href="dashboard.php" class="nav-link <?= $menu_aktif === 'dashboard' ? 'active' : ''; ?>">
                        <i class="bi bi-house-door-fill me-2"></i> Dashboard
                    </a>
                </li>
                <li class="nav-item">
                    <a href="disposisi_masuk.php" class="nav-link">
                        <i class="bi bi-envelope-paper-fill me-2"></i> Disposisi Masuk
                    </a>
                </li>
                <li class="nav-item">
                    <a href="monitoring_masuk.php" class="nav-link">
                        <i class="bi bi-diagram-3-fill me-2"></i> Monitoring Disposisi
                    </a>
                </li>
                <li class="nav-item">
                    <a href="arsip.php" class="nav-link">
                        <i class="bi bi-folder-fill me-2"></i> Arsip Surat
                    </a>
                </li>
                <li class="nav-item">
                    <a href="dashboard.php?menu=profil" class="nav-link <?= $menu_aktif === 'profil' ? 'active' : ''; ?>">
                        <i class="bi bi-gear-fill me-2"></i> Pengaturan Profil
                    </a>
                </li>
            </ul>
        </div>
        <div class="pt-3 border-top border-light-subtle">
            <a href="../logout.php" class="logout-link">
                <i class="bi bi-box-arrow-left me-2"></i> Keluar Sistem
            </a>
        </div>
    </aside>

    <main class="main-content">
        <div class="container-fluid px-0">
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
                <div class="header-biru-kadis mb-4 d-flex justify-content-between align-items-center flex-wrap gap-3">
                    <div>
                        <h4 class="fw-bold mb-1 text-white">Pengaturan Profil Kepala Dinas</h4>
                        <p class="mb-0 small" style="color: #dbeafe;">Perbarui identitas pejabat, email aktif, dan kata sandi akun Kepala Dinas.</p>
                    </div>
                    <div>
                        <span class="badge-putih-kadis"><i class="bi bi-person-badge me-1"></i> KEPALA DINAS</span>
                    </div>
                </div>

                <div class="row g-4">
                    <div class="col-lg-4">
                        <div class="kartu-profil-putih p-4 h-100">
                            <div class="d-flex align-items-center gap-3 mb-3">
                                <div class="avatar-profil-kd">
                                    <i class="bi bi-person-badge"></i>
                                </div>
                                <div>
                                    <h6 class="fw-bold mb-1 text-dark"><?= htmlspecialchars($nama_login, ENT_QUOTES, 'UTF-8'); ?></h6>
                                    <span class="badge bg-primary">Kepala Dinas</span>
                                </div>
                            </div>
                            <hr>
                            <div class="small mb-2">
                                <div class="text-muted">Jabatan / Peran</div>
                                <div class="fw-semibold text-dark">Kepala Dinas</div>
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
                        <div class="kartu-profil-putih p-4">
                            <h6 class="fw-bold mb-2 text-dark"><i class="bi bi-shield-check me-2 text-primary"></i>Perbarui Data Profil &amp; Keamanan Akun (Persetujuan Email Aktif)</h6>
                            <p class="small text-muted mb-3">Setiap pembaruan profil atau kata sandi memerlukan kode persetujuan yang dikirim ke <strong>email aktif</strong> yang Anda isikan.</p>

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
                                        <label class="form-label small fw-semibold text-dark">Email Aktif (Wajib untuk Persetujuan)</label>
                                        <input type="email" name="email_profil" class="form-control" value="<?= htmlspecialchars($val_email_form, ENT_QUOTES, 'UTF-8'); ?>" placeholder="nama@emailaktif.com" required>
                                    </div>

                                    <div class="col-12"><hr class="my-1"><div class="small fw-bold text-muted">Keamanan Akun — Ganti Kata Sandi (Opsional)</div></div>

                                    <div class="col-md-6">
                                        <label class="form-label small fw-semibold text-dark">Kata Sandi Baru</label>
                                        <div class="sandi-wrapper-kd">
                                            <input type="password" id="sandiBaruProfilKd" name="sandi_baru_profil" class="form-control" placeholder="Minimal 6 karakter..." minlength="6" autocomplete="new-password">
                                            <button type="button" class="btn-toggle-sandi-kd toggle-sandi-kd" data-target="sandiBaruProfilKd" aria-label="Tampilkan sandi" title="Tampilkan sandi">
                                                <i class="bi bi-eye"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-semibold text-dark">Konfirmasi Kata Sandi Baru</label>
                                        <div class="sandi-wrapper-kd">
                                            <input type="password" id="sandiKonfirmasiProfilKd" name="sandi_konfirmasi_profil" class="form-control" placeholder="Ulangi kata sandi baru..." minlength="6" autocomplete="new-password">
                                            <button type="button" class="btn-toggle-sandi-kd toggle-sandi-kd" data-target="sandiKonfirmasiProfilKd" aria-label="Tampilkan sandi" title="Tampilkan sandi">
                                                <i class="bi bi-eye"></i>
                                            </button>
                                        </div>
                                    </div>

                                    <div class="col-12"><hr class="my-1"><div class="small fw-bold text-primary">Persetujuan Melalui Email Aktif</div></div>

                                    <div class="col-md-6">
                                        <label class="form-label small fw-semibold text-dark">Kode Persetujuan (6 Digit dari Email)</label>
                                        <input type="text" name="kode_persetujuan_email" class="form-control" maxlength="6" pattern="[0-9]{6}" placeholder="Contoh: 123456" autocomplete="one-time-code">
                                        <div class="form-text">
                                            <?php if ($menunggu_otp_kd): ?>
                                                Kode dikirim ke <strong><?= htmlspecialchars((string)$otp_aktif_kd['email'], ENT_QUOTES, 'UTF-8'); ?></strong>.
                                            <?php else: ?>
                                                Klik <strong>Kirim Kode ke Email</strong> terlebih dahulu.
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="col-md-6 d-flex align-items-start pt-md-4 gap-2 flex-wrap">
                                        <button type="submit" name="kirim_kode_profil_kd" value="1" formnovalidate class="btn btn-outline-primary fw-semibold">
                                            <i class="bi bi-envelope-check me-1"></i> <?= $menunggu_otp_kd ? 'Kirim Ulang Kode' : 'Kirim Kode ke Email'; ?>
                                        </button>
                                        <?php if ($menunggu_otp_kd): ?>
                                            <button type="submit" name="batal_kode_profil_kd" value="1" formnovalidate class="btn btn-outline-danger btn-sm mt-1">
                                                Batalkan
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="mt-4 d-flex justify-content-end gap-2">
                                    <a href="dashboard.php" class="btn btn-outline-secondary">Kembali ke Dashboard</a>
                                    <button type="submit" name="simpan_profil_kadis" value="1" class="btn btn-primary fw-semibold px-4">
                                        <i class="bi bi-check2-circle me-1"></i> Verifikasi &amp; Simpan Profil
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="header-biru-kadis mb-3 d-flex justify-content-between align-items-center flex-wrap gap-3">
                    <div>
                        <h4 class="fw-bold mb-1 text-white">Dashboard Kepala Dinas</h4>
                        <p class="mb-0 small" style="color: #dbeafe;">Kelola disposisi surat masuk, pantau tindak lanjut disposisi, dan tinjau arsip surat.</p>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <a href="dashboard.php?menu=profil" class="btn btn-outline-light btn-sm rounded-pill px-3 fw-semibold">
                            <i class="bi bi-person-gear me-1"></i> Profil
                        </a>
                        <span class="badge-putih-kadis"><i class="bi bi-person-badge me-1"></i> KEPALA DINAS</span>
                    </div>
                </div>

                <div class="welcome-card-kadis mb-4">
                    <h5 class="fw-bold mb-1" style="color: #0f172a;">Hallo, <?= htmlspecialchars($nama_login, ENT_QUOTES, 'UTF-8'); ?> 👋</h5>
                    <p class="text-muted small mb-0">Gunakan kartu statistik di bawah atau menu sidebar untuk memantau disposisi dan mengelola arsip surat.</p>
                </div>

                <div class="row g-4">
                    <div class="col-md-4">
                        <a href="disposisi_masuk.php" class="stat-card-link" title="Buka Disposisi Masuk">
                            <div class="card p-4 stat-card h-100">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <div class="text-muted small">Disposisi Masuk</div>
                                        <h2 class="fw-bold text-primary mb-0 mt-1"><?= $jml_masuk; ?></h2>
                                        <small class="text-primary d-inline-block mt-2">Lihat daftar <i class="bi bi-arrow-right"></i></small>
                                    </div>
                                    <i class="bi bi-envelope-paper fs-1 text-primary opacity-50"></i>
                                </div>
                            </div>
                        </a>
                    </div>

                    <div class="col-md-4">
                        <a href="monitoring_masuk.php" class="stat-card-link" title="Buka Monitoring Disposisi">
                            <div class="card p-4 stat-card h-100">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <div class="text-muted small">Sedang Diproses</div>
                                        <h2 class="fw-bold text-warning mb-0 mt-1"><?= $jml_proses; ?></h2>
                                        <small class="text-warning d-inline-block mt-2">Pantau progres <i class="bi bi-arrow-right"></i></small>
                                    </div>
                                    <i class="bi bi-hourglass-split fs-1 text-warning opacity-50"></i>
                                </div>
                            </div>
                        </a>
                    </div>

                    <div class="col-md-4">
                        <a href="arsip.php" class="stat-card-link" title="Buka Arsip Surat">
                            <div class="card p-4 stat-card h-100">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <div class="text-muted small">Selesai</div>
                                        <h2 class="fw-bold text-success mb-0 mt-1"><?= $jml_selesai; ?></h2>
                                        <small class="text-success d-inline-block mt-2">Buka arsip <i class="bi bi-arrow-right"></i></small>
                                    </div>
                                    <i class="bi bi-check-circle fs-1 text-success opacity-50"></i>
                                </div>
                            </div>
                        </a>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.querySelectorAll('.toggle-sandi-kd').forEach(function (btn) {
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