<?php
session_start();
include '../koneksi.php';

wajib_akses_halaman($koneksi, ['kabid'], 'kabid02', '../');

$menu_aktif = strtolower(trim((string)($_GET['menu'] ?? 'dashboard')));
if (!in_array($menu_aktif, ['dashboard', 'disposisi_masuk', 'progres', 'laporan', 'arsip', 'profil'], true)) {
    $menu_aktif = 'dashboard';
}

function kolom_ada_b2($koneksi, $tabel, $kolom) {
    $t = mysqli_real_escape_string($koneksi, $tabel);
    $k = mysqli_real_escape_string($koneksi, $kolom);
    $res = mysqli_query($koneksi, "SHOW COLUMNS FROM `$t` LIKE '$k'");
    return ($res && mysqli_num_rows($res) > 0);
}

function tabel_ada_b2($koneksi, $tabel) {
    $t = mysqli_real_escape_string($koneksi, $tabel);
    $res = mysqli_query($koneksi, "SHOW TABLES LIKE '$t'");
    return ($res && mysqli_num_rows($res) > 0);
}

function deteksi_pk_b2($koneksi, $tabel) {
    $t = mysqli_real_escape_string($koneksi, $tabel);
    $res = mysqli_query($koneksi, "SHOW KEYS FROM `$t` WHERE Key_name = 'PRIMARY'");
    if ($res && ($row = mysqli_fetch_assoc($res)) && !empty($row['Column_name'])) {
        return $row['Column_name'];
    }
    return 'id';
}

function deteksi_kolom_pertama_b2($koneksi, $tabel, array $kandidat) {
    foreach ($kandidat as $col) {
        if (kolom_ada_b2($koneksi, $tabel, $col)) {
            return $col;
        }
    }
    return null;
}

function format_no_agenda_b2($raw_agenda): string {
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

function deteksi_pemberi_disposisi_b2(array $row, ?string $kol_pemberi_disp, bool $ada_cat_kadis, bool $ada_cat_verif): string {
    if ($kol_pemberi_disp !== null && !empty($row[$kol_pemberi_disp])) {
        $val = trim((string)$row[$kol_pemberi_disp]);
        if ($val !== '') {
            return $val;
        }
    }
    foreach ([
        'didisposisikan_oleh', 'disposisi_oleh', 'pemberi_disposisi',
        'dari_disposisi', 'dari_role', 'pengirim_disposisi', 'teruskan_oleh', 'petugas_disposisi'
    ] as $col) {
        if (!empty($row[$col]) && trim((string)$row[$col]) !== '') {
            return trim((string)$row[$col]);
        }
    }
    if ($ada_cat_kadis && trim((string)($row['catatan_kepala_dinas'] ?? '')) !== '') {
        return 'Kepala Dinas';
    }
    if (!empty($row['diproses_kadis'])) {
        return 'Kepala Dinas';
    }
    if ($ada_cat_verif && trim((string)($row['catatan_verifikasi'] ?? '')) !== '') {
        return 'Sekretaris';
    }
    return 'Kepala Dinas';
}

function normalisasi_status_tiga_b2($row_or_status, $fallback = 'Masuk') {
    $row = is_array($row_or_status) ? $row_or_status : [];
    $status_raw = is_array($row_or_status) ? ($row_or_status['status_surat'] ?? '') : $row_or_status;
    $penerima = strtolower(trim((string)($row['penerima_sekarang'] ?? '')));
    $s = strtolower(trim((string)$status_raw));
    if ($s === '') {
        $s = strtolower(trim((string)$fallback));
    }

    if (in_array($s, ['selesai', 'diarsipkan', 'arsip', 'done', 'completed'], true) || str_starts_with($penerima, 'selesai')) {
        return 'Selesai';
    }
    if (in_array($s, ['di proses', 'sedang diproses', 'progres', 'progress', 'ditindaklanjuti', 'proses'], true) || str_starts_with($penerima, 'di proses')) {
        return 'Di Proses';
    }
    return 'Masuk';
}

function badge_status_b2($row_or_status, $fallback = 'Masuk') {
    $status = normalisasi_status_tiga_b2($row_or_status, $fallback);
    if ($status === 'Selesai') {
        return '<span class="badge bg-success status-badge-b2">Selesai</span>';
    }
    if ($status === 'Di Proses') {
        return '<span class="badge text-dark status-badge-b2" style="background-color: #f59e0b;">Di Proses</span>';
    }
    return '<span class="badge bg-primary status-badge-b2">Masuk</span>';
}

function catat_notifikasi_status_b2($koneksi, $id_surat, $status_baru, $catatan, $nama_bidang, $nama_login) {
    $kandidat_tabel = ['riwayat_surat', 'riwayat_disposisi', 'log_status_surat', 'log_surat'];
    foreach ($kandidat_tabel as $tbl) {
        if (!tabel_ada_b2($koneksi, $tbl)) {
            continue;
        }
        $kol_id     = deteksi_kolom_pertama_b2($koneksi, $tbl, ['id_surat', 'surat_id', 'id_disposisi']);
        $kol_status = deteksi_kolom_pertama_b2($koneksi, $tbl, ['status_surat', 'status', 'status_baru', 'tahapan']);
        $kol_cat    = deteksi_kolom_pertama_b2($koneksi, $tbl, ['catatan', 'keterangan', 'catatan_disposisi', 'pesan']);
        $kol_aktor  = deteksi_kolom_pertama_b2($koneksi, $tbl, ['petugas', 'diperbarui_oleh', 'aktor', 'user_update', 'bidang']);
        $kol_target = deteksi_kolom_pertama_b2($koneksi, $tbl, ['tujuan_notif', 'penerima_notif', 'diketahui_oleh']);
        $kol_waktu  = deteksi_kolom_pertama_b2($koneksi, $tbl, ['created_at', 'tanggal', 'tgl_update', 'waktu']);

        if ($kol_id === null || $kol_status === null) {
            continue;
        }

        $cols = ["`$kol_id`", "`$kol_status`"];
        $vals = [(int)$id_surat, "'" . mysqli_real_escape_string($koneksi, $status_baru) . "'"];

        $info_aktor = $nama_bidang . ($nama_login !== '' ? ' (' . $nama_login . ')' : '');
        $pesan_lengkap = ($catatan !== '' ? $catatan . ' — ' : '') . 'Status diperbarui menjadi "' . $status_baru . '" oleh ' . $info_aktor . ' (diketahui Kepala Dinas, Sekretaris, dan Pengirim Surat).';

        if ($kol_cat !== null) {
            $cols[] = "`$kol_cat`";
            $vals[] = "'" . mysqli_real_escape_string($koneksi, $pesan_lengkap) . "'";
        }
        if ($kol_aktor !== null) {
            $cols[] = "`$kol_aktor`";
            $vals[] = "'" . mysqli_real_escape_string($koneksi, $info_aktor) . "'";
        }
        if ($kol_target !== null) {
            $cols[] = "`$kol_target`";
            $vals[] = "'Kepala Dinas, Sekretaris, Pengirim Surat'";
        }
        if ($kol_waktu !== null) {
            $cols[] = "`$kol_waktu`";
            $vals[] = "NOW()";
        }

        $sql_ins = "INSERT INTO `$tbl` (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $vals) . ")";
        @mysqli_query($koneksi, $sql_ins);
    }
}

function unggah_bukti_b2($file_input_name, $sub_folder = 'bukti_kabid') {
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
    $tmp_name  = (string)($_FILES[$file_input_name]['tmp_name'] ?? '');
    $orig_name = (string)($_FILES[$file_input_name]['name'] ?? 'bukti');
    $size_byte = (int)($_FILES[$file_input_name]['size'] ?? 0);
    if ($size_byte <= 0 || $size_byte > 10 * 1024 * 1024) {
        return ['ok' => false, 'error' => 'Ukuran bukti dukung maksimal 10 MB.'];
    }
    $ext = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
    if (!in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'], true)) {
        return ['ok' => false, 'error' => 'Format bukti dukung tidak didukung.'];
    }
    $upload_dir = dirname(__DIR__) . '/uploads/' . trim($sub_folder, '/');
    if (!is_dir($upload_dir) && !mkdir($upload_dir, 0777, true)) {
        return ['ok' => false, 'error' => 'Folder penyimpanan bukti dukung gagal dibuat.'];
    }
    $safe_name = time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file($tmp_name, $upload_dir . '/' . $safe_name)) {
        return ['ok' => false, 'error' => 'Gagal memindahkan file bukti dukung.'];
    }
    return ['ok' => true, 'path' => 'uploads/' . trim($sub_folder, '/') . '/' . $safe_name];
}

function normalisasi_url_b2($raw_path) {
    $p = trim(str_replace('\\', '/', (string)$raw_path));
    if ($p === '') return '';
    if (preg_match('#^https?://#i', $p)) return $p;
    $p = ltrim($p, '/');
    if (strpos($p, 'uploads/') === 0 || strpos($p, 'file_surat/') === 0 || strpos($p, 'surat/') === 0) {
        return '../' . $p;
    }
    if (strpos($p, '../') === 0) return $p;
    return '../uploads/' . $p;
}

function cari_logo_sangihe_b2(): string {
    $base_dir = __DIR__;
    $prioritas_rel = [
        '../logo_sangihe.png',
        '../logo_sangihe.jpg',
        '../logo_sangihe.jpeg',
        '../sangihe.png',
        '../assets/logo_sangihe.png',
        '../assets/img/logo_sangihe.png',
        '../assets/images/logo_sangihe.png',
        '../img/logo_sangihe.png',
        '../images/logo_sangihe.png',
        '../logo/logo_sangihe.png',
        '../assets/logo.png',
        '../assets/img/logo.png',
        '../img/logo.png',
        'logo_sangihe.png',
        '../uploads/logo_sangihe.png',
    ];

    foreach ($prioritas_rel as $rel) {
        $fs = $base_dir . '/' . $rel;
        if (file_exists($fs) && is_file($fs)) {
            return $rel;
        }
    }

    return '';
}

function muat_konfigurasi_smtp_b2(): array {
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

function kirim_smtp_socket_b2(
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

function kirim_kode_verifikasi_profil_b2(string $email_tujuan, string $kode_otp, string $nama_user): array {
    $cfg = muat_konfigurasi_smtp_b2();
    $subjek = 'Kode Persetujuan Pembaruan Profil - Portal E-Office Diskominfo Sangihe';
    $isi_pesan = "Halo " . ($nama_user !== '' ? $nama_user : 'Pejabat Bidang 2') . ",\n\n"
        . "Berikut kode persetujuan untuk memperbarui data profil dan/atau keamanan akun Bidang 2:\n"
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
                $mail->addAddress($email_tujuan, $nama_user !== '' ? $nama_user : 'Pejabat Bidang 2');
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
    $ok = kirim_smtp_socket_b2(
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

$url_logo = cari_logo_sangihe_b2();

$nama_bidang = 'Penyelenggaraan e-Government';
$kode_bidang = 'kabid02';

$pesan_sukses = '';
$pesan_error  = '';

// Muat struktur & data akun dari tabel users
$pk_users       = tabel_ada_b2($koneksi, 'users') ? deteksi_pk_b2($koneksi, 'users') : 'id';
$kol_u_nama     = tabel_ada_b2($koneksi, 'users') ? deteksi_kolom_pertama_b2($koneksi, 'users', ['nama', 'nama_lengkap', 'full_name']) : null;
$kol_u_nip      = tabel_ada_b2($koneksi, 'users') ? deteksi_kolom_pertama_b2($koneksi, 'users', ['nip']) : null;
$kol_u_email    = tabel_ada_b2($koneksi, 'users') ? deteksi_kolom_pertama_b2($koneksi, 'users', ['email']) : null;
$kol_u_username = tabel_ada_b2($koneksi, 'users') ? deteksi_kolom_pertama_b2($koneksi, 'users', ['username', 'user']) : null;
$kol_u_password = tabel_ada_b2($koneksi, 'users') ? deteksi_kolom_pertama_b2($koneksi, 'users', ['password', 'sandi', 'kata_sandi']) : null;

$data_akun_b2 = [];
if (tabel_ada_b2($koneksi, 'users')) {
    $pk_u_esc = mysqli_real_escape_string($koneksi, $pk_users);
    $id_sesi  = (int)($_SESSION['user_id'] ?? 0);
    $usr_sesi = trim((string)($_SESSION['username'] ?? ''));

    if ($id_sesi > 0) {
        $res_u = mysqli_query($koneksi, "SELECT * FROM `users` WHERE `$pk_u_esc` = $id_sesi LIMIT 1");
        if ($res_u && ($r_u = mysqli_fetch_assoc($res_u))) {
            $data_akun_b2 = $r_u;
        }
    }
    if (empty($data_akun_b2) && $kol_u_username !== null && $usr_sesi !== '') {
        $u_esc = mysqli_real_escape_string($koneksi, $usr_sesi);
        $res_u = mysqli_query($koneksi, "SELECT * FROM `users` WHERE LOWER(TRIM(`$kol_u_username`)) = LOWER('$u_esc') LIMIT 1");
        if ($res_u && ($r_u = mysqli_fetch_assoc($res_u))) {
            $data_akun_b2 = $r_u;
        }
    }
    if (empty($data_akun_b2) && $kol_u_username !== null) {
        $res_u = mysqli_query($koneksi, "SELECT * FROM `users` WHERE LOWER(TRIM(`$kol_u_username`)) = 'kabid02' LIMIT 1");
        if ($res_u && ($r_u = mysqli_fetch_assoc($res_u))) {
            $data_akun_b2 = $r_u;
        }
    }
}

$profil_user = ambil_profil_pejabat($koneksi);
$nama_login  = trim((string)($data_akun_b2[$kol_u_nama ?? ''] ?? ($profil_user['nama'] ?? 'Kepala Bidang 2')));
if ($nama_login === '') {
    $nama_login = 'Kepala Bidang 2';
}
$nip_login      = trim((string)($data_akun_b2[$kol_u_nip ?? ''] ?? ($profil_user['nip'] ?? '-')));
$email_login    = trim((string)($data_akun_b2[$kol_u_email ?? ''] ?? ($_SESSION['email'] ?? '')));
$username_login = trim((string)($data_akun_b2[$kol_u_username ?? ''] ?? ($_SESSION['username'] ?? 'kabid02')));

$draft_profil_b2 = $_SESSION['draft_profil_b2'] ?? [];
$otp_aktif_b2    = $_SESSION['otp_profil_b2'] ?? null;

// Langkah 1: Kirim kode persetujuan ke email aktif yang diisikan
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['kirim_kode_profil_b2'])) {
    $menu_aktif    = 'profil';
    $nama_baru     = trim((string)($_POST['nama_profil'] ?? ''));
    $nip_baru      = trim((string)($_POST['nip_profil'] ?? ''));
    $email_baru    = trim((string)($_POST['email_profil'] ?? ''));
    $username_baru = trim((string)($_POST['username_profil'] ?? ''));
    $sandi_baru    = (string)($_POST['sandi_baru_profil'] ?? '');
    $sandi_konf    = (string)($_POST['sandi_konfirmasi_profil'] ?? '');

    if ($nama_baru === '') {
        $pesan_error = 'Nama pejabat / pengguna wajib diisi.';
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

        $_SESSION['draft_profil_b2'] = $draft_baru;
        $_SESSION['otp_profil_b2']   = $otp_baru;
        $draft_profil_b2 = $draft_baru;
        $otp_aktif_b2    = $otp_baru;

        $hasil_kirim = kirim_kode_verifikasi_profil_b2($email_baru, $kode_otp, $nama_baru);
        if ($hasil_kirim['terkirim']) {
            $pesan_sukses = 'Kode persetujuan telah dikirim ke email aktif ' . $email_baru . '. Silakan cek Inbox atau folder Spam, lalu masukkan kodenya.';
        } else {
            $detail_err = trim((string)($hasil_kirim['error'] ?? ''));
            $pesan_error  = 'Gagal mengirim OTP ke email ' . $email_baru . ($detail_err !== '' ? ' (' . $detail_err . ')' : '') . '.';
        }
    }
}

// Batalkan draft / kode persetujuan
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['batal_kode_profil_b2'])) {
    $menu_aktif = 'profil';
    unset($_SESSION['draft_profil_b2'], $_SESSION['otp_profil_b2']);
    $draft_profil_b2 = [];
    $otp_aktif_b2    = null;
    $pesan_sukses    = 'Permintaan verifikasi email dibatalkan.';
}

// Langkah 2: Verifikasi kode persetujuan email & simpan perubahan profil ke database
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['simpan_profil_bidang2'])) {
    $menu_aktif = 'profil';
    $kode_input = trim((string)($_POST['kode_persetujuan_email'] ?? ''));

    if (!is_array($otp_aktif_b2) || empty($draft_profil_b2)) {
        $pesan_error = 'Pembaruan profil membutuhkan persetujuan email aktif. Isi email aktif lalu klik "Kirim Kode ke Email" terlebih dahulu.';
    } elseif ($kode_input === '') {
        $pesan_error = 'Masukkan kode persetujuan yang dikirim ke email aktif Anda.';
    } elseif (time() > (int)($otp_aktif_b2['expires_at'] ?? 0)) {
        $pesan_error = 'Kode persetujuan sudah kedaluwarsa. Klik "Kirim Kode ke Email" untuk meminta kode baru.';
    } elseif (!hash_equals((string)($otp_aktif_b2['kode'] ?? ''), $kode_input)) {
        $pesan_error = 'Kode persetujuan email tidak sesuai. Periksa kembali kode di email aktif Anda.';
    } elseif (!tabel_ada_b2($koneksi, 'users') || empty($data_akun_b2)) {
        $pesan_error = 'Data akun pengguna pada tabel users tidak ditemukan.';
    } else {
        $nama_baru     = (string)($draft_profil_b2['nama'] ?? '');
        $nip_baru      = (string)($draft_profil_b2['nip'] ?? '');
        $email_baru    = (string)($draft_profil_b2['email'] ?? '');
        $username_baru = (string)($draft_profil_b2['username'] ?? '');
        $hash_baru     = (string)($draft_profil_b2['password_hash'] ?? '');

        $id_target_u = (int)($data_akun_b2[$pk_users] ?? ($_SESSION['user_id'] ?? 0));
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
                $_SESSION['nama'] = $nama_baru;
                $_SESSION['nip']  = $nip_baru;
                if ($username_baru !== '') {
                    $_SESSION['username'] = $username_baru;
                }
                if ($email_baru !== '') {
                    $_SESSION['email'] = $email_baru;
                }

                unset($_SESSION['draft_profil_b2'], $_SESSION['otp_profil_b2']);
                $draft_profil_b2 = [];
                $otp_aktif_b2    = null;

                $res_u_fresh = mysqli_query($koneksi, "SELECT * FROM `users` WHERE `$pk_u_esc` = $id_target_u LIMIT 1");
                if ($res_u_fresh && ($r_fresh = mysqli_fetch_assoc($res_u_fresh))) {
                    $data_akun_b2   = $r_fresh;
                    $nama_login     = trim((string)($data_akun_b2[$kol_u_nama ?? ''] ?? $nama_baru));
                    $nip_login      = trim((string)($data_akun_b2[$kol_u_nip ?? ''] ?? $nip_baru));
                    $email_login    = trim((string)($data_akun_b2[$kol_u_email ?? ''] ?? $email_baru));
                    $username_login = trim((string)($data_akun_b2[$kol_u_username ?? ''] ?? $username_baru));
                }

                $pesan_sukses = 'Persetujuan email berhasil diverifikasi. Profil dan keamanan akun Bidang 2 telah diperbarui.' . ($hash_baru !== '' ? ' Kata sandi baru juga aktif.' : '');
            } else {
                $pesan_error = 'Gagal menyimpan perubahan profil: ' . mysqli_error($koneksi);
            }
        }
    }
}

$val_nama_form     = (string)($draft_profil_b2['nama'] ?? $nama_login);
$val_nip_form      = (string)($draft_profil_b2['nip'] ?? ($nip_login !== '-' ? $nip_login : ''));
$val_username_form = (string)($draft_profil_b2['username'] ?? $username_login);
$val_email_form    = (string)($draft_profil_b2['email'] ?? $email_login);
$menunggu_otp_b2   = is_array($otp_aktif_b2) && !empty($draft_profil_b2);

$pk_col        = deteksi_pk_b2($koneksi, 'surat_masuk');
$ada_status    = kolom_ada_b2($koneksi, 'surat_masuk', 'status_surat');
$ada_penerima  = kolom_ada_b2($koneksi, 'surat_masuk', 'penerima_sekarang');
$ada_disposisi = kolom_ada_b2($koneksi, 'surat_masuk', 'disposisi_kepada');
$ada_bidang_tj = kolom_ada_b2($koneksi, 'surat_masuk', 'bidang_tujuan');
$ada_tujuan_dp = kolom_ada_b2($koneksi, 'surat_masuk', 'tujuan_disposisi');

$kol_pemberi_disp = deteksi_kolom_pertama_b2($koneksi, 'surat_masuk', [
    'didisposisikan_oleh', 'disposisi_oleh', 'pemberi_disposisi',
    'dari_disposisi', 'dari_role', 'pengirim_disposisi', 'teruskan_oleh', 'petugas_disposisi'
]);

$kol_catatan = kolom_ada_b2($koneksi, 'surat_masuk', 'disposisi_surat')
    ? 'disposisi_surat'
    : (kolom_ada_b2($koneksi, 'surat_masuk', 'catatan_disposisi') ? 'catatan_disposisi' : null);
$ada_catatan = ($kol_catatan !== null);

$ada_cat_kadis = kolom_ada_b2($koneksi, 'surat_masuk', 'catatan_kepala_dinas');
$ada_cat_verif = kolom_ada_b2($koneksi, 'surat_masuk', 'catatan_verifikasi');

$kolom_bukti   = deteksi_kolom_pertama_b2($koneksi, 'surat_masuk', [
    'bukti_dukung_kabid', 'bukti_dukung', 'bukti_penyelesaian', 'bukti_kadis', 'file_bukti'
]);
$kolom_riwayat = deteksi_kolom_pertama_b2($koneksi, 'surat_masuk', [
    'riwayat_status', 'log_status', 'keterangan_status', 'riwayat_disposisi'
]);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['kirim_status_di_proses'])) {
    $id_surat = (int)($_POST['id_surat'] ?? 0);
    $catatan_proses = trim((string)($_POST['catatan_proses'] ?? ''));

    if ($id_surat <= 0) {
        $pesan_error = 'Surat tidak valid.';
    } else {
        $set_parts = [];
        if ($ada_status) {
            $set_parts[] = "status_surat = 'Di Proses'";
        }
        if ($ada_penerima) {
            $lbl = mysqli_real_escape_string($koneksi, 'Di Proses (' . $nama_bidang . ')');
            $set_parts[] = "penerima_sekarang = '$lbl'";
        }
        if ($catatan_proses !== '' && $kol_catatan !== null) {
            $ct_esc = mysqli_real_escape_string($koneksi, $catatan_proses);
            $set_parts[] = "`$kol_catatan` = '$ct_esc'";
        }
        if ($kolom_riwayat !== null) {
            $jejak = '[' . date('d/m/Y H:i') . '] Status: Di Proses oleh ' . $nama_bidang . ($catatan_proses !== '' ? ' (' . $catatan_proses . ')' : '');
            $jejak_esc = mysqli_real_escape_string($koneksi, $jejak);
            $set_parts[] = "`$kol_riwayat` = CONCAT(COALESCE(`$kol_riwayat`, ''), IF(COALESCE(`$kol_riwayat`, '') = '', '', ' | '), '$jejak_esc')";
        }

        if (!empty($set_parts)) {
            $pk_esc = mysqli_real_escape_string($koneksi, $pk_col);
            $sql_upd = "UPDATE surat_masuk SET " . implode(', ', $set_parts) . " WHERE `$pk_esc` = $id_surat";
            if (mysqli_query($koneksi, $sql_upd)) {
                catat_notifikasi_status_b2($koneksi, $id_surat, 'Di Proses', $catatan_proses, $nama_bidang, $nama_login);
                $pesan_sukses = 'Status surat diubah menjadi "Di Proses" dan dikirim agar diketahui oleh Kepala Dinas, Sekretaris, serta Pengirim Surat.';
            } else {
                $pesan_error = 'Gagal mengirim status "Di Proses": ' . mysqli_error($koneksi);
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['selesaikan_tugas_bidang'])) {
    $id_surat = (int)($_POST['id_surat'] ?? 0);
    $catatan  = trim((string)($_POST['catatan_penyelesaian'] ?? ''));

    if ($id_surat <= 0) {
        $pesan_error = 'Surat tidak valid.';
    } else {
        $upload_res = unggah_bukti_b2('bukti_dukung', 'bukti_kabid');
        if (!$upload_res['ok']) {
            $pesan_error = $upload_res['error'];
        } else {
            $set_parts = [];
            if ($ada_status) {
                $set_parts[] = "status_surat = 'Selesai'";
            }
            if ($ada_penerima) {
                $lbl = mysqli_real_escape_string($koneksi, 'Selesai (' . $nama_bidang . ')');
                $set_parts[] = "penerima_sekarang = '$lbl'";
            }
            if ($catatan !== '' && $kol_catatan !== null) {
                $ct_esc = mysqli_real_escape_string($koneksi, $catatan);
                $set_parts[] = "`$kol_catatan` = '$ct_esc'";
            }
            if ($kolom_riwayat !== null) {
                $jejak = '[' . date('d/m/Y H:i') . '] Status: Selesai oleh ' . $nama_bidang . ($catatan !== '' ? ' (' . $catatan . ')' : '');
                $jejak_esc = mysqli_real_escape_string($koneksi, $jejak);
                $set_parts[] = "`$kol_riwayat` = CONCAT(COALESCE(`$kol_riwayat`, ''), IF(COALESCE(`$kol_riwayat`, '') = '', '', ' | '), '$jejak_esc')";
            }
            if (!empty($upload_res['path']) && $kolom_bukti !== null) {
                $path_esc = mysqli_real_escape_string($koneksi, $upload_res['path']);
                $set_parts[] = "`$kol_bukti` = '$path_esc'";
            }
            if (!empty($set_parts)) {
                $pk_esc = mysqli_real_escape_string($koneksi, $pk_col);
                $sql_upd = "UPDATE surat_masuk SET " . implode(', ', $set_parts) . " WHERE `$pk_esc` = $id_surat";
                if (mysqli_query($koneksi, $sql_upd)) {
                    catat_notifikasi_status_b2($koneksi, $id_surat, 'Selesai', $catatan, $nama_bidang, $nama_login);
                    $pesan_sukses = 'Status surat diubah menjadi "Selesai", masuk ke Arsip Surat, dan dikirim agar diketahui oleh Kepala Dinas, Sekretaris, serta Pengirim Surat.';
                } else {
                    $pesan_error = 'Gagal memperbarui status surat: ' . mysqli_error($koneksi);
                }
            }
        }
    }
}

$kata_kunci = [
    'kabid02', 'kabid_02', 'kabid 02',
    'kabid2', 'kabid_2', 'kabid 2',
    'kabidii', 'kabid_ii', 'kabid ii',
    'penyelenggaraan e-government', 'e-government', 'egovernment', 'e government',
    'bidang 2', 'bidang_2', 'bidang2',
    'bidang ii', 'bidang_ii', 'bidangii'
];
$kolom_tujuan_cek = [];
if ($ada_penerima)  $kolom_tujuan_cek[] = 'penerima_sekarang';
if ($ada_disposisi) $kolom_tujuan_cek[] = 'disposisi_kepada';
if ($ada_bidang_tj) $kolom_tujuan_cek[] = 'bidang_tujuan';
if ($ada_tujuan_dp) $kolom_tujuan_cek[] = 'tujuan_disposisi';

$kondisi = [];
foreach ($kata_kunci as $kw) {
    $kw_esc = mysqli_real_escape_string($koneksi, $kw);
    foreach ($kolom_tujuan_cek as $ktc) {
        $kondisi[] = "LOWER(TRIM(COALESCE(`$ktc`, ''))) LIKE '%$kw_esc%'";
    }
}
$where_sql = !empty($kondisi) ? '(' . implode(' OR ', $kondisi) . ')' : '1=1';

$res_all = mysqli_query($koneksi, "SELECT * FROM surat_masuk WHERE $where_sql ORDER BY `$pk_col` DESC");
$semua_surat  = [];
$list_masuk   = [];
$list_progres = [];
$list_arsip   = [];

if ($res_all) {
    while ($row = mysqli_fetch_assoc($res_all)) {
        $semua_surat[] = $row;
        $st_norm = normalisasi_status_tiga_b2($row, 'Masuk');
        if ($st_norm === 'Selesai') {
            $list_arsip[] = $row;
        } elseif ($st_norm === 'Di Proses') {
            $list_progres[] = $row;
        } else {
            $list_masuk[] = $row;
        }
    }
}

$judul_menu = [
    'dashboard'       => 'Beranda',
    'disposisi_masuk' => 'Disposisi Masuk',
    'progres'         => 'Progres',
    'laporan'         => 'Laporan',
    'arsip'           => 'Arsip Surat',
    'profil'          => 'Pengaturan Profil',
];

$sapaan_user = $nama_login;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($judul_menu[$menu_aktif], ENT_QUOTES, 'UTF-8'); ?> - Bidang 2</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <style>
        body { background-color: #eef2f7; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; color: #1e293b; }
        .sidebar { width: 260px; min-height: 100vh; background-color: #ffffff; color: #0f172a; border-right: 1px solid #e2e8f0; position: fixed; top: 0; left: 0; z-index: 100; }
        .brand-logo-box { display: flex; align-items: center; justify-content: center; width: 100%; padding: 8px 6px 14px; margin-bottom: 10px; }
        .brand-logo-box img { width: 100%; max-width: 224px; height: auto; max-height: 108px; object-fit: contain; display: block; background-color: #ffffff; }
        .brand-logo-icon { width: 50px; height: 50px; border-radius: 12px; background-color: rgba(13, 110, 253, 0.12); color: #0d6efd; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; }
        .sidebar .nav-link { color: #334155; padding: 11px 18px; border-radius: 8px; margin-bottom: 5px; font-size: 0.95rem; font-weight: 500; }
        .sidebar .nav-link:hover { background-color: #f1f5f9; color: #0f172a; }
        .sidebar .nav-link.active { background-color: #0d6efd; color: #ffffff; }
        .main-content { margin-left: 260px; padding: 26px 28px; background-color: #eef2f7; min-height: 100vh; color: #1e293b; }
        .kartu-header-bidang { background-color: #3b6998; border: 1px solid #2f5780; color: #ffffff; border-radius: 12px; padding: 20px 26px; box-shadow: 0 3px 10px rgba(30, 58, 95, 0.14); }
        .banner-sambutan { background: linear-gradient(135deg, #dbeafe 0%, #eff6ff 100%); border: 1px solid #bfdbfe; color: #0f172a; border-radius: 12px; padding: 22px 26px; box-shadow: 0 2px 10px rgba(15, 23, 42, 0.04); }
        .kartu-stat-link { text-decoration: none; color: inherit; display: block; height: 100%; cursor: pointer; }
        .kartu-stat { background-color: #ffffff; border: 1px solid #e2e8f0; border-radius: 14px; padding: 22px; box-shadow: 0 2px 10px rgba(15, 23, 42, 0.04); height: 100%; transition: transform 0.15s ease, box-shadow 0.15s ease; }
        .kartu-stat-link:hover .kartu-stat { transform: translateY(-2px); box-shadow: 0 6px 16px rgba(15, 23, 42, 0.08); }
        .kartu-stat .stat-label { font-size: 0.78rem; font-weight: 700; color: #475569; text-transform: uppercase; }
        .kartu-stat .stat-angka { font-size: 2rem; font-weight: 700; color: #0f172a; line-height: 1.2; margin: 10px 0 12px; }
        .kartu-stat .stat-link { font-size: 0.85rem; font-weight: 600; display: inline-flex; align-items: center; gap: 4px; }
        .ikon-kotak { width: 58px; height: 58px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; flex-shrink: 0; }
        .ikon-biru { background-color: #e8f0fe; color: #1a73e8; }
        .ikon-kuning { background-color: #fef7e0; color: #e37400; }
        .ikon-hijau { background-color: #e6f4ea; color: #1e8e3e; }
        .ikon-ungu { background-color: #f3e8fd; color: #7627bb; }
        .kartu-konten { background-color: #4372a1; border: 1px solid #355f8a; border-radius: 14px; box-shadow: 0 3px 10px rgba(30, 58, 95, 0.14); color: #ffffff; }
        .kartu-profil-putih { background-color: #ffffff; border: 1px solid #e2e8f0; border-radius: 14px; box-shadow: 0 2px 10px rgba(15, 23, 42, 0.05); color: #1e293b; }
        .kartu-konten .table { --bs-table-bg: #f8fafc; --bs-table-color: #0f172a; --bs-table-border-color: #cbd5e1; --bs-table-hover-bg: #ffffff; border-radius: 10px; overflow: hidden; }
        .kartu-konten .table thead.table-light th {
            background-color: #e2e8f0;
            color: #0f172a;
            border-color: #cbd5e1;
            font-size: 0.8rem;
            font-weight: 700;
            padding: 11px 10px;
            vertical-align: middle;
            white-space: nowrap;
        }
        .kartu-konten .table tbody td {
            font-size: 0.82rem;
            padding: 11px 10px;
            vertical-align: middle;
            line-height: 1.35;
        }
        .agenda-pill-b2 { font-size: 0.75rem; font-weight: 600; padding: 4px 10px; white-space: nowrap; }
        .pemberi-badge-b2 { font-size: 0.75rem; font-weight: 600; padding: 5px 9px; white-space: nowrap; display: inline-flex; align-items: center; gap: 4px; }
        .status-badge-b2 { font-size: 0.75rem; font-weight: 600; padding: 5px 10px; white-space: nowrap; }
        .aksi-bidang-group { display: inline-flex; align-items: center; justify-content: center; gap: 6px; flex-wrap: nowrap; }
        .btn-di-proses { background-color: #f59e0b; border-color: #d97706; color: #0f172a; font-weight: 600; font-size: 0.76rem; padding: 5px 9px; border-radius: 7px; white-space: nowrap; }
        .btn-di-proses:hover { background-color: #d97706; border-color: #b45309; color: #0f172a; }
        .aksi-bidang-group .btn-success, .btn-selesai-b2 { font-weight: 600; font-size: 0.76rem; padding: 5px 9px; border-radius: 7px; white-space: nowrap; }
        .btn-bukti-b2 { font-weight: 600; font-size: 0.75rem; padding: 4px 9px; border-radius: 7px; white-space: nowrap; }
        .arahan-teks-b2 { font-size: 0.76rem; color: #1d4ed8; margin-top: 4px; display: block; }
        .filter-tabel-box { max-width: 340px; width: 100%; }
        .avatar-profil-b2 { width: 64px; height: 64px; border-radius: 16px; background-color: #e8f0fe; color: #1a73e8; display: flex; align-items: center; justify-content: center; font-size: 1.8rem; flex-shrink: 0; }
        .sandi-wrapper-b2 { position: relative; }
        .sandi-wrapper-b2 .form-control { padding-right: 42px; }
        .btn-toggle-sandi-b2 {
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
        .btn-toggle-sandi-b2:hover {
            color: #0d6efd;
            background-color: rgba(13, 110, 253, 0.08);
        }
        @media print { .sidebar, .no-print { display: none !important; } .main-content { margin-left: 0 !important; padding: 0 !important; background-color: #ffffff !important; } }
        @media (max-width: 768px) { .sidebar { position: relative; width: 100%; min-height: auto; border-right: none; border-bottom: 1px solid #e2e8f0; } .main-content { margin-left: 0; padding: 16px; } .filter-tabel-box { max-width: 100%; } }
    </style>
</head>
<body>
    <div class="sidebar d-flex flex-column p-3 no-print">
        <div class="brand-logo-box">
            <?php if ($url_logo !== ''): ?>
                <img src="<?= htmlspecialchars($url_logo, ENT_QUOTES, 'UTF-8'); ?>" alt="Logo LSM Diskominfo Sangihe">
            <?php else: ?>
                <div class="brand-logo-icon"><i class="bi bi-envelope-fill"></i></div>
            <?php endif; ?>
        </div>
        <ul class="nav nav-pills flex-column mb-auto">
            <li class="nav-item"><a href="bidang2.php?menu=dashboard" class="nav-link <?= $menu_aktif === 'dashboard' ? 'active' : ''; ?>"><i class="bi bi-speedometer2 me-2"></i> Beranda</a></li>
            <li class="nav-item"><a href="bidang2.php?menu=disposisi_masuk" class="nav-link <?= $menu_aktif === 'disposisi_masuk' ? 'active' : ''; ?>"><i class="bi bi-inbox-fill me-2"></i> Disposisi Masuk</a></li>
            <li class="nav-item"><a href="bidang2.php?menu=progres" class="nav-link <?= $menu_aktif === 'progres' ? 'active' : ''; ?>"><i class="bi bi-arrow-repeat me-2"></i> Progres</a></li>
            <li class="nav-item"><a href="bidang2.php?menu=laporan" class="nav-link <?= $menu_aktif === 'laporan' ? 'active' : ''; ?>"><i class="bi bi-file-earmark-bar-graph-fill me-2"></i> Laporan</a></li>
            <li class="nav-item"><a href="bidang2.php?menu=arsip" class="nav-link <?= $menu_aktif === 'arsip' ? 'active' : ''; ?>"><i class="bi bi-folder-check me-2"></i> Arsip Surat</a></li>
            <li class="nav-item"><a href="bidang2.php?menu=profil" class="nav-link <?= $menu_aktif === 'profil' ? 'active' : ''; ?>"><i class="bi bi-gear-fill me-2"></i> Pengaturan Profil</a></li>
        </ul>
        <hr>
        <div><a href="../logout.php" class="nav-link text-danger"><i class="bi bi-box-arrow-left me-2"></i> Keluar Sistem</a></div>
    </div>

    <div class="main-content">
        <div class="container-fluid px-0">
            <?php if ($pesan_sukses !== ''): ?>
                <div class="alert alert-success alert-dismissible fade show no-print" role="alert">
                    <?= htmlspecialchars($pesan_sukses, ENT_QUOTES, 'UTF-8'); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            <?php if ($pesan_error !== ''): ?>
                <div class="alert alert-danger alert-dismissible fade show no-print" role="alert">
                    <?= htmlspecialchars($pesan_error, ENT_QUOTES, 'UTF-8'); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if ($menu_aktif === 'dashboard'): ?>
                <div class="kartu-header-bidang mb-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <div>
                        <h5 class="fw-bold mb-1 text-white">Dashboard Bidang 2</h5>
                        <div class="text-white-50 small"><?= htmlspecialchars($nama_bidang, ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <a href="bidang2.php?menu=profil" class="btn btn-outline-light btn-sm rounded-pill px-3 fw-semibold">
                            <i class="bi bi-person-gear me-1"></i> Profil
                        </a>
                        <span class="badge bg-light text-dark px-3 py-2 rounded-pill fw-semibold"><i class="bi bi-person-badge-fill me-1"></i> BIDANG 2</span>
                    </div>
                </div>

                <div class="banner-sambutan mb-4">
                    <h4 class="fw-bold mb-1">Hallo &#128075; <?= htmlspecialchars($sapaan_user, ENT_QUOTES, 'UTF-8'); ?></h4>
                    <div class="sub-teks">Gunakan kartu statistik di bawah atau menu sidebar untuk memantau disposisi, menindaklanjuti dokumen bidang, atau mengatur profil akun.</div>
                </div>

                <div class="row g-3 mb-4 no-print">
                    <div class="col-md-6 col-xl-3">
                        <a href="bidang2.php?menu=disposisi_masuk" class="kartu-stat-link">
                            <div class="kartu-stat d-flex justify-content-between align-items-center">
                                <div>
                                    <div class="stat-label">DISPOSISI MASUK</div>
                                    <div class="stat-angka"><?= count($list_masuk); ?></div>
                                    <span class="stat-link text-primary">Lihat disposisi masuk &rarr;</span>
                                </div>
                                <div class="ikon-kotak ikon-biru"><i class="bi bi-envelope-check-fill"></i></div>
                            </div>
                        </a>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <a href="bidang2.php?menu=progres" class="kartu-stat-link">
                            <div class="kartu-stat d-flex justify-content-between align-items-center">
                                <div>
                                    <div class="stat-label">PROGRES (DI PROSES)</div>
                                    <div class="stat-angka"><?= count($list_progres); ?></div>
                                    <span class="stat-link text-warning">Pantau progres &rarr;</span>
                                </div>
                                <div class="ikon-kotak ikon-kuning"><i class="bi bi-arrow-repeat"></i></div>
                            </div>
                        </a>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <a href="bidang2.php?menu=arsip" class="kartu-stat-link">
                            <div class="kartu-stat d-flex justify-content-between align-items-center">
                                <div>
                                    <div class="stat-label">ARSIP SURAT (SELESAI)</div>
                                    <div class="stat-angka"><?= count($list_arsip); ?></div>
                                    <span class="stat-link text-success">Buka arsip surat &rarr;</span>
                                </div>
                                <div class="ikon-kotak ikon-hijau"><i class="bi bi-folder-check"></i></div>
                            </div>
                        </a>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <a href="bidang2.php?menu=laporan" class="kartu-stat-link">
                            <div class="kartu-stat d-flex justify-content-between align-items-center">
                                <div>
                                    <div class="stat-label">TOTAL DISPOSISI BIDANG</div>
                                    <div class="stat-angka"><?= count($semua_surat); ?></div>
                                    <span class="stat-link" style="color: #7627bb;">Lihat rekap laporan &rarr;</span>
                                </div>
                                <div class="ikon-kotak ikon-ungu"><i class="bi bi-files"></i></div>
                            </div>
                        </a>
                    </div>
                </div>

            <?php elseif ($menu_aktif === 'disposisi_masuk'): ?>
                <div class="kartu-header-bidang mb-4 d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <div>
                        <h5 class="fw-bold mb-1 text-white">Disposisi Masuk &mdash; Bidang 2</h5>
                        <div class="text-white-50 small"><?= htmlspecialchars($nama_bidang, ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                    <span class="badge bg-light text-dark px-3 py-2 rounded-pill fw-semibold"><i class="bi bi-person-badge-fill me-1"></i> BIDANG 2</span>
                </div>

                <div class="kartu-konten p-4">
                    <h5 class="fw-bold mb-3 text-white">Daftar Disposisi Masuk</h5>
                    <div class="table-responsive">
                        <table class="table table-bordered align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th class="text-center" style="width: 48px;">No</th>
                                    <th class="text-center" style="width: 108px;">No. Agenda</th>
                                    <th style="width: 125px;">Nomor Surat</th>
                                    <th style="width: 165px;">Asal Surat</th>
                                    <th class="text-center" style="width: 150px;">Didisposisikan Oleh</th>
                                    <th>Perihal &amp; Arahan</th>
                                    <th class="text-center" style="width: 82px;">Dokumen</th>
                                    <th class="text-center" style="width: 86px;">Status</th>
                                    <th class="text-center" style="width: 156px;">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($list_masuk)): ?>
                                    <tr><td colspan="9" class="text-center text-muted py-4">Belum ada disposisi baru untuk Bidang 2.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($list_masuk as $i => $row): ?>
                                        <?php
                                        $id_r = (int)($row[$pk_col] ?? 0);
                                        $agenda_tampil = format_no_agenda_b2($row['no_agenda'] ?? ($row['nomor_agenda'] ?? ''));
                                        $no_surat = trim((string)($row['no_surat'] ?? ($row['nomor_surat'] ?? '-')));
                                        $asal_surat = trim((string)($row['asal_surat'] ?? ($row['asal_instansi'] ?? ($row['pengirim'] ?? '-'))));
                                        $pemberi_disp = deteksi_pemberi_disposisi_b2($row, $kol_pemberi_disp, $ada_cat_kadis, $ada_cat_verif);
                                        $perihal = trim((string)($row['perihal'] ?? ($row['subjek'] ?? '-')));
                                        $sifat_val = trim((string)($row['sifat_surat'] ?? 'Biasa'));
                                        $is_rahasia = (strtolower($sifat_val) === 'rahasia');
                                        $arahan = trim((string)(
                                            ($ada_cat_kadis ? ($row['catatan_kepala_dinas'] ?? '') : '') ?:
                                            (($ada_cat_verif ? ($row['catatan_verifikasi'] ?? '') : '') ?:
                                            ($kol_catatan !== null ? ($row[$kol_catatan] ?? '') : ''))
                                        ));
                                        $raw_dok = trim((string)($row['file_surat'] ?? ($row['lampiran'] ?? ($row['berkas_surat'] ?? ''))));
                                        $url_dok = (!$is_rahasia && $raw_dok !== '') ? normalisasi_url_b2($raw_dok) : '';
                                        ?>
                                        <tr>
                                            <td class="text-center text-muted fw-semibold"><?= $i + 1; ?></td>
                                            <td class="text-center"><span class="badge bg-secondary rounded-pill agenda-pill-b2"><?= htmlspecialchars($agenda_tampil, ENT_QUOTES, 'UTF-8'); ?></span></td>
                                            <td class="fw-semibold text-dark"><?= htmlspecialchars($no_surat !== '' ? $no_surat : '-', ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td><?= htmlspecialchars($asal_surat !== '' ? $asal_surat : '-', ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td class="text-center">
                                                <span class="badge bg-info text-dark pemberi-badge-b2"><i class="bi bi-person-check-fill"></i><?= htmlspecialchars($pemberi_disp, ENT_QUOTES, 'UTF-8'); ?></span>
                                            </td>
                                            <td>
                                                <div><?= htmlspecialchars($perihal !== '' ? $perihal : '-', ENT_QUOTES, 'UTF-8'); ?></div>
                                                <?php if ($arahan !== ''): ?>
                                                    <span class="arahan-teks-b2"><i class="bi bi-chat-left-text me-1"></i><?= htmlspecialchars($arahan, ENT_QUOTES, 'UTF-8'); ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <?php if ($url_dok !== ''): ?>
                                                    <a href="<?= htmlspecialchars($url_dok, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-outline-primary py-1 px-2" title="Buka Dokumen">
                                                        <i class="bi bi-file-earmark-text"></i>
                                                    </a>
                                                <?php elseif ($is_rahasia): ?>
                                                    <span class="badge bg-dark"><i class="bi bi-lock-fill"></i></span>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center"><?= badge_status_b2($row, 'Masuk'); ?></td>
                                            <td class="text-center">
                                                <div class="aksi-bidang-group">
                                                    <button type="button" class="btn btn-sm btn-di-proses btn-set-proses" data-bs-toggle="modal" data-bs-target="#modalDiProses" data-id="<?= $id_r; ?>" data-agenda="<?= htmlspecialchars($agenda_tampil, ENT_QUOTES, 'UTF-8'); ?>" data-nomor="<?= htmlspecialchars($no_surat, ENT_QUOTES, 'UTF-8'); ?>">
                                                        Di Proses
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-success btn-set-selesai" data-bs-toggle="modal" data-bs-target="#modalSelesai" data-id="<?= $id_r; ?>" data-agenda="<?= htmlspecialchars($agenda_tampil, ENT_QUOTES, 'UTF-8'); ?>" data-nomor="<?= htmlspecialchars($no_surat, ENT_QUOTES, 'UTF-8'); ?>">
                                                        Selesai
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            <?php elseif ($menu_aktif === 'progres'): ?>
                <div class="kartu-header-bidang mb-4 d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <div>
                        <h5 class="fw-bold mb-1 text-white">Progres (Di Proses) &mdash; Bidang 2</h5>
                        <div class="text-white-50 small"><?= htmlspecialchars($nama_bidang, ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                    <span class="badge bg-light text-dark px-3 py-2 rounded-pill fw-semibold"><i class="bi bi-person-badge-fill me-1"></i> BIDANG 2</span>
                </div>

                <div class="kartu-konten p-4">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                        <h5 class="fw-bold mb-0 text-white">Daftar Surat Sedang Di Proses</h5>
                        <div class="filter-tabel-box">
                            <div class="input-group input-group-sm">
                                <span class="input-group-text bg-white text-muted"><i class="bi bi-search"></i></span>
                                <input type="text" class="form-control cari-tabel-bidang" data-target="tabelProgresB2" placeholder="Cari agenda, nomor surat, asal, perihal...">
                            </div>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-bordered align-middle mb-0" id="tabelProgresB2">
                            <thead class="table-light">
                                <tr>
                                    <th class="text-center" style="width: 48px;">No</th>
                                    <th class="text-center" style="width: 108px;">No. Agenda</th>
                                    <th style="width: 125px;">Nomor Surat</th>
                                    <th style="width: 165px;">Asal Surat</th>
                                    <th class="text-center" style="width: 150px;">Didisposisikan Oleh</th>
                                    <th>Perihal &amp; Catatan</th>
                                    <th class="text-center" style="width: 92px;">Status</th>
                                    <th class="text-center" style="width: 112px;">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($list_progres)): ?>
                                    <tr><td colspan="8" class="text-center text-muted py-4">Belum ada surat berstatus Di Proses.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($list_progres as $i => $row): ?>
                                        <?php
                                        $id_r = (int)($row[$pk_col] ?? 0);
                                        $agenda_tampil = format_no_agenda_b2($row['no_agenda'] ?? ($row['nomor_agenda'] ?? ''));
                                        $no_surat = trim((string)($row['no_surat'] ?? ($row['nomor_surat'] ?? '-')));
                                        $asal_surat = trim((string)($row['asal_surat'] ?? ($row['asal_instansi'] ?? ($row['pengirim'] ?? '-'))));
                                        $pemberi_disp = deteksi_pemberi_disposisi_b2($row, $kol_pemberi_disp, $ada_cat_kadis, $ada_cat_verif);
                                        $perihal = trim((string)($row['perihal'] ?? ($row['subjek'] ?? '-')));
                                        $cat_tampil = trim((string)($kol_catatan !== null ? ($row[$kol_catatan] ?? '') : ''));
                                        ?>
                                        <tr class="baris-data-b2">
                                            <td class="text-center text-muted fw-semibold nomor-urut-b2"><?= $i + 1; ?></td>
                                            <td class="text-center"><span class="badge bg-secondary rounded-pill agenda-pill-b2"><?= htmlspecialchars($agenda_tampil, ENT_QUOTES, 'UTF-8'); ?></span></td>
                                            <td class="fw-semibold text-dark"><?= htmlspecialchars($no_surat !== '' ? $no_surat : '-', ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td><?= htmlspecialchars($asal_surat !== '' ? $asal_surat : '-', ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td class="text-center">
                                                <span class="badge bg-info text-dark pemberi-badge-b2"><i class="bi bi-person-check-fill"></i><?= htmlspecialchars($pemberi_disp, ENT_QUOTES, 'UTF-8'); ?></span>
                                            </td>
                                            <td>
                                                <div><?= htmlspecialchars($perihal !== '' ? $perihal : '-', ENT_QUOTES, 'UTF-8'); ?></div>
                                                <?php if ($cat_tampil !== ''): ?>
                                                    <span class="arahan-teks-b2 text-secondary"><i class="bi bi-chat-left-text me-1"></i><?= htmlspecialchars($cat_tampil, ENT_QUOTES, 'UTF-8'); ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center"><?= badge_status_b2($row, 'Di Proses'); ?></td>
                                            <td class="text-center">
                                                <button type="button" class="btn btn-sm btn-success btn-selesai-b2 btn-set-selesai" data-bs-toggle="modal" data-bs-target="#modalSelesai" data-id="<?= $id_r; ?>" data-agenda="<?= htmlspecialchars($agenda_tampil, ENT_QUOTES, 'UTF-8'); ?>" data-nomor="<?= htmlspecialchars($no_surat, ENT_QUOTES, 'UTF-8'); ?>">
                                                    <i class="bi bi-check2-circle me-1"></i> Selesai
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <tr class="baris-kosong-b2 d-none">
                                        <td colspan="8" class="text-center text-muted py-4">Data surat yang dicari tidak ditemukan.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            <?php elseif ($menu_aktif === 'laporan'): ?>
                <div class="kartu-header-bidang mb-4 d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <div>
                        <h5 class="fw-bold mb-1 text-white">Laporan Disposisi &mdash; Bidang 2</h5>
                        <div class="text-white-50 small"><?= htmlspecialchars($nama_bidang, ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                    <button type="button" onclick="window.print();" class="btn btn-light btn-sm fw-semibold no-print">
                        <i class="bi bi-printer me-1"></i> Cetak Laporan
                    </button>
                </div>

                <div class="kartu-konten p-4">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                        <h5 class="fw-bold mb-0 text-white">Rekap Seluruh Disposisi Bidang 2</h5>
                        <div class="filter-tabel-box no-print">
                            <div class="input-group input-group-sm">
                                <span class="input-group-text bg-white text-muted"><i class="bi bi-search"></i></span>
                                <input type="text" class="form-control cari-tabel-bidang" data-target="tabelLaporanB2" placeholder="Cari agenda, nomor surat, asal, status...">
                            </div>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-bordered align-middle mb-0" id="tabelLaporanB2">
                            <thead class="table-light">
                                <tr>
                                    <th class="text-center" style="width: 48px;">No</th>
                                    <th class="text-center" style="width: 108px;">No. Agenda</th>
                                    <th style="width: 130px;">Nomor Surat</th>
                                    <th style="width: 170px;">Asal Surat</th>
                                    <th class="text-center" style="width: 155px;">Didisposisikan Oleh</th>
                                    <th>Perihal</th>
                                    <th class="text-center" style="width: 96px;">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($semua_surat)): ?>
                                    <tr><td colspan="7" class="text-center text-muted py-4">Belum ada data disposisi untuk Bidang 2.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($semua_surat as $i => $row): ?>
                                        <?php
                                        $agenda_tampil = format_no_agenda_b2($row['no_agenda'] ?? ($row['nomor_agenda'] ?? ''));
                                        $no_surat = trim((string)($row['no_surat'] ?? ($row['nomor_surat'] ?? '-')));
                                        $asal_surat = trim((string)($row['asal_surat'] ?? ($row['asal_instansi'] ?? ($row['pengirim'] ?? '-'))));
                                        $pemberi_disp = deteksi_pemberi_disposisi_b2($row, $kol_pemberi_disp, $ada_cat_kadis, $ada_cat_verif);
                                        $perihal = trim((string)($row['perihal'] ?? ($row['subjek'] ?? '-')));
                                        ?>
                                        <tr class="baris-data-b2">
                                            <td class="text-center text-muted fw-semibold nomor-urut-b2"><?= $i + 1; ?></td>
                                            <td class="text-center"><span class="badge bg-secondary rounded-pill agenda-pill-b2"><?= htmlspecialchars($agenda_tampil, ENT_QUOTES, 'UTF-8'); ?></span></td>
                                            <td class="fw-semibold text-dark"><?= htmlspecialchars($no_surat !== '' ? $no_surat : '-', ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td><?= htmlspecialchars($asal_surat !== '' ? $asal_surat : '-', ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td class="text-center">
                                                <span class="badge bg-info text-dark pemberi-badge-b2"><i class="bi bi-person-check-fill"></i><?= htmlspecialchars($pemberi_disp, ENT_QUOTES, 'UTF-8'); ?></span>
                                            </td>
                                            <td><?= htmlspecialchars($perihal !== '' ? $perihal : '-', ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td class="text-center"><?= badge_status_b2($row, 'Masuk'); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <tr class="baris-kosong-b2 d-none">
                                        <td colspan="7" class="text-center text-muted py-4">Data laporan yang dicari tidak ditemukan.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            <?php elseif ($menu_aktif === 'arsip'): ?>
                <div class="kartu-header-bidang mb-4 d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <div>
                        <h5 class="fw-bold mb-1 text-white">Arsip Surat Selesai &mdash; Bidang 2</h5>
                        <div class="text-white-50 small"><?= htmlspecialchars($nama_bidang, ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                    <span class="badge bg-light text-dark px-3 py-2 rounded-pill fw-semibold"><i class="bi bi-person-badge-fill me-1"></i> BIDANG 2</span>
                </div>

                <div class="kartu-konten p-4">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                        <h5 class="fw-bold mb-0 text-white">Daftar Arsip Surat Bidang 2</h5>
                        <div class="filter-tabel-box">
                            <div class="input-group input-group-sm">
                                <span class="input-group-text bg-white text-muted"><i class="bi bi-search"></i></span>
                                <input type="text" class="form-control cari-tabel-bidang" data-target="tabelArsipB2" placeholder="Cari agenda, nomor surat, asal, perihal...">
                            </div>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-bordered align-middle mb-0" id="tabelArsipB2">
                            <thead class="table-light">
                                <tr>
                                    <th class="text-center" style="width: 48px;">No</th>
                                    <th class="text-center" style="width: 108px;">No. Agenda</th>
                                    <th style="width: 130px;">Nomor Surat</th>
                                    <th style="width: 165px;">Asal Surat</th>
                                    <th class="text-center" style="width: 150px;">Didisposisikan Oleh</th>
                                    <th>Perihal</th>
                                    <th class="text-center" style="width: 105px;">Bukti Dukung</th>
                                    <th class="text-center" style="width: 92px;">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($list_arsip)): ?>
                                    <tr><td colspan="8" class="text-center text-muted py-4">Belum ada surat selesai di arsip Bidang 2.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($list_arsip as $i => $row): ?>
                                        <?php
                                        $agenda_tampil = format_no_agenda_b2($row['no_agenda'] ?? ($row['nomor_agenda'] ?? ''));
                                        $no_surat = trim((string)($row['no_surat'] ?? ($row['nomor_surat'] ?? '-')));
                                        $asal_surat = trim((string)($row['asal_surat'] ?? ($row['asal_instansi'] ?? ($row['pengirim'] ?? '-'))));
                                        $pemberi_disp = deteksi_pemberi_disposisi_b2($row, $kol_pemberi_disp, $ada_cat_kadis, $ada_cat_verif);
                                        $perihal = trim((string)($row['perihal'] ?? ($row['subjek'] ?? '-')));
                                        $raw_bukti = ($kolom_bukti !== null && !empty($row[$kolom_bukti])) ? trim((string)$row[$kolom_bukti]) : '';
                                        $url_bukti = ($raw_bukti !== '') ? normalisasi_url_b2($raw_bukti) : '';
                                        ?>
                                        <tr class="baris-data-b2">
                                            <td class="text-center text-muted fw-semibold nomor-urut-b2"><?= $i + 1; ?></td>
                                            <td class="text-center"><span class="badge bg-secondary rounded-pill agenda-pill-b2"><?= htmlspecialchars($agenda_tampil, ENT_QUOTES, 'UTF-8'); ?></span></td>
                                            <td class="fw-semibold text-dark"><?= htmlspecialchars($no_surat !== '' ? $no_surat : '-', ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td><?= htmlspecialchars($asal_surat !== '' ? $asal_surat : '-', ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td class="text-center">
                                                <span class="badge bg-info text-dark pemberi-badge-b2"><i class="bi bi-person-check-fill"></i><?= htmlspecialchars($pemberi_disp, ENT_QUOTES, 'UTF-8'); ?></span>
                                            </td>
                                            <td><?= htmlspecialchars($perihal !== '' ? $perihal : '-', ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td class="text-center">
                                                <?php if ($url_bukti !== ''): ?>
                                                    <a href="<?= htmlspecialchars($url_bukti, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-primary btn-bukti-b2">
                                                        <i class="bi bi-file-earmark-check me-1"></i> Bukti
                                                    </a>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center"><?= badge_status_b2($row, 'Selesai'); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <tr class="baris-kosong-b2 d-none">
                                        <td colspan="8" class="text-center text-muted py-4">Data arsip yang dicari tidak ditemukan.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            <?php elseif ($menu_aktif === 'profil'): ?>
                <div class="kartu-header-bidang mb-4 d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <div>
                        <h5 class="fw-bold mb-1 text-white">Pengaturan Profil &mdash; Bidang 2</h5>
                        <div class="text-white-50 small"><?= htmlspecialchars($nama_bidang, ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                    <span class="badge bg-light text-dark px-3 py-2 rounded-pill fw-semibold"><i class="bi bi-person-badge-fill me-1"></i> BIDANG 2</span>
                </div>

                <div class="row g-4">
                    <div class="col-lg-4">
                        <div class="kartu-profil-putih p-4 h-100">
                            <div class="d-flex align-items-center gap-3 mb-3">
                                <div class="avatar-profil-b2">
                                    <i class="bi bi-person-badge"></i>
                                </div>
                                <div>
                                    <h6 class="fw-bold mb-1 text-dark"><?= htmlspecialchars($nama_login, ENT_QUOTES, 'UTF-8'); ?></h6>
                                    <span class="badge bg-primary">Kepala Bidang 2 (<?= htmlspecialchars($kode_bidang, ENT_QUOTES, 'UTF-8'); ?>)</span>
                                </div>
                            </div>
                            <hr>
                            <div class="small mb-2">
                                <div class="text-muted">Unit / Bidang</div>
                                <div class="fw-semibold text-dark"><?= htmlspecialchars($nama_bidang, ENT_QUOTES, 'UTF-8'); ?></div>
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

                            <form method="POST" action="bidang2.php?menu=profil" autocomplete="off">
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
                                        <div class="sandi-wrapper-b2">
                                            <input type="password" id="sandiBaruProfilB2" name="sandi_baru_profil" class="form-control" placeholder="Minimal 6 karakter..." minlength="6" autocomplete="new-password">
                                            <button type="button" class="btn-toggle-sandi-b2 toggle-sandi-b2" data-target="sandiBaruProfilB2" aria-label="Tampilkan sandi" title="Tampilkan sandi">
                                                <i class="bi bi-eye"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-semibold text-dark">Konfirmasi Kata Sandi Baru</label>
                                        <div class="sandi-wrapper-b2">
                                            <input type="password" id="sandiKonfirmasiProfilB2" name="sandi_konfirmasi_profil" class="form-control" placeholder="Ulangi kata sandi baru..." minlength="6" autocomplete="new-password">
                                            <button type="button" class="btn-toggle-sandi-b2 toggle-sandi-b2" data-target="sandiKonfirmasiProfilB2" aria-label="Tampilkan sandi" title="Tampilkan sandi">
                                                <i class="bi bi-eye"></i>
                                            </button>
                                        </div>
                                    </div>

                                    <div class="col-12"><hr class="my-1"><div class="small fw-bold text-primary">Persetujuan Melalui Email Aktif</div></div>

                                    <div class="col-md-6">
                                        <label class="form-label small fw-semibold text-dark">Kode Persetujuan (6 Digit dari Email)</label>
                                        <input type="text" name="kode_persetujuan_email" class="form-control" maxlength="6" pattern="[0-9]{6}" placeholder="Contoh: 123456" autocomplete="one-time-code">
                                        <div class="form-text">
                                            <?php if ($menunggu_otp_b2): ?>
                                                Kode dikirim ke <strong><?= htmlspecialchars((string)$otp_aktif_b2['email'], ENT_QUOTES, 'UTF-8'); ?></strong>.
                                            <?php else: ?>
                                                Klik <strong>Kirim Kode ke Email</strong> terlebih dahulu.
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="col-md-6 d-flex align-items-start pt-md-4 gap-2 flex-wrap">
                                        <button type="submit" name="kirim_kode_profil_b2" value="1" formnovalidate class="btn btn-outline-primary fw-semibold">
                                            <i class="bi bi-envelope-check me-1"></i> <?= $menunggu_otp_b2 ? 'Kirim Ulang Kode' : 'Kirim Kode ke Email'; ?>
                                        </button>
                                        <?php if ($menunggu_otp_b2): ?>
                                            <button type="submit" name="batal_kode_profil_b2" value="1" formnovalidate class="btn btn-outline-danger btn-sm mt-1">
                                                Batalkan
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="mt-4 d-flex justify-content-end gap-2">
                                    <a href="bidang2.php?menu=dashboard" class="btn btn-outline-secondary">Kembali ke Beranda</a>
                                    <button type="submit" name="simpan_profil_bidang2" value="1" class="btn btn-primary fw-semibold px-4">
                                        <i class="bi bi-check2-circle me-1"></i> Verifikasi &amp; Simpan Profil
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="modal fade" id="modalDiProses" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <form method="POST" class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold">Tandai Sedang Di Proses</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="id_surat" id="proses_id_surat">
                    <div class="mb-2 small text-muted">
                        Agenda: <strong id="proses_agenda">-</strong> &bull; No. Surat: <strong id="proses_nomor">-</strong>
                    </div>
                    <div class="mb-0">
                        <label class="form-label small fw-bold">Catatan Proses (Opsional)</label>
                        <textarea name="catatan_proses" rows="3" class="form-control form-control-sm" placeholder="Contoh: Sedang ditindaklanjuti penyelenggaraan e-Government..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" name="kirim_status_di_proses" value="1" class="btn btn-warning btn-sm fw-semibold">
                        Simpan Status Di Proses
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal fade" id="modalSelesai" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <form method="POST" enctype="multipart/form-data" class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold">Selesaikan Tugas Bidang 2</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="id_surat" id="selesai_id_surat">
                    <div class="mb-2 small text-muted">
                        Agenda: <strong id="selesai_agenda">-</strong> &bull; No. Surat: <strong id="selesai_nomor">-</strong>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Catatan Penyelesaian</label>
                        <textarea name="catatan_penyelesaian" rows="3" class="form-control form-control-sm" placeholder="Tuliskan ringkasan tindak lanjut yang telah dilaksanakan..."></textarea>
                    </div>
                    <div class="mb-0">
                        <label class="form-label small fw-bold">Bukti Dukung (Opsional, maks. 10 MB)</label>
                        <input type="file" name="bukti_dukung" class="form-control form-control-sm" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" name="selesaikan_tugas_bidang" value="1" class="btn btn-success btn-sm">
                        <i class="bi bi-check2-circle me-1"></i> Tandai Selesai
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.querySelectorAll('.btn-set-proses').forEach(function (btn) {
            btn.addEventListener('click', function () {
                document.getElementById('proses_id_surat').value = this.dataset.id || '';
                document.getElementById('proses_agenda').textContent = this.dataset.agenda || '-';
                document.getElementById('proses_nomor').textContent = this.dataset.nomor || '-';
            });
        });
        document.querySelectorAll('.btn-set-selesai').forEach(function (btn) {
            btn.addEventListener('click', function () {
                document.getElementById('selesai_id_surat').value = this.dataset.id || '';
                document.getElementById('selesai_agenda').textContent = this.dataset.agenda || '-';
                document.getElementById('selesai_nomor').textContent = this.dataset.nomor || '-';
            });
        });

        document.querySelectorAll('.toggle-sandi-b2').forEach(function (btn) {
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

        document.querySelectorAll('.cari-tabel-bidang').forEach(function (input) {
            var tableId = input.getAttribute('data-target');
            var table = document.getElementById(tableId);
            if (!table) return;

            var rows = Array.from(table.querySelectorAll('tbody tr.baris-data-b2'));
            var emptyRow = table.querySelector('tbody tr.baris-kosong-b2');

            input.addEventListener('input', function () {
                var q = input.value.toLowerCase().trim();
                var visibleCount = 0;

                rows.forEach(function (tr) {
                    var match = q === '' || tr.textContent.toLowerCase().indexOf(q) !== -1;
                    tr.classList.toggle('d-none', !match);
                    if (match) {
                        visibleCount++;
                        var numCell = tr.querySelector('.nomor-urut-b2');
                        if (numCell) numCell.textContent = visibleCount;
                    }
                });

                if (emptyRow) {
                    emptyRow.classList.toggle('d-none', visibleCount > 0);
                }
            });
        });
    </script>
</body>
</html>