<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include '../koneksi.php';

if (
    !isset($_SESSION['user']) &&
    !isset($_SESSION['id_user']) &&
    !isset($_SESSION['user_id']) &&
    !isset($_SESSION['id']) &&
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

$menu_aktif = strtolower(trim((string)($_GET['menu'] ?? 'disposisi_masuk')));
if (!in_array($menu_aktif, ['disposisi_masuk', 'profil'], true)) {
    $menu_aktif = 'disposisi_masuk';
}

$boleh_lihat_rahasia = in_array($role_session, ['sekretaris', 'kepala_dinas', 'kepala dinas', 'kadis'], true);

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

function kolom_ada_disposisi($koneksi, $tabel, $kolom) {
    $t = mysqli_real_escape_string($koneksi, $tabel);
    $k = mysqli_real_escape_string($koneksi, $kolom);
    $res = mysqli_query($koneksi, "SHOW COLUMNS FROM `$t` LIKE '$k'");
    return ($res && mysqli_num_rows($res) > 0);
}

function tabel_ada_disposisi($koneksi, $tabel) {
    $t = mysqli_real_escape_string($koneksi, $tabel);
    $res = mysqli_query($koneksi, "SHOW TABLES LIKE '$t'");
    return ($res && mysqli_num_rows($res) > 0);
}

function pastikan_kolom_hapus_kadis($koneksi, $tabel = 'surat_masuk') {
    if (kolom_ada_disposisi($koneksi, $tabel, 'dihapus_kadis')) {
        return 'dihapus_kadis';
    }
    @mysqli_query($koneksi, "ALTER TABLE `$tabel` ADD COLUMN `dihapus_kadis` TINYINT(1) NOT NULL DEFAULT 0");
    return kolom_ada_disposisi($koneksi, $tabel, 'dihapus_kadis') ? 'dihapus_kadis' : null;
}

function deteksi_pk_disposisi($koneksi, $tabel) {
    $t = mysqli_real_escape_string($koneksi, $tabel);
    $res = mysqli_query($koneksi, "SHOW KEYS FROM `$t` WHERE Key_name = 'PRIMARY'");
    if ($res && ($row = mysqli_fetch_assoc($res)) && !empty($row['Column_name'])) {
        return $row['Column_name'];
    }
    $res_col = mysqli_query($koneksi, "SHOW COLUMNS FROM `$t`");
    while ($res_col && ($c = mysqli_fetch_assoc($res_col))) {
        if (in_array(strtolower($c['Field']), ['id', 'id_surat', 'id_surat_masuk'], true)) {
            return $c['Field'];
        }
    }
    return 'id';
}

function deteksi_kolom_pertama($koneksi, $tabel, array $kandidat) {
    foreach ($kandidat as $col) {
        if (kolom_ada_disposisi($koneksi, $tabel, $col)) {
            return $col;
        }
    }
    return null;
}

function format_no_agenda_kadis($raw_agenda): string {
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

function unggah_bukti_dukung($file_input_name, $sub_folder = 'bukti_kadis') {
    if (!isset($_FILES[$file_input_name]) || !is_array($_FILES[$file_input_name])) {
        return ['ok' => true, 'path' => null];
    }

    $err = (int)($_FILES[$file_input_name]['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_NO_FILE) {
        return ['ok' => true, 'path' => null];
    }
    if ($err !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Gagal mengunggah bukti dukung (kode error: ' . $err . ').'];
    }

    $tmp_name = (string)($_FILES[$file_input_name]['tmp_name'] ?? '');
    $orig_name = (string)($_FILES[$file_input_name]['name'] ?? 'bukti');
    $size_byte = (int)($_FILES[$file_input_name]['size'] ?? 0);

    $max_size = 10 * 1024 * 1024;
    if ($size_byte <= 0 || $size_byte > $max_size) {
        return ['ok' => false, 'error' => 'Ukuran bukti dukung maksimal 10 MB.'];
    }

    $ext = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
    $allowed_ext = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'];
    if (!in_array($ext, $allowed_ext, true)) {
        return ['ok' => false, 'error' => 'Format bukti dukung tidak didukung. Gunakan PDF, JPG, PNG, DOC, atau DOCX.'];
    }

    $upload_dir = dirname(__DIR__) . '/uploads/' . trim($sub_folder, '/');
    if (!is_dir($upload_dir) && !mkdir($upload_dir, 0777, true)) {
        return ['ok' => false, 'error' => 'Folder penyimpanan bukti dukung gagal dibuat.'];
    }

    $safe_name = time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $dest_abs = $upload_dir . '/' . $safe_name;

    if (!move_uploaded_file($tmp_name, $dest_abs)) {
        return ['ok' => false, 'error' => 'Gagal memindahkan file bukti dukung ke folder server.'];
    }

    return ['ok' => true, 'path' => 'uploads/' . trim($sub_folder, '/') . '/' . $safe_name];
}

function normalisasi_url_berkas($raw_path) {
    $p = trim((string)$raw_path);
    if ($p === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $p)) {
        return $p;
    }
    $p = ltrim(str_replace('\\', '/', $p), '/');
    if (strpos($p, 'uploads/') === 0 || strpos($p, 'file_surat/') === 0 || strpos($p, 'surat/') === 0) {
        return '../' . $p;
    }
    if (strpos($p, '../') === 0) {
        return $p;
    }
    return '../uploads/' . $p;
}

function muat_konfigurasi_smtp_disp_kd(): array {
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

function kirim_smtp_socket_disp_kd(
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

function kirim_kode_verifikasi_profil_disp_kd(string $email_tujuan, string $kode_otp, string $nama_user): array {
    $cfg = muat_konfigurasi_smtp_disp_kd();
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
    $ok = kirim_smtp_socket_disp_kd(
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

$pk_users       = tabel_ada_disposisi($koneksi, 'users') ? deteksi_pk_disposisi($koneksi, 'users') : 'id';
$kol_u_nama     = tabel_ada_disposisi($koneksi, 'users') ? deteksi_kolom_pertama($koneksi, 'users', ['nama', 'nama_lengkap', 'full_name']) : null;
$kol_u_nip      = tabel_ada_disposisi($koneksi, 'users') ? deteksi_kolom_pertama($koneksi, 'users', ['nip']) : null;
$kol_u_email    = tabel_ada_disposisi($koneksi, 'users') ? deteksi_kolom_pertama($koneksi, 'users', ['email']) : null;
$kol_u_username = tabel_ada_disposisi($koneksi, 'users') ? deteksi_kolom_pertama($koneksi, 'users', ['username', 'user']) : null;
$kol_u_password = tabel_ada_disposisi($koneksi, 'users') ? deteksi_kolom_pertama($koneksi, 'users', ['password', 'sandi', 'kata_sandi']) : null;
$kol_u_role     = tabel_ada_disposisi($koneksi, 'users') ? deteksi_kolom_pertama($koneksi, 'users', ['role', 'level']) : null;

$data_akun_kd = [];
if (tabel_ada_disposisi($koneksi, 'users')) {
    $pk_u_esc = mysqli_real_escape_string($koneksi, $pk_users);
    $id_sesi  = (int)($_SESSION['id_user'] ?? ($_SESSION['user_id'] ?? ($_SESSION['id'] ?? 0)));
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
    ($_SESSION['username'] ?? 'Kepala Dinas'))))
));
if ($nama_login === '') {
    $nama_login = 'Kepala Dinas';
}

$nip_login      = trim((string)($kol_u_nip !== null ? ($data_akun_kd[$kol_u_nip] ?? '') : ($_SESSION['nip'] ?? '')));
$email_login    = trim((string)($kol_u_email !== null ? ($data_akun_kd[$kol_u_email] ?? '') : ($_SESSION['email'] ?? '')));
$username_login = trim((string)($kol_u_username !== null ? ($data_akun_kd[$kol_u_username] ?? '') : ($_SESSION['username'] ?? 'kadis')));

$draft_profil_kd = $_SESSION['draft_profil_kd'] ?? [];
$otp_aktif_kd    = $_SESSION['otp_profil_kd'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['kirim_kode_profil_kd'])) {
    $menu_aktif    = 'profil';
    $nama_baru     = trim((string)($_POST['nama_profil'] ?? ''));
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

        $hasil_kirim = kirim_kode_verifikasi_profil_disp_kd($email_baru, $kode_otp, $nama_baru);
        if ($hasil_kirim['terkirim']) {
            $pesan_sukses = 'Kode persetujuan telah dikirim ke email aktif ' . $email_baru . '. Silakan cek Inbox atau folder Spam, lalu masukkan kodenya.';
        } else {
            $detail_err = trim((string)($hasil_kirim['error'] ?? ''));
            $pesan_error  = 'Gagal mengirim OTP ke email ' . $email_baru . ($detail_err !== '' ? ' (' . $detail_err . ')' : '') . '.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['batal_kode_profil_kd'])) {
    $menu_aktif = 'profil';
    unset($_SESSION['draft_profil_kd'], $_SESSION['otp_profil_kd']);
    $draft_profil_kd = [];
    $otp_aktif_kd    = null;
    $pesan_sukses    = 'Permintaan verifikasi email dibatalkan.';
}

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
    } elseif (!tabel_ada_disposisi($koneksi, 'users') || empty($data_akun_kd)) {
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
                    $nama_login     = trim((string)($data_akun_kd[$kol_u_nama ?? ''] ?? $nama_baru));
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

$pk_col = deteksi_pk_disposisi($koneksi, 'surat_masuk');
$kol_hapus_kadis = pastikan_kolom_hapus_kadis($koneksi, 'surat_masuk');
$ada_kadis = kolom_ada_disposisi($koneksi, 'surat_masuk', 'diproses_kadis');
$ada_penerima = kolom_ada_disposisi($koneksi, 'surat_masuk', 'penerima_sekarang');
$ada_disposisi = kolom_ada_disposisi($koneksi, 'surat_masuk', 'disposisi_kepada');
$ada_bidang_tujuan = kolom_ada_disposisi($koneksi, 'surat_masuk', 'bidang_tujuan');
$ada_catatan_kadis = kolom_ada_disposisi($koneksi, 'surat_masuk', 'catatan_kepala_dinas');
$ada_catatan = kolom_ada_disposisi($koneksi, 'surat_masuk', 'catatan_disposisi');
$ada_sifat = kolom_ada_disposisi($koneksi, 'surat_masuk', 'sifat_surat');
$ada_status = kolom_ada_disposisi($koneksi, 'surat_masuk', 'status_surat');

$kolom_bukti_tersedia = deteksi_kolom_pertama($koneksi, 'surat_masuk', [
    'bukti_dukung_kadis', 'bukti_kadis', 'bukti_penyelesaian', 'bukti_dukung', 'file_bukti'
]);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['hapus_tampilan_kadis'])) {
    $id_surat = (int)($_POST['id_surat'] ?? 0);
    if ($id_surat <= 0) {
        $pesan_error = 'Surat tidak valid untuk dihapus dari tampilan Kepala Dinas.';
    } elseif ($kol_hapus_kadis === null) {
        $pesan_error = 'Kolom penanda hapus khusus Kepala Dinas tidak tersedia.';
    } else {
        $pk_esc = mysqli_real_escape_string($koneksi, $pk_col);
        $sql_del = "UPDATE surat_masuk SET `$kol_hapus_kadis` = 1 WHERE `$pk_esc` = $id_surat LIMIT 1";
        if (mysqli_query($koneksi, $sql_del)) {
            $pesan_sukses = 'Surat berhasil dihapus dari tampilan Kepala Dinas saja (tidak berpengaruh ke dashboard peran lain).';
        } else {
            $pesan_error = 'Gagal menghapus surat dari tampilan Kepala Dinas: ' . mysqli_error($koneksi);
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['kirim_disposisi'])) {
    $id_surat = (int)($_POST['id_surat'] ?? 0);
    $tujuan_bidang = trim((string)($_POST['tujuan_bidang'] ?? ''));
    $catatan = trim((string)($_POST['catatan_disposisi'] ?? ''));
    $sifat = trim((string)($_POST['sifat_surat'] ?? ''));

    if ($id_surat <= 0 || $tujuan_bidang === '') {
        $pesan_error = 'Pilih surat dan tujuan disposisi terlebih dahulu.';
    } else {
        $pk_esc = mysqli_real_escape_string($koneksi, $pk_col);
        $tb_esc = mysqli_real_escape_string($koneksi, $tujuan_bidang);
        $set_parts = [];

        if ($ada_status) {
            $set_parts[] = "status_surat = 'Diproses'";
        }
        if ($ada_kadis) {
            $set_parts[] = "diproses_kadis = 1";
        }
        if ($ada_disposisi) {
            $set_parts[] = "disposisi_kepada = '$tb_esc'";
        }
        if ($ada_penerima) {
            $set_parts[] = "penerima_sekarang = '$tb_esc'";
        }
        if ($ada_bidang_tujuan) {
            $set_parts[] = "bidang_tujuan = '$tb_esc'";
        }
        if ($ada_catatan_kadis) {
            $ct_esc = mysqli_real_escape_string($koneksi, $catatan);
            $set_parts[] = "catatan_kepala_dinas = '$ct_esc'";
        } elseif ($ada_catatan) {
            $ct_esc = mysqli_real_escape_string($koneksi, $catatan);
            $set_parts[] = "catatan_disposisi = '$ct_esc'";
        }

        if ($ada_sifat) {
            $is_ke_bidang = str_starts_with(strtolower($tujuan_bidang), 'bidang');
            $sifat_lama = '';
            $q_sf = mysqli_query($koneksi, "SELECT `sifat_surat` FROM `surat_masuk` WHERE `$pk_esc` = $id_surat LIMIT 1");
            if ($q_sf && ($r_sf = mysqli_fetch_assoc($q_sf))) {
                $sifat_lama = trim((string)($r_sf['sifat_surat'] ?? ''));
            }

            if ($sifat !== '') {
                $sf_esc = mysqli_real_escape_string($koneksi, $sifat);
                $set_parts[] = "sifat_surat = '$sf_esc'";
            } elseif ($is_ke_bidang && strtolower($sifat_lama) === 'rahasia') {
                $set_parts[] = "sifat_surat = 'Biasa'";
            }
        }

        if (!empty($set_parts)) {
            $sql_upd = "UPDATE surat_masuk SET " . implode(', ', $set_parts) . " WHERE `$pk_esc` = $id_surat";
            if (mysqli_query($koneksi, $sql_upd)) {
                $pesan_sukses = 'Disposisi surat berhasil diproses dan diteruskan ke ' . $tujuan_bidang . '.';
            } else {
                $pesan_error = 'Gagal memperbarui disposisi: ' . mysqli_error($koneksi);
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['selesaikan_tugas'])) {
    $id_surat = (int)($_POST['id_surat'] ?? 0);
    $catatan_selesai = trim((string)($_POST['catatan_penyelesaian'] ?? ''));

    if ($id_surat <= 0) {
        $pesan_error = 'Surat tidak valid untuk diselesaikan.';
    } else {
        $upload_res = unggah_bukti_dukung('bukti_dukung', 'bukti_kadis');
        if (!$upload_res['ok']) {
            $pesan_error = $upload_res['error'];
        } else {
            $set_parts = [];
            if ($ada_status) {
                $set_parts[] = "status_surat = 'Selesai'";
            }
            if ($ada_kadis) {
                $set_parts[] = "diproses_kadis = 1";
            }
            if ($ada_penerima) {
                $set_parts[] = "penerima_sekarang = 'Selesai (Kepala Dinas)'";
            }
            if ($catatan_selesai !== '') {
                $ct_esc = mysqli_real_escape_string($koneksi, $catatan_selesai);
                if ($ada_catatan_kadis) {
                    $set_parts[] = "catatan_kepala_dinas = '$ct_esc'";
                } elseif ($ada_catatan) {
                    $set_parts[] = "catatan_disposisi = '$ct_esc'";
                }
            }
            if (!empty($upload_res['path']) && $kolom_bukti_tersedia !== null) {
                $path_esc = mysqli_real_escape_string($koneksi, $upload_res['path']);
                $set_parts[] = "`$kolom_bukti_tersedia` = '$path_esc'";
            }

            if (!empty($set_parts)) {
                $pk_esc = mysqli_real_escape_string($koneksi, $pk_col);
                $sql_selesai = "UPDATE surat_masuk SET " . implode(', ', $set_parts) . " WHERE `$pk_esc` = $id_surat";
                if (mysqli_query($koneksi, $sql_selesai)) {
                    $pesan_sukses = 'Tugas surat berhasil diselesaikan.';
                } else {
                    $pesan_error = 'Gagal menyelesaikan tugas surat: ' . mysqli_error($koneksi);
                }
            }
        }
    }
}

$filter_hapus_kadis = ($kol_hapus_kadis !== null) ? "COALESCE(`$kol_hapus_kadis`, 0) = 0 AND " : "";

if ($ada_kadis && $ada_penerima && $ada_disposisi) {
    $sql_list = "SELECT * FROM surat_masuk
                 WHERE {$filter_hapus_kadis}diproses_kadis = 0
                 AND LOWER(TRIM(COALESCE(status_surat, ''))) NOT IN ('selesai', 'diarsipkan', 'arsip')
                 AND (LOWER(TRIM(COALESCE(penerima_sekarang, ''))) IN ('kepala_dinas', 'kepala dinas', 'kadis')
                      OR LOWER(TRIM(COALESCE(disposisi_kepada, ''))) IN ('kepala_dinas', 'kepala dinas', 'kadis'))
                 ORDER BY `$pk_col` DESC";
} elseif ($ada_kadis) {
    $sql_list = "SELECT * FROM surat_masuk
                 WHERE {$filter_hapus_kadis}diproses_kadis = 0
                 AND LOWER(TRIM(COALESCE(status_surat, ''))) NOT IN ('selesai', 'diarsipkan', 'arsip')
                 ORDER BY `$pk_col` DESC";
} else {
    $sql_list = "SELECT * FROM surat_masuk
                 WHERE {$filter_hapus_kadis}LOWER(TRIM(COALESCE(status_surat, ''))) NOT IN ('diproses', 'selesai', 'diarsipkan', 'arsip')
                 ORDER BY `$pk_col` DESC";
}

$res_list = mysqli_query($koneksi, $sql_list);
$daftar_surat = [];
if ($res_list) {
    while ($row = mysqli_fetch_assoc($res_list)) {
        $daftar_surat[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $menu_aktif === 'profil' ? 'Pengaturan Profil - Kepala Dinas' : 'Disposisi Masuk - Kepala Dinas'; ?></title>
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
            padding: 20px 24px;
            box-shadow: 0 8px 20px rgba(47, 87, 128, 0.16);
        }
        .header-biru-kadis-text {
            flex: 1 1 auto;
            min-width: 0;
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
            white-space: nowrap;
            flex-shrink: 0;
        }
        .card-tabel-putih {
            background-color: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            box-shadow: 0 4px 14px rgba(15, 23, 42, 0.04);
            overflow: hidden;
        }
        .kartu-profil-putih {
            background-color: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            box-shadow: 0 4px 14px rgba(15, 23, 42, 0.04);
            color: #1e293b;
        }
        .avatar-profil-disp-kd {
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
        .sandi-wrapper-disp-kd { position: relative; }
        .sandi-wrapper-disp-kd .form-control { padding-right: 42px; }
        .btn-toggle-sandi-disp-kd {
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
        .btn-toggle-sandi-disp-kd:hover {
            color: #0d6efd;
            background-color: rgba(13, 110, 253, 0.08);
        }
        .table-custom {
            margin-bottom: 0;
            border-color: #e2e8f0;
        }
        .table-custom thead th {
            background-color: #3b6998 !important;
            color: #ffffff !important;
            border-color: #2f5780 !important;
            font-size: 0.8rem;
            font-weight: 600;
            padding: 12px 12px;
            vertical-align: middle;
            white-space: nowrap;
        }
        .table-custom tbody td {
            font-size: 0.84rem;
            padding: 12px 12px;
            vertical-align: middle;
            color: #1e293b;
        }
        .table-custom tbody tr:hover {
            background-color: #f8fafc;
        }
        .badge-agenda-kadis {
            font-size: 0.75rem;
            font-weight: 600;
            letter-spacing: 0.2px;
            white-space: nowrap;
        }
        .status-pill-kadis {
            background-color: #64748b;
            color: #ffffff;
            font-size: 0.73rem;
            font-weight: 600;
            padding: 5px 10px;
            border-radius: 7px;
            display: inline-block;
            white-space: nowrap;
        }
        .aksi-group {
            display: grid;
            grid-template-columns: repeat(2, max-content);
            justify-content: center;
            align-items: center;
            gap: 6px;
        }
        .aksi-group form {
            margin: 0;
            display: contents;
        }
        .aksi-group .btn,
        .aksi-group button {
            font-size: 0.74rem;
            font-weight: 600;
            padding: 5px 9px;
            border-radius: 7px;
            white-space: nowrap;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
            line-height: 1.2;
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
                    <a href="dashboard.php" class="nav-link">
                        <i class="bi bi-house-door-fill me-2"></i> Dashboard
                    </a>
                </li>
                <li class="nav-item">
                    <a href="disposisi_masuk.php" class="nav-link <?= $menu_aktif === 'disposisi_masuk' ? 'active' : ''; ?>">
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
                    <a href="disposisi_masuk.php?menu=profil" class="nav-link <?= $menu_aktif === 'profil' ? 'active' : ''; ?>">
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
                <div class="header-biru-kadis mb-4 d-flex justify-content-between align-items-start gap-3">
                    <div class="header-biru-kadis-text">
                        <h4 class="fw-bold mb-1 text-white">Pengaturan Profil Kepala Dinas</h4>
                        <p class="mb-0 small" style="color: #dbeafe;">Perbarui identitas pejabat, email aktif, dan kata sandi akun Kepala Dinas.</p>
                    </div>
                    <span class="badge-putih-kadis"><i class="bi bi-person-badge me-1"></i> KEPALA DINAS</span>
                </div>

                <div class="row g-4">
                    <div class="col-lg-4">
                        <div class="kartu-profil-putih p-4 h-100">
                            <div class="d-flex align-items-center gap-3 mb-3">
                                <div class="avatar-profil-disp-kd">
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

                            <form method="POST" action="disposisi_masuk.php?menu=profil" autocomplete="off">
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
                                        <div class="sandi-wrapper-disp-kd">
                                            <input type="password" id="sandiBaruProfilDispKd" name="sandi_baru_profil" class="form-control" placeholder="Minimal 6 karakter..." minlength="6" autocomplete="new-password">
                                            <button type="button" class="btn-toggle-sandi-disp-kd toggle-sandi-disp-kd" data-target="sandiBaruProfilDispKd" aria-label="Tampilkan sandi" title="Tampilkan sandi">
                                                <i class="bi bi-eye"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-semibold text-dark">Konfirmasi Kata Sandi Baru</label>
                                        <div class="sandi-wrapper-disp-kd">
                                            <input type="password" id="sandiKonfirmasiProfilDispKd" name="sandi_konfirmasi_profil" class="form-control" placeholder="Ulangi kata sandi baru..." minlength="6" autocomplete="new-password">
                                            <button type="button" class="btn-toggle-sandi-disp-kd toggle-sandi-disp-kd" data-target="sandiKonfirmasiProfilDispKd" aria-label="Tampilkan sandi" title="Tampilkan sandi">
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
                                    <a href="disposisi_masuk.php" class="btn btn-outline-secondary">Kembali ke Disposisi Masuk</a>
                                    <button type="submit" name="simpan_profil_kadis" value="1" class="btn btn-primary fw-semibold px-4">
                                        <i class="bi bi-check2-circle me-1"></i> Verifikasi &amp; Simpan Profil
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="header-biru-kadis mb-4 d-flex justify-content-between align-items-start gap-3">
                    <div class="header-biru-kadis-text">
                        <h4 class="fw-bold mb-1 text-white">Disposisi Masuk</h4>
                        <p class="mb-0 small" style="color: #dbeafe;">Halo, <?= htmlspecialchars($nama_login, ENT_QUOTES, 'UTF-8'); ?> — lihat isi surat (termasuk surat rahasia), disposisikan ke unit/bidang, atau selesaikan tugas surat beserta bukti dukung jika ada.</p>
                    </div>
                    <span class="badge-putih-kadis"><i class="bi bi-person-badge me-1"></i> KEPALA DINAS</span>
                </div>

                <div class="card card-tabel-putih">
                    <div class="card-body p-3 p-md-4">
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover table-custom align-middle">
                                <thead>
                                    <tr>
                                        <th class="text-center" style="width: 52px;">No</th>
                                        <th class="text-center" style="width: 112px;">No. Agenda</th>
                                        <th style="width: 165px;">Nomor / Tanggal</th>
                                        <th style="width: 140px;">Asal Surat</th>
                                        <th>Perihal</th>
                                        <th class="text-center" style="width: 165px;">Status</th>
                                        <th class="text-center" style="width: 215px;">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($daftar_surat)): ?>
                                        <tr>
                                            <td colspan="7" class="text-center text-muted py-4">
                                                Belum ada disposisi masuk yang menunggu tindakan Kepala Dinas.
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($daftar_surat as $idx => $s): ?>
                                            <?php
                                            $id_row = (int)($s[$pk_col] ?? 0);
                                            $no_agenda_raw = $s['no_agenda'] ?? ($s['nomor_agenda'] ?? '');
                                            $no_agenda_tampil = format_no_agenda_kadis($no_agenda_raw);
                                            $no_surat = $s['no_surat'] ?? ($s['nomor_surat'] ?? '-');
                                            $tgl_surat = $s['tgl_surat'] ?? ($s['tanggal_surat'] ?? ($s['created_at'] ?? '-'));
                                            $asal_surat = $s['asal_surat'] ?? ($s['asal_instansi'] ?? ($s['pengirim'] ?? ($s['instansi_pengirim'] ?? '-')));
                                            $perihal = $s['perihal'] ?? ($s['subjek'] ?? '-');
                                            $sifat_surat_row = trim((string)($s['sifat_surat'] ?? 'Biasa'));
                                            $is_rahasia_row = (strtolower($sifat_surat_row) === 'rahasia');

                                            $isi_surat_raw = $s['isi_surat'] ?? ($s['ringkasan'] ?? ($s['keterangan'] ?? ($s['uraian'] ?? '')));
                                            if (trim((string)$isi_surat_raw) === '') {
                                                $isi_surat_raw = 'Tidak ada ringkasan isi teks pada surat ini. Silakan periksa berkas/lampiran surat jika tersedia.';
                                            }

                                            $berkas_raw = $s['file_surat'] ?? ($s['lampiran'] ?? ($s['berkas_surat'] ?? ($s['dokumen_surat'] ?? ($s['file'] ?? ''))));
                                            $berkas_url = (!$is_rahasia_row || $boleh_lihat_rahasia) ? normalisasi_url_berkas($berkas_raw) : '';

                                            $badge_label = 'Menunggu Proses Kadis';
                                            ?>
                                            <tr>
                                                <td class="text-center text-muted fw-semibold"><?= $idx + 1; ?></td>
                                                <td class="text-center">
                                                    <?php if ($no_agenda_tampil !== '-'): ?>
                                                        <span class="badge bg-secondary rounded-pill px-2 py-1 badge-agenda-kadis">
                                                            <?= htmlspecialchars($no_agenda_tampil, ENT_QUOTES, 'UTF-8'); ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="fw-semibold text-dark"><?= htmlspecialchars((string)$no_surat, ENT_QUOTES, 'UTF-8'); ?></div>
                                                    <div class="text-muted small"><?= htmlspecialchars((string)$tgl_surat, ENT_QUOTES, 'UTF-8'); ?></div>
                                                    <?php if ($is_rahasia_row): ?>
                                                        <span class="badge bg-dark mt-1" style="font-size: 0.68rem;"><i class="bi bi-shield-lock-fill me-1"></i>Rahasia</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-secondary"><?= htmlspecialchars((string)$asal_surat, ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td><?= htmlspecialchars((string)$perihal, ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td class="text-center"><span class="status-pill-kadis"><?= htmlspecialchars($badge_label, ENT_QUOTES, 'UTF-8'); ?></span></td>
                                                <td class="text-center">
                                                    <div class="aksi-group">
                                                        <button
                                                            type="button"
                                                            class="btn btn-sm btn-info text-white btn-lihat-surat"
                                                            data-bs-toggle="modal"
                                                            data-bs-target="#modalLihatSurat"
                                                            data-agenda="<?= htmlspecialchars($no_agenda_tampil, ENT_QUOTES, 'UTF-8'); ?>"
                                                            data-nomor="<?= htmlspecialchars((string)$no_surat, ENT_QUOTES, 'UTF-8'); ?>"
                                                            data-tanggal="<?= htmlspecialchars((string)$tgl_surat, ENT_QUOTES, 'UTF-8'); ?>"
                                                            data-asal="<?= htmlspecialchars((string)$asal_surat, ENT_QUOTES, 'UTF-8'); ?>"
                                                            data-perihal="<?= htmlspecialchars((string)$perihal, ENT_QUOTES, 'UTF-8'); ?>"
                                                            data-sifat="<?= htmlspecialchars($sifat_surat_row, ENT_QUOTES, 'UTF-8'); ?>"
                                                            data-isi="<?= htmlspecialchars((string)$isi_surat_raw, ENT_QUOTES, 'UTF-8'); ?>"
                                                            data-berkas="<?= htmlspecialchars($berkas_url, ENT_QUOTES, 'UTF-8'); ?>"
                                                        >
                                                            <i class="bi bi-eye"></i> Lihat
                                                        </button>
                                                        <button
                                                            type="button"
                                                            class="btn btn-sm btn-primary btn-proses-disposisi"
                                                            data-bs-toggle="modal"
                                                            data-bs-target="#modalDisposisi"
                                                            data-id="<?= $id_row; ?>"
                                                            data-agenda="<?= htmlspecialchars($no_agenda_tampil, ENT_QUOTES, 'UTF-8'); ?>"
                                                            data-nomor="<?= htmlspecialchars((string)$no_surat, ENT_QUOTES, 'UTF-8'); ?>"
                                                            data-perihal="<?= htmlspecialchars((string)$perihal, ENT_QUOTES, 'UTF-8'); ?>"
                                                            data-sifat="<?= htmlspecialchars($sifat_surat_row, ENT_QUOTES, 'UTF-8'); ?>"
                                                        >
                                                            <i class="bi bi-send-check"></i> Disposisi
                                                        </button>
                                                        <button
                                                            type="button"
                                                            class="btn btn-sm btn-success btn-selesaikan-tugas"
                                                            data-bs-toggle="modal"
                                                            data-bs-target="#modalSelesaikan"
                                                            data-id="<?= $id_row; ?>"
                                                            data-agenda="<?= htmlspecialchars($no_agenda_tampil, ENT_QUOTES, 'UTF-8'); ?>"
                                                            data-nomor="<?= htmlspecialchars((string)$no_surat, ENT_QUOTES, 'UTF-8'); ?>"
                                                            data-perihal="<?= htmlspecialchars((string)$perihal, ENT_QUOTES, 'UTF-8'); ?>"
                                                        >
                                                            <i class="bi bi-check2-circle"></i> Selesai
                                                        </button>
                                                        <form method="POST" onsubmit="return confirm('Hapus surat ini dari tampilan Kepala Dinas saja?');">
                                                            <input type="hidden" name="id_surat" value="<?= $id_row; ?>">
                                                            <button type="submit" name="hapus_tampilan_kadis" value="1" class="btn btn-sm btn-outline-danger">
                                                                <i class="bi bi-trash"></i> Hapus
                                                            </button>
                                                        </form>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <!-- Modal Lihat Surat -->
    <div class="modal fade" id="modalLihatSurat" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold">Detail &amp; Isi Surat</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-2 mb-3 small">
                        <div class="col-md-4"><strong>No. Agenda:</strong> <span id="lihat_agenda">-</span></div>
                        <div class="col-md-4"><strong>Nomor Surat:</strong> <span id="lihat_nomor">-</span></div>
                        <div class="col-md-4"><strong>Tanggal:</strong> <span id="lihat_tanggal">-</span></div>
                        <div class="col-md-6"><strong>Asal Surat:</strong> <span id="lihat_asal">-</span></div>
                        <div class="col-md-6"><strong>Sifat Surat:</strong> <span id="lihat_sifat">-</span></div>
                        <div class="col-12"><strong>Perihal:</strong> <span id="lihat_perihal">-</span></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Ringkasan / Isi Surat</label>
                        <div id="lihat_isi" class="p-3 bg-light border rounded small" style="white-space: pre-line;">-</div>
                    </div>
                    <div id="lihat_berkas_wrap" class="d-none">
                        <a id="lihat_berkas_link" href="#" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-file-earmark-arrow-down me-1"></i> Buka / Unduh Berkas Surat
                        </a>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Tutup</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Disposisi -->
    <div class="modal fade" id="modalDisposisi" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <form method="POST" class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold">Disposisi Surat</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="id_surat" id="disp_id_surat">
                    <div class="mb-2 small text-muted">
                        Agenda: <strong id="disp_agenda">-</strong> &bull; No. Surat: <strong id="disp_nomor">-</strong>
                    </div>
                    <div class="mb-3 small">
                        <strong>Perihal:</strong> <span id="disp_perihal">-</span>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Tujuan Disposisi</label>
                        <select name="tujuan_bidang" class="form-select form-select-sm" required>
                            <option value="">-- Pilih Tujuan Disposisi --</option>
                            <option value="Bidang 1 - Pengelolaan Informasi &amp; Saluran Komunikasi Publik">Bidang 1 - Pengelolaan Informasi &amp; Saluran Komunikasi Publik</option>
                            <option value="Bidang 2 - Penyelenggaraan e-Government">Bidang 2 - Penyelenggaraan e-Government</option>
                            <option value="Bidang 3 - Pengembangan Sumber Daya Komunikasi &amp; Informatika">Bidang 3 - Pengembangan Sumber Daya Komunikasi &amp; Informatika</option>
                            <option value="Bidang 4 - Statistik Sektoral &amp; Persandian">Bidang 4 - Statistik Sektoral &amp; Persandian</option>
                            <option value="Sekretaris">Sekretaris</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Sifat Surat (Opsional)</label>
                        <select name="sifat_surat" id="disp_sifat" class="form-select form-select-sm">
                            <option value="">-- Otomatis / Sesuaikan --</option>
                            <option value="Biasa">Biasa</option>
                            <option value="Penting">Penting</option>
                            <option value="Segera">Segera</option>
                            <option value="Rahasia">Rahasia</option>
                        </select>
                    </div>
                    <div class="mb-0">
                        <label class="form-label small fw-bold">Catatan / Arahan Disposisi</label>
                        <textarea name="catatan_disposisi" rows="3" class="form-control form-control-sm" placeholder="Tuliskan arahan tindak lanjut Kepala Dinas..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" name="kirim_disposisi" value="1" class="btn btn-primary btn-sm fw-semibold">
                        <i class="bi bi-send-check me-1"></i> Kirim Disposisi
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Selesaikan Tugas -->
    <div class="modal fade" id="modalSelesaikan" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <form method="POST" enctype="multipart/form-data" class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold">Selesaikan Tugas Surat</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="id_surat" id="selesai_id_surat">
                    <div class="mb-2 small text-muted">
                        Agenda: <strong id="selesai_agenda">-</strong> &bull; No. Surat: <strong id="selesai_nomor">-</strong>
                    </div>
                    <div class="mb-3 small">
                        <strong>Perihal:</strong> <span id="selesai_perihal">-</span>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Catatan Penyelesaian</label>
                        <textarea name="catatan_penyelesaian" rows="3" class="form-control form-control-sm" placeholder="Tuliskan catatan penyelesaian tugas..."></textarea>
                    </div>
                    <div class="mb-0">
                        <label class="form-label small fw-bold">Bukti Dukung (Opsional, maks. 10 MB)</label>
                        <input type="file" name="bukti_dukung" class="form-control form-control-sm" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" name="selesaikan_tugas" value="1" class="btn btn-success btn-sm fw-semibold">
                        <i class="bi bi-check2-circle me-1"></i> Tandai Selesai
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.querySelectorAll('.btn-lihat-surat').forEach(function (btn) {
            btn.addEventListener('click', function () {
                document.getElementById('lihat_agenda').textContent = this.dataset.agenda || '-';
                document.getElementById('lihat_nomor').textContent = this.dataset.nomor || '-';
                document.getElementById('lihat_tanggal').textContent = this.dataset.tanggal || '-';
                document.getElementById('lihat_asal').textContent = this.dataset.asal || '-';
                document.getElementById('lihat_sifat').textContent = this.dataset.sifat || '-';
                document.getElementById('lihat_perihal').textContent = this.dataset.perihal || '-';
                document.getElementById('lihat_isi').textContent = this.dataset.isi || '-';

                var berkas = this.dataset.berkas || '';
                var wrap = document.getElementById('lihat_berkas_wrap');
                var link = document.getElementById('lihat_berkas_link');
                if (berkas !== '') {
                    link.href = berkas;
                    wrap.classList.remove('d-none');
                } else {
                    link.href = '#';
                    wrap.classList.add('d-none');
                }
            });
        });

        document.querySelectorAll('.btn-proses-disposisi').forEach(function (btn) {
            btn.addEventListener('click', function () {
                document.getElementById('disp_id_surat').value = this.dataset.id || '';
                document.getElementById('disp_agenda').textContent = this.dataset.agenda || '-';
                document.getElementById('disp_nomor').textContent = this.dataset.nomor || '-';
                document.getElementById('disp_perihal').textContent = this.dataset.perihal || '-';
                var selSifat = document.getElementById('disp_sifat');
                if (selSifat) {
                    selSifat.value = '';
                }
            });
        });

        document.querySelectorAll('.btn-selesaikan-tugas').forEach(function (btn) {
            btn.addEventListener('click', function () {
                document.getElementById('selesai_id_surat').value = this.dataset.id || '';
                document.getElementById('selesai_agenda').textContent = this.dataset.agenda || '-';
                document.getElementById('selesai_nomor').textContent = this.dataset.nomor || '-';
                document.getElementById('selesai_perihal').textContent = this.dataset.perihal || '-';
            });
        });

        document.querySelectorAll('.toggle-sandi-disp-kd').forEach(function (btn) {
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