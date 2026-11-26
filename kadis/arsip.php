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

$role_session = strtolower(trim((string)($_SESSION['role'] ?? ($_SESSION['level'] ?? (is_array($_SESSION['user'] ?? null) ? ($_SESSION['user']['role'] ?? ($_SESSION['user']['level'] ?? '')) : '')))));
if (!in_array($role_session, ['kepala_dinas', 'kepala dinas', 'kadis'], true)) {
    echo "<script>alert('Akses ditolak! Halaman ini khusus untuk Kepala Dinas.'); window.location='../login.php';</script>";
    exit();
}

$menu_aktif = strtolower(trim((string)($_GET['menu'] ?? 'arsip')));
if (!in_array($menu_aktif, ['arsip', 'profil'], true)) {
    $menu_aktif = 'arsip';
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

function kolom_ada_arsip_kadis(mysqli $koneksi, string $tabel, string $kolom): bool {
    $t = mysqli_real_escape_string($koneksi, $tabel);
    $k = mysqli_real_escape_string($koneksi, $kolom);
    $res = mysqli_query($koneksi, "SHOW COLUMNS FROM `$t` LIKE '$k'");
    return ($res && mysqli_num_rows($res) > 0);
}

function tabel_ada_arsip_kadis(mysqli $koneksi, string $tabel): bool {
    $t = mysqli_real_escape_string($koneksi, $tabel);
    $res = mysqli_query($koneksi, "SHOW TABLES LIKE '$t'");
    return ($res && mysqli_num_rows($res) > 0);
}

function pastikan_kolom_hapus_arsip_kadis(mysqli $koneksi, string $tabel = 'surat_masuk'): string {
    if (kolom_ada_arsip_kadis($koneksi, $tabel, 'dihapus_kadis')) {
        return 'dihapus_kadis';
    }
    @mysqli_query($koneksi, "ALTER TABLE `$tabel` ADD COLUMN `dihapus_kadis` TINYINT(1) NOT NULL DEFAULT 0");
    return kolom_ada_arsip_kadis($koneksi, $tabel, 'dihapus_kadis') ? 'dihapus_kadis' : '';
}

function cari_kolom_arsip_kadis(mysqli $koneksi, string $tabel, array $kandidat): string {
    foreach ($kandidat as $kolom) {
        if (kolom_ada_arsip_kadis($koneksi, $tabel, $kolom)) {
            return $kolom;
        }
    }
    return '';
}

function deteksi_pk_arsip(mysqli $koneksi, string $tabel): string {
    $t = mysqli_real_escape_string($koneksi, $tabel);
    $res = mysqli_query($koneksi, "SHOW KEYS FROM `$t` WHERE Key_name = 'PRIMARY'");
    if ($res && ($row = mysqli_fetch_assoc($res)) && !empty($row['Column_name'])) {
        return (string)$row['Column_name'];
    }
    return 'id';
}

function format_no_agenda_arsip($raw_agenda): string {
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

function resolve_url_berkas_arsip_kadis(string $raw_file): string {
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

function deteksi_penyelesai_arsip(array $row, string $kol_penyelesai, string $kol_bukti): string {
    if ($kol_penyelesai !== '' && !empty($row[$kol_penyelesai])) {
        return trim((string)$row[$kol_penyelesai]);
    }
    foreach (['diselesaikan_oleh', 'selesai_oleh', 'penyelesai', 'verifikasi_oleh', 'petugas_selesai', 'diproses_oleh'] as $col) {
        if (!empty($row[$col])) {
            return trim((string)$row[$col]);
        }
    }

    $penerima = strtolower(trim((string)($row['penerima_sekarang'] ?? $row['disposisi_kepada'] ?? '')));
    if (($kol_bukti !== '' && !empty($row[$kol_bukti])) || !empty($row['bukti_dukung_kadis']) || !empty($row['bukti_kadis']) || str_contains($penerima, 'kepala dinas') || str_contains($penerima, 'kadis')) {
        return 'Kepala Dinas';
    }
    if (!empty($row['catatan_verifikasi']) || str_contains($penerima, 'sekretaris')) {
        return 'Sekretaris';
    }
    if ($penerima !== '' && $penerima !== 'selesai / telah dilaksanakan' && $penerima !== 'selesai (kepala dinas)') {
        return (string)($row['penerima_sekarang'] ?? $row['disposisi_kepada'] ?? 'Unit Terkait');
    }
    return 'Unit Terkait';
}

function format_tanggal_penyelesaian(array $row, string $kol_tgl_selesai): string {
    $raw_tgl = '';
    if ($kol_tgl_selesai !== '' && !empty($row[$kol_tgl_selesai])) {
        $raw_tgl = trim((string)$row[$kol_tgl_selesai]);
    } else {
        foreach ([
            'tgl_penyelesaian', 'tanggal_penyelesaian', 'tgl_selesai', 'tanggal_selesai',
            'waktu_selesai', 'selesai_pada', 'updated_at', 'tgl_update', 'tanggal_update'
        ] as $col) {
            if (!empty($row[$col])) {
                $raw_tgl = trim((string)$row[$col]);
                break;
            }
        }
    }

    if ($raw_tgl === '' || $raw_tgl === '0000-00-00' || $raw_tgl === '0000-00-00 00:00:00') {
        return '-';
    }

    $ts = strtotime($raw_tgl);
    if ($ts !== false) {
        $punya_jam = (bool)preg_match('/\d{2}:\d{2}/', $raw_tgl);
        return $punya_jam ? date('d-m-Y H:i', $ts) : date('d-m-Y', $ts);
    }
    return $raw_tgl;
}

function muat_konfigurasi_smtp_arsip_kd(): array {
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

function kirim_smtp_socket_arsip_kd(
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

function kirim_kode_verifikasi_profil_arsip_kd(string $email_tujuan, string $kode_otp, string $nama_user): array {
    $cfg = muat_konfigurasi_smtp_arsip_kd();
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
    $ok = kirim_smtp_socket_arsip_kd(
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
$pk_users       = tabel_ada_arsip_kadis($koneksi, 'users') ? deteksi_pk_arsip($koneksi, 'users') : 'id';
$kol_u_nama     = tabel_ada_arsip_kadis($koneksi, 'users') ? cari_kolom_arsip_kadis($koneksi, 'users', ['nama', 'nama_lengkap', 'full_name']) : '';
$kol_u_nip      = tabel_ada_arsip_kadis($koneksi, 'users') ? cari_kolom_arsip_kadis($koneksi, 'users', ['nip']) : '';
$kol_u_email    = tabel_ada_arsip_kadis($koneksi, 'users') ? cari_kolom_arsip_kadis($koneksi, 'users', ['email']) : '';
$kol_u_username = tabel_ada_arsip_kadis($koneksi, 'users') ? cari_kolom_arsip_kadis($koneksi, 'users', ['username', 'user']) : '';
$kol_u_password = tabel_ada_arsip_kadis($koneksi, 'users') ? cari_kolom_arsip_kadis($koneksi, 'users', ['password', 'sandi', 'kata_sandi']) : '';
$kol_u_role     = tabel_ada_arsip_kadis($koneksi, 'users') ? cari_kolom_arsip_kadis($koneksi, 'users', ['role', 'level']) : '';

$data_akun_kd = [];
if (tabel_ada_arsip_kadis($koneksi, 'users')) {
    $pk_u_esc = mysqli_real_escape_string($koneksi, $pk_users);
    $id_sesi  = (int)($_SESSION['id_user'] ?? ($_SESSION['user_id'] ?? ($_SESSION['id'] ?? 0)));
    $usr_sesi = trim((string)($_SESSION['username'] ?? ''));

    if ($id_sesi > 0) {
        $res_u = mysqli_query($koneksi, "SELECT * FROM `users` WHERE `$pk_u_esc` = $id_sesi LIMIT 1");
        if ($res_u && ($r_u = mysqli_fetch_assoc($res_u))) {
            $data_akun_kd = $r_u;
        }
    }
    if (empty($data_akun_kd) && $kol_u_username !== '' && $usr_sesi !== '') {
        $u_esc = mysqli_real_escape_string($koneksi, $usr_sesi);
        $res_u = mysqli_query($koneksi, "SELECT * FROM `users` WHERE LOWER(TRIM(`$kol_u_username`)) = LOWER('$u_esc') LIMIT 1");
        if ($res_u && ($r_u = mysqli_fetch_assoc($res_u))) {
            $data_akun_kd = $r_u;
        }
    }
    if (empty($data_akun_kd) && $kol_u_role !== '') {
        $res_u = mysqli_query($koneksi, "SELECT * FROM `users` WHERE LOWER(TRIM(`$kol_u_role`)) IN ('kepala_dinas', 'kepala dinas', 'kadis') LIMIT 1");
        if ($res_u && ($r_u = mysqli_fetch_assoc($res_u))) {
            $data_akun_kd = $r_u;
        }
    }
}

$nama_login = trim((string)(
    ($kol_u_nama !== '' ? ($data_akun_kd[$kol_u_nama] ?? '') : '') ?:
    ($_SESSION['google_name'] ??
    ($_SESSION['nama'] ??
    ($_SESSION['name'] ??
    ($_SESSION['username'] ?? 'Kepala Dinas'))))
));
if ($nama_login === '') {
    $nama_login = 'Kepala Dinas';
}

$nip_login      = trim((string)($kol_u_nip !== '' ? ($data_akun_kd[$kol_u_nip] ?? '') : ($_SESSION['nip'] ?? '')));
$email_login    = trim((string)($kol_u_email !== '' ? ($data_akun_kd[$kol_u_email] ?? '') : ($_SESSION['email'] ?? '')));
$username_login = trim((string)($kol_u_username !== '' ? ($data_akun_kd[$kol_u_username] ?? '') : ($_SESSION['username'] ?? 'kadis')));

$draft_profil_kd = $_SESSION['draft_profil_kd'] ?? [];
$otp_aktif_kd    = $_SESSION['otp_profil_kd'] ?? null;

// Langkah 1: Kirim kode persetujuan ke email aktif yang diisikan
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
    } elseif ($kol_u_username !== '' && $username_baru === '') {
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

        $hasil_kirim = kirim_kode_verifikasi_profil_arsip_kd($email_baru, $kode_otp, $nama_baru);
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
    } elseif (!tabel_ada_arsip_kadis($koneksi, 'users') || empty($data_akun_kd)) {
        $pesan_error = 'Data akun pengguna pada tabel users tidak ditemukan.';
    } else {
        $nama_baru     = (string)($draft_profil_kd['nama'] ?? '');
        $nip_baru      = (string)($draft_profil_kd['nip'] ?? '');
        $email_baru    = (string)($draft_profil_kd['email'] ?? '');
        $username_baru = (string)($draft_profil_kd['username'] ?? '');
        $hash_baru     = (string)($draft_profil_kd['password_hash'] ?? '');

        $id_target_u = (int)($data_akun_kd[$pk_users] ?? ($_SESSION['id_user'] ?? ($_SESSION['user_id'] ?? ($_SESSION['id'] ?? 0))));
        $set_u = [];

        if ($kol_u_nama !== '') {
            $set_u[] = "`$kol_u_nama` = '" . mysqli_real_escape_string($koneksi, $nama_baru) . "'";
        }
        if ($kol_u_nip !== '') {
            $set_u[] = "`$kol_u_nip` = '" . mysqli_real_escape_string($koneksi, $nip_baru) . "'";
        }
        if ($kol_u_email !== '' && $email_baru !== '') {
            $set_u[] = "`$kol_u_email` = '" . mysqli_real_escape_string($koneksi, $email_baru) . "'";
        }
        if ($kol_u_username !== '' && $username_baru !== '') {
            $set_u[] = "`$kol_u_username` = '" . mysqli_real_escape_string($koneksi, $username_baru) . "'";
        }
        if ($kol_u_password !== '' && $hash_baru !== '') {
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
                    $nama_login     = trim((string)($data_akun_kd[$kol_u_nama] ?? $nama_baru));
                    $nip_login      = trim((string)($data_akun_kd[$kol_u_nip] ?? $nip_baru));
                    $email_login    = trim((string)($data_akun_kd[$kol_u_email] ?? $email_baru));
                    $username_login = trim((string)($data_akun_kd[$kol_u_username] ?? $username_baru));
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

$pk_col = deteksi_pk_arsip($koneksi, 'surat_masuk');
$kol_hapus_kadis = pastikan_kolom_hapus_arsip_kadis($koneksi, 'surat_masuk');
$kol_dokumen = cari_kolom_arsip_kadis($koneksi, 'surat_masuk', [
    'file_surat', 'lampiran', 'berkas_surat', 'dokumen_surat', 'file'
]);
$kol_bukti = cari_kolom_arsip_kadis($koneksi, 'surat_masuk', [
    'bukti_dukung_kadis', 'bukti_kadis', 'bukti_penyelesaian', 'bukti_dukung', 'file_bukti'
]);
$kol_penyelesai = cari_kolom_arsip_kadis($koneksi, 'surat_masuk', [
    'diselesaikan_oleh', 'selesai_oleh', 'penyelesai', 'verifikasi_oleh', 'petugas_selesai', 'diproses_oleh'
]);
$kol_tgl_selesai = cari_kolom_arsip_kadis($koneksi, 'surat_masuk', [
    'tgl_penyelesaian', 'tanggal_penyelesaian', 'tgl_selesai', 'tanggal_selesai',
    'waktu_selesai', 'selesai_pada', 'updated_at', 'tgl_update', 'tanggal_update'
]);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['hapus_tampilan_kadis'])) {
    $id_surat = (int)($_POST['id_surat'] ?? 0);
    if ($id_surat <= 0) {
        $pesan_error = 'Arsip surat tidak valid untuk dihapus dari tampilan Kepala Dinas.';
    } elseif ($kol_hapus_kadis === '') {
        $pesan_error = 'Kolom penanda hapus khusus Kepala Dinas tidak tersedia.';
    } else {
        $stmt_del = mysqli_prepare($koneksi, "UPDATE `surat_masuk` SET `$kol_hapus_kadis` = 1 WHERE `$pk_col` = ? LIMIT 1");
        if ($stmt_del) {
            mysqli_stmt_bind_param($stmt_del, 'i', $id_surat);
            if (mysqli_stmt_execute($stmt_del)) {
                $pesan_sukses = 'Arsip surat berhasil dihapus dari tampilan Kepala Dinas saja (tidak memengaruhi dashboard peran lain).';
            } else {
                $pesan_error = 'Gagal menghapus arsip dari tampilan Kepala Dinas.';
            }
            mysqli_stmt_close($stmt_del);
        } else {
            $pesan_error = 'Gagal menyiapkan perintah hapus: ' . mysqli_error($koneksi);
        }
    }
}

$where_arsip = ["LOWER(TRIM(COALESCE(status_surat, ''))) IN ('selesai', 'diarsipkan', 'arsip')"];
if ($kol_hapus_kadis !== '') {
    $where_arsip[] = "COALESCE(`$kol_hapus_kadis`, 0) = 0";
}

$sql_arsip = "SELECT * FROM surat_masuk
              WHERE " . implode(' AND ', $where_arsip) . "
              ORDER BY `$pk_col` DESC";
$res_arsip = mysqli_query($koneksi, $sql_arsip);
$daftar_arsip = [];
if ($res_arsip) {
    while ($row = mysqli_fetch_assoc($res_arsip)) {
        $daftar_arsip[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $menu_aktif === 'profil' ? 'Pengaturan Profil - Kepala Dinas' : 'Arsip Surat - Kepala Dinas'; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after {
            box-sizing: border-box;
        }
        html, body {
            background-color: #f4f7fb;
            color: #1e293b;
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            margin: 0;
            max-width: 100%;
            overflow-x: clip;
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
            padding: 22px 20px;
            min-width: 0;
            max-width: calc(100% - 260px);
            width: auto;
        }
        .header-biru-kadis {
            background-color: #3b6998;
            border: 1px solid #2f5780;
            color: #ffffff;
            border-radius: 14px;
            padding: 18px 22px;
            box-shadow: 0 8px 20px rgba(47, 87, 128, 0.16);
        }
        .header-biru-kadis-text {
            flex: 1 1 auto;
            min-width: 0;
        }
        .badge-putih-kadis {
            background-color: #ffffff;
            color: #1e293b;
            font-size: 0.76rem;
            font-weight: 700;
            letter-spacing: 0.4px;
            padding: 7px 14px;
            border-radius: 10px;
            text-transform: uppercase;
            box-shadow: 0 2px 6px rgba(15, 23, 42, 0.12);
            white-space: nowrap;
            flex-shrink: 0;
        }
        .card-tabel-putih,
        .kartu-profil-putih {
            background-color: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            box-shadow: 0 4px 14px rgba(15, 23, 42, 0.04);
            max-width: 100%;
        }
        .card-tabel-putih {
            overflow: hidden;
        }
        .kartu-profil-putih {
            color: #1e293b;
        }
        .avatar-profil-arsip-kd {
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
        .sandi-wrapper-arsip-kd { position: relative; }
        .sandi-wrapper-arsip-kd .form-control { padding-right: 42px; }
        .btn-toggle-sandi-arsip-kd {
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
        .btn-toggle-sandi-arsip-kd:hover {
            color: #0d6efd;
            background-color: rgba(13, 110, 253, 0.08);
        }
        .search-arsip-box {
            max-width: 400px;
            width: 100%;
        }
        .table-custom {
            width: 100%;
            table-layout: fixed;
            margin-bottom: 0;
            border-color: #e2e8f0;
        }
        .table-custom thead th {
            background-color: #3b6998 !important;
            color: #ffffff !important;
            border-color: #2f5780 !important;
            font-size: 0.7rem;
            font-weight: 600;
            padding: 8px 5px;
            vertical-align: middle;
            line-height: 1.25;
            white-space: normal;
            overflow-wrap: break-word;
            word-wrap: break-word;
        }
        .table-custom tbody td {
            font-size: 0.73rem;
            padding: 8px 5px;
            vertical-align: middle;
            line-height: 1.28;
            color: #1e293b;
            white-space: normal;
            overflow-wrap: break-word;
            word-wrap: break-word;
        }
        .table-custom tbody tr:hover {
            background-color: #f8fafc;
        }
        .badge-agenda-arsip {
            font-size: 0.67rem;
            font-weight: 600;
            padding: 3px 6px;
            display: inline-block;
            max-width: 100%;
            white-space: normal;
            line-height: 1.2;
        }
        .badge-penyelesai-arsip {
            font-size: 0.68rem;
            font-weight: 600;
            display: inline-block;
            max-width: 100%;
            white-space: normal;
            line-height: 1.25;
        }
        .tgl-selesai-arsip {
            font-size: 0.71rem;
            font-weight: 600;
            color: #334155;
            display: inline-block;
            max-width: 100%;
            line-height: 1.25;
        }
        .status-pill-arsip {
            font-size: 0.68rem;
            font-weight: 600;
            padding: 4px 6px;
            border-radius: 6px;
            display: inline-block;
            max-width: 100%;
            white-space: normal;
            line-height: 1.2;
        }
        .btn-berkas-sm,
        .btn-hapus-arsip {
            font-size: 0.67rem;
            font-weight: 600;
            padding: 3px 5px;
            border-radius: 6px;
            max-width: 100%;
            white-space: normal;
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
                max-width: 100%;
                width: 100%;
                padding: 16px;
            }
            .search-arsip-box {
                max-width: 100%;
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
                    <a href="arsip.php" class="nav-link <?= $menu_aktif === 'arsip' ? 'active' : ''; ?>">
                        <i class="bi bi-folder-fill me-2"></i> Arsip Surat
                    </a>
                </li>
                <li class="nav-item">
                    <a href="arsip.php?menu=profil" class="nav-link <?= $menu_aktif === 'profil' ? 'active' : ''; ?>">
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
                                <div class="avatar-profil-arsip-kd">
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

                            <form method="POST" action="arsip.php?menu=profil" autocomplete="off">
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
                                        <div class="sandi-wrapper-arsip-kd">
                                            <input type="password" id="sandiBaruProfilArsipKd" name="sandi_baru_profil" class="form-control" placeholder="Minimal 6 karakter..." minlength="6" autocomplete="new-password">
                                            <button type="button" class="btn-toggle-sandi-arsip-kd toggle-sandi-arsip-kd" data-target="sandiBaruProfilArsipKd" aria-label="Tampilkan sandi" title="Tampilkan sandi">
                                                <i class="bi bi-eye"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-semibold text-dark">Konfirmasi Kata Sandi Baru</label>
                                        <div class="sandi-wrapper-arsip-kd">
                                            <input type="password" id="sandiKonfirmasiProfilArsipKd" name="sandi_konfirmasi_profil" class="form-control" placeholder="Ulangi kata sandi baru..." minlength="6" autocomplete="new-password">
                                            <button type="button" class="btn-toggle-sandi-arsip-kd toggle-sandi-arsip-kd" data-target="sandiKonfirmasiProfilArsipKd" aria-label="Tampilkan sandi" title="Tampilkan sandi">
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
                                    <a href="arsip.php" class="btn btn-outline-secondary">Kembali ke Arsip Surat</a>
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
                        <h4 class="fw-bold mb-1 text-white">Arsip Surat</h4>
                        <p class="mb-0 small" style="color: #dbeafe;">Halo, <?= htmlspecialchars($nama_login, ENT_QUOTES, 'UTF-8'); ?> — daftar surat yang telah selesai atau diarsipkan beserta pihak yang menyelesaikan, tanggal penyelesaian, dokumen surat, dan bukti dukungnya.</p>
                    </div>
                    <span class="badge-putih-kadis"><i class="bi bi-person-badge me-1"></i> KEPALA DINAS</span>
                </div>

                <div class="card card-tabel-putih p-3">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                        <h5 class="fw-bold text-dark mb-0"><i class="bi bi-folder-check me-2" style="color: #3b6998;"></i>Daftar Arsip Surat</h5>
                        <div class="search-arsip-box">
                            <div class="input-group input-group-sm">
                                <span class="input-group-text bg-white text-muted"><i class="bi bi-search"></i></span>
                                <input type="text" id="cariArsipSurat" class="form-control" placeholder="Cari no. agenda, asal instansi, no. surat, perihal, penyelesai..." autocomplete="off">
                                <button type="button" id="resetCariArsip" class="btn btn-outline-secondary d-none" title="Hapus pencarian">
                                    <i class="bi bi-x-lg"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <div>
                        <table class="table table-bordered table-hover table-custom align-middle mb-0" id="tabelArsipSurat">
                            <colgroup>
                                <col style="width: 3.5%;">
                                <col style="width: 8.5%;">
                                <col style="width: 10.5%;">
                                <col style="width: 10.5%;">
                                <col style="width: 14%;">
                                <col style="width: 12.5%;">
                                <col style="width: 10%;">
                                <col style="width: 7.5%;">
                                <col style="width: 8%;">
                                <col style="width: 8%;">
                                <col style="width: 7%;">
                            </colgroup>
                            <thead class="text-center">
                                <tr>
                                    <th>No</th>
                                    <th>No. Agenda</th>
                                    <th>Asal Instansi</th>
                                    <th>Nomor Surat</th>
                                    <th>Perihal</th>
                                    <th>Diselesaikan Oleh</th>
                                    <th>Tanggal Penyelesaian</th>
                                    <th>Status</th>
                                    <th>Dokumen Surat</th>
                                    <th>Bukti Dukung</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($daftar_arsip)): ?>
                                    <tr id="barisKosongAwal">
                                        <td colspan="11" class="text-center text-muted py-4">
                                            Belum ada surat yang diarsipkan atau berstatus selesai.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($daftar_arsip as $idx => $a): ?>
                                        <?php
                                        $id_row = (int)($a[$pk_col] ?? 0);
                                        $no_agenda_tampil = format_no_agenda_arsip($a['no_agenda'] ?? ($a['nomor_agenda'] ?? ''));
                                        $asal_surat = trim((string)($a['asal_instansi'] ?? ($a['asal_surat'] ?? ($a['pengirim'] ?? ($a['instansi_pengirim'] ?? '-')))));
                                        $no_surat = trim((string)($a['no_surat'] ?? ($a['nomor_surat'] ?? '-')));
                                        $perihal = trim((string)($a['perihal'] ?? ($a['subjek'] ?? '-')));
                                        $penyelesai_tampil = deteksi_penyelesai_arsip($a, $kol_penyelesai, $kol_bukti);
                                        $tgl_selesai_tampil = format_tanggal_penyelesaian($a, $kol_tgl_selesai);
                                        $status_row = trim((string)($a['status_surat'] ?? 'Selesai'));

                                        $sifat_val = trim((string)($a['sifat_surat'] ?? 'Biasa'));
                                        $is_rahasia = (strtolower($sifat_val) === 'rahasia');

                                        $raw_dok = ($kol_dokumen !== '' && !empty($a[$kol_dokumen])) ? (string)$a[$kol_dokumen] : '';
                                        $raw_bukti = ($kol_bukti !== '' && !empty($a[$kol_bukti])) ? (string)$a[$kol_bukti] : '';

                                        $url_dok = ($raw_dok !== '') ? resolve_url_berkas_arsip_kadis($raw_dok) : '';
                                        $boleh_buka_dok = ($url_dok !== '') && (!$is_rahasia || $boleh_lihat_rahasia);
                                        $url_bukti = ($raw_bukti !== '') ? resolve_url_berkas_arsip_kadis($raw_bukti) : '';
                                        ?>
                                        <tr class="baris-arsip">
                                            <td class="text-center text-muted fw-semibold nomor-urut"><?= $idx + 1; ?></td>
                                            <td class="text-center">
                                                <?php if ($no_agenda_tampil !== '-'): ?>
                                                    <span class="badge bg-secondary rounded-pill badge-agenda-arsip">
                                                        <?= htmlspecialchars($no_agenda_tampil, ENT_QUOTES, 'UTF-8'); ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-secondary"><?= htmlspecialchars($asal_surat !== '' ? $asal_surat : '-', ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td class="fw-semibold text-dark">
                                                <?= htmlspecialchars($no_surat !== '' ? $no_surat : '-', ENT_QUOTES, 'UTF-8'); ?>
                                                <?php if ($is_rahasia): ?>
                                                    <span class="badge bg-dark mt-1 d-inline-block" style="font-size: 0.64rem;"><i class="bi bi-shield-lock-fill me-1"></i>Rahasia</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= htmlspecialchars($perihal !== '' ? $perihal : '-', ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td class="text-center">
                                                <span class="badge bg-info text-dark badge-penyelesai-arsip px-2 py-1">
                                                    <i class="bi bi-person-check-fill me-1"></i><?= htmlspecialchars($penyelesai_tampil, ENT_QUOTES, 'UTF-8'); ?>
                                                </span>
                                            </td>
                                            <td class="text-center">
                                                <span class="tgl-selesai-arsip"><?= htmlspecialchars($tgl_selesai_tampil, ENT_QUOTES, 'UTF-8'); ?></span>
                                            </td>
                                            <td class="text-center">
                                                <span class="badge bg-success status-pill-arsip">
                                                    <?= htmlspecialchars($status_row !== '' ? $status_row : 'Selesai', ENT_QUOTES, 'UTF-8'); ?>
                                                </span>
                                            </td>
                                            <td class="text-center">
                                                <?php if ($boleh_buka_dok): ?>
                                                    <a href="<?= htmlspecialchars($url_dok, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer" class="btn btn-success btn-berkas-sm" title="<?= $is_rahasia ? 'Lihat Dokumen Rahasia' : 'Lihat Dokumen Surat'; ?>">
                                                        <i class="bi bi-file-earmark-pdf-fill me-1"></i> Dokumen<?= $is_rahasia ? ' (Rahasia)' : ''; ?>
                                                    </a>
                                                <?php elseif ($is_rahasia): ?>
                                                    <span class="badge bg-dark" style="font-size: 0.66rem;"><i class="bi bi-lock-fill me-1"></i> Rahasia</span>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <?php if ($url_bukti !== ''): ?>
                                                    <a href="<?= htmlspecialchars($url_bukti, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer" class="btn btn-primary btn-berkas-sm">
                                                        <i class="bi bi-file-earmark-check-fill me-1"></i> Lihat Bukti
                                                    </a>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <form method="POST" class="d-inline" onsubmit="return confirm('Hapus arsip surat ini dari tampilan Kepala Dinas saja? Data tetap aman dan tidak memengaruhi dashboard peran lain.');">
                                                    <input type="hidden" name="id_surat" value="<?= $id_row; ?>">
                                                    <button type="submit" name="hapus_tampilan_kadis" value="1" class="btn btn-sm btn-outline-danger btn-hapus-arsip" title="Hapus dari tampilan Kepala Dinas saja">
                                                        <i class="bi bi-trash"></i> Hapus
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                <tr id="barisTidakDitemukan" class="d-none">
                                    <td colspan="11" class="text-center text-muted py-4">Surat yang dicari tidak ditemukan pada arsip.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>

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

            document.querySelectorAll('.toggle-sandi-arsip-kd').forEach(function (btn) {
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
        })();
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>