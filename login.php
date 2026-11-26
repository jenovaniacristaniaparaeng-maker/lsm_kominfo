<?php
session_start();

$skip_auto_redirect = isset($_GET['noredirect']) || isset($_GET['error']) || !empty($_SESSION['login_error']);

if (!$skip_auto_redirect && (!empty($_SESSION['role']) || !empty($_SESSION['level']))) {
    $role_aktif     = strtolower(str_replace([' ', '-'], '_', trim((string)($_SESSION['role'] ?? $_SESSION['level'] ?? ''))));
    $username_aktif = strtolower(trim((string)($_SESSION['username'] ?? '')));

    if (in_array($role_aktif, ['admin', 'administrator', 'superadmin'], true) || $username_aktif === 'admin') {
        header("Location: admin/dashboard.php");
        exit();
    } elseif (in_array($role_aktif, ['kepala_dinas', 'kepala dinas', 'kadis'], true)) {
        header("Location: kadis/dashboard.php");
        exit();
    } elseif ($role_aktif === 'sekretariat') {
        header("Location: sekretariat/dashboard.php");
        exit();
    } elseif ($role_aktif === 'sekretaris') {
        header("Location: sekretaris/dashboard.php");
        exit();
    } elseif ($role_aktif === 'kabid') {
        $map_bidang = [
            'kabid01' => 'kabid/bidang1.php?menu=dashboard',
            'bidang1' => 'kabid/bidang1.php?menu=dashboard',
            'kabid02' => 'kabid/bidang2.php?menu=dashboard',
            'bidang2' => 'kabid/bidang2.php?menu=dashboard',
            'kabid03' => 'kabid/bidang3.php?menu=dashboard',
            'bidang3' => 'kabid/bidang3.php?menu=dashboard',
            'kabid04' => 'kabid/bidang4.php?menu=dashboard',
            'bidang4' => 'kabid/bidang4.php?menu=dashboard',
        ];
        $tujuan_kabid = $map_bidang[$username_aktif] ?? 'kabid/bidang1.php?menu=dashboard';
        header("Location: " . $tujuan_kabid);
        exit();
    } elseif (in_array($role_aktif, ['pengirim', 'pengirim_surat', 'pengirim surat', 'user', 'publik'], true)) {
        header("Location: pengirim/dashboard.php");
        exit();
    }
}

$path_koneksi = file_exists(__DIR__ . '/koneksi.php')
    ? __DIR__ . '/koneksi.php'
    : (file_exists(__DIR__ . '/../koneksi.php') ? __DIR__ . '/../koneksi.php' : '');
if ($path_koneksi !== '') {
    include_once $path_koneksi;
}

foreach ([
    __DIR__ . '/vendor/autoload.php',
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/PHPMailer/src/PHPMailer.php',
    __DIR__ . '/../PHPMailer/src/PHPMailer.php',
] as $autoload_file) {
    if (file_exists($autoload_file)) {
        if (str_ends_with($autoload_file, 'autoload.php')) {
            include_once $autoload_file;
        } else {
            $base_phpml = dirname($autoload_file);
            include_once $base_phpml . '/Exception.php';
            include_once $base_phpml . '/PHPMailer.php';
            include_once $base_phpml . '/SMTP.php';
        }
        break;
    }
}

function kolom_ada_ls_portal(mysqli $koneksi, string $tabel, string $kolom): bool {
    $t = mysqli_real_escape_string($koneksi, $tabel);
    $k = mysqli_real_escape_string($koneksi, $kolom);
    $res = mysqli_query($koneksi, "SHOW COLUMNS FROM `$t` LIKE '$k'");
    return ($res && mysqli_num_rows($res) > 0);
}

function deteksi_pk_ls_portal(mysqli $koneksi, string $tabel): string {
    $t = mysqli_real_escape_string($koneksi, $tabel);
    $res = mysqli_query($koneksi, "SHOW KEYS FROM `$t` WHERE Key_name = 'PRIMARY'");
    if ($res && ($row = mysqli_fetch_assoc($res)) && !empty($row['Column_name'])) {
        return (string)$row['Column_name'];
    }
    foreach (['id', 'id_user', 'id_pengirim', 'user_id'] as $kandidat_pk) {
        if (kolom_ada_ls_portal($koneksi, $tabel, $kandidat_pk)) {
            return $kandidat_pk;
        }
    }
    return 'id';
}

function cari_kolom_ls_portal(mysqli $koneksi, string $tabel, array $kandidat): string {
    foreach ($kandidat as $kolom) {
        if (kolom_ada_ls_portal($koneksi, $tabel, $kolom)) {
            return $kolom;
        }
    }
    return '';
}

function ambil_daftar_tabel_akun_ls_portal(mysqli $koneksi): array {
    $prioritas = ['users', 'pengirim', 'pengirim_surat', 'pengguna', 'akun', 'user'];
    $ditemukan = [];

    foreach ($prioritas as $t) {
        $t_esc = mysqli_real_escape_string($koneksi, $t);
        $res = mysqli_query($koneksi, "SHOW TABLES LIKE '$t_esc'");
        if ($res && mysqli_num_rows($res) > 0) {
            $ditemukan[] = $t;
        }
    }

    $res_all = mysqli_query($koneksi, "SHOW TABLES");
    if ($res_all) {
        while ($row = mysqli_fetch_row($res_all)) {
            $nama_tbl = (string)($row[0] ?? '');
            if ($nama_tbl === '' || in_array($nama_tbl, $ditemukan, true)) {
                continue;
            }
            $kol_u = cari_kolom_ls_portal($koneksi, $nama_tbl, ['username', 'user']);
            $kol_e = cari_kolom_ls_portal($koneksi, $nama_tbl, ['email']);
            $kol_p = cari_kolom_ls_portal($koneksi, $nama_tbl, ['password', 'sandi', 'kata_sandi']);
            if ($kol_p !== '' && ($kol_u !== '' || $kol_e !== '')) {
                $ditemukan[] = $nama_tbl;
            }
        }
    }

    return $ditemukan;
}

function cari_akun_di_database_ls_portal(mysqli $koneksi, string $identitas): ?array {
    foreach (ambil_daftar_tabel_akun_ls_portal($koneksi) as $tabel) {
        $kol_u = cari_kolom_ls_portal($koneksi, $tabel, ['username', 'user']);
        $kol_e = cari_kolom_ls_portal($koneksi, $tabel, ['email']);
        $kol_p = cari_kolom_ls_portal($koneksi, $tabel, ['password', 'sandi', 'kata_sandi']);
        $kol_n = cari_kolom_ls_portal($koneksi, $tabel, ['nama', 'nama_lengkap', 'full_name']);
        $pk    = deteksi_pk_ls_portal($koneksi, $tabel);

        $syarat = [];
        $types  = '';
        $params = [];

        if ($kol_u !== '') {
            $syarat[] = "LOWER(TRIM(`$kol_u`)) = LOWER(?)";
            $types .= 's';
            $params[] = $identitas;
        }
        if ($kol_e !== '') {
            $syarat[] = "LOWER(TRIM(`$kol_e`)) = LOWER(?)";
            $types .= 's';
            $params[] = $identitas;
        }

        if (empty($syarat) || $kol_p === '') {
            continue;
        }

        $t_esc = mysqli_real_escape_string($koneksi, $tabel);
        $stmt_c = mysqli_prepare($koneksi, "SELECT * FROM `$t_esc` WHERE " . implode(" OR ", $syarat) . " LIMIT 1");
        if (!$stmt_c) {
            continue;
        }
        mysqli_stmt_bind_param($stmt_c, $types, ...$params);
        mysqli_stmt_execute($stmt_c);
        $res_c = mysqli_stmt_get_result($stmt_c);
        $row_c = mysqli_fetch_assoc($res_c) ?: null;
        mysqli_stmt_close($stmt_c);

        if ($row_c) {
            return [
                'tabel'   => $tabel,
                'pk'      => $pk,
                'kol_u'   => $kol_u,
                'kol_e'   => $kol_e,
                'kol_p'   => $kol_p,
                'kol_n'   => $kol_n,
                'row'     => $row_c,
                'id_val'  => $row_c[$pk] ?? null,
                'email'   => ($kol_e !== '') ? trim((string)($row_c[$kol_e] ?? '')) : '',
                'nama'    => ($kol_n !== '') ? trim((string)($row_c[$kol_n] ?? '')) : '',
            ];
        }
    }

    return null;
}

function kirim_kode_konfirmasi_email_portal(string $email_tujuan, string $kode_otp, string $nama_user, string $email_baru = ''): array {
    $nama_tampil = $nama_user !== '' ? $nama_user : 'Pengguna';
    $subjek = 'Konfirmasi Lupa Kata Sandi - Portal E-Office Diskominfo Sangihe';
    $isi = "Halo " . $nama_tampil . ",\n\n"
        . "Kami menerima permintaan pengaturan ulang kata sandi untuk akun Anda.\n"
        . "Apakah benar ini Anda? Jika ya, gunakan kode konfirmasi berikut untuk membuat sandi baru:\n\n"
        . "KODE KONFIRMASI: " . $kode_otp . "\n\n"
        . ($email_baru !== '' ? "Email baru (opsional) yang akan disimpan: " . $email_baru . "\n" : "")
        . "Kode berlaku selama 10 menit. Jika Anda tidak merasa meminta reset sandi, abaikan email ini.\n\n"
        . "Dinas Komunikasi dan Informatika Daerah Kabupaten Kepulauan Sangihe";

    if (function_exists('kirim_otp_smtp')) {
        $detail = '';
        $ok = kirim_otp_smtp($email_tujuan, $kode_otp, $nama_tampil, $detail);
        return [
            'terkirim' => (bool)$ok,
            'error'    => $ok ? '' : ($detail !== '' ? $detail : 'kirim_otp_smtp mengembalikan gagal.'),
        ];
    }

    if (class_exists('\PHPMailer\PHPMailer\PHPMailer')) {
        $smtp_cfg = [];
        if (isset($GLOBALS['smtp_config']) && is_array($GLOBALS['smtp_config'])) {
            $smtp_cfg = $GLOBALS['smtp_config'];
        } elseif (defined('SMTP_HOST') && defined('SMTP_USER') && defined('SMTP_PASS')) {
            $smtp_cfg = [
                'host'       => SMTP_HOST,
                'username'   => SMTP_USER,
                'password'   => SMTP_PASS,
                'port'       => defined('SMTP_PORT') ? SMTP_PORT : 587,
                'encryption' => defined('SMTP_ENCRYPTION') ? SMTP_ENCRYPTION : 'tls',
                'from_email' => defined('SMTP_FROM') ? SMTP_FROM : SMTP_USER,
                'from_name'  => defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'Portal E-Office Diskominfo Sangihe',
            ];
        }

        if (!empty($smtp_cfg['host']) && !empty($smtp_cfg['username']) && !empty($smtp_cfg['password'])) {
            try {
                $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
                $mail->isSMTP();
                $mail->Host       = (string)$smtp_cfg['host'];
                $mail->SMTPAuth   = true;
                $mail->Username   = (string)$smtp_cfg['username'];
                $mail->Password   = (string)$smtp_cfg['password'];
                $enc = strtolower((string)($smtp_cfg['encryption'] ?? 'tls'));
                if ($enc === 'ssl' || $enc === 'smtps') {
                    $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
                } else {
                    $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                }
                $mail->Port       = (int)($smtp_cfg['port'] ?? 587);
                $mail->CharSet    = 'UTF-8';

                $from_email = (string)($smtp_cfg['from_email'] ?? $smtp_cfg['username']);
                $from_name  = (string)($smtp_cfg['from_name'] ?? 'Portal E-Office Diskominfo Sangihe');
                $mail->setFrom($from_email, $from_name);
                $mail->addAddress($email_tujuan, $nama_tampil);
                $mail->Subject = $subjek;
                $mail->Body    = $isi;
                $mail->send();

                return ['terkirim' => true, 'error' => ''];
            } catch (\Throwable $e) {
                return ['terkirim' => false, 'error' => 'SMTP error: ' . $e->getMessage()];
            }
        }
    }

    $headers = "From: Portal E-Office Diskominfo Sangihe <no-reply@diskominfo-sangihe.go.id>\r\n"
        . "Reply-To: no-reply@diskominfo-sangihe.go.id\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n";
    $ok = @mail($email_tujuan, $subjek, $isi, $headers);
    if ($ok) {
        return ['terkirim' => true, 'error' => ''];
    }

    return [
        'terkirim' => false,
        'error'    => 'Fungsi kirim_otp_smtp() atau konfigurasi SMTP belum tersedia di server/koneksi.php.',
    ];
}

$email_konfirmasi_utama = 'jenovaniacristaniaparaeng@gmail.com';
$error_msg = '';
$info_msg  = '';
$buka_modal_lupa_sandi = false;
$sesi_ls = $_SESSION['lupa_sandi_portal'] ?? null;

$ada_koneksi_db = (isset($koneksi) && $koneksi instanceof mysqli);

// Tahap 1: Kirim kode konfirmasi ke email jenovaniacristaniaparaeng@gmail.com
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['kirim_kode_lupa_sandi'])) {
    $buka_modal_lupa_sandi = true;
    $akun_lupa = trim((string)($_POST['akun_lupa'] ?? ''));
    $email_baru_opsional = trim((string)($_POST['email_baru'] ?? ''));

    if ($akun_lupa === '') {
        $error_msg = 'Masukkan username atau email akun terlebih dahulu.';
    } elseif ($email_baru_opsional !== '' && !filter_var($email_baru_opsional, FILTER_VALIDATE_EMAIL)) {
        $error_msg = 'Format email baru tidak valid.';
    } elseif (!$ada_koneksi_db) {
        $error_msg = 'Koneksi database belum tersedia untuk memproses lupa sandi.';
    } else {
        $ketemu = cari_akun_di_database_ls_portal($koneksi, $akun_lupa);

        if (!$ketemu || $ketemu['id_val'] === null || $ketemu['id_val'] === '') {
            $error_msg = 'Akun "' . $akun_lupa . '" tidak ditemukan pada database.';
        } else {
            $email_u = $email_konfirmasi_utama;
            $nama_u = $ketemu['nama'] !== '' ? $ketemu['nama'] : $akun_lupa;

            $kode_otp = (string)random_int(100000, 999999);
            $sesi_baru = [
                'tabel'      => $ketemu['tabel'],
                'pk'         => $ketemu['pk'],
                'kol_e'      => $ketemu['kol_e'],
                'kol_p'      => $ketemu['kol_p'],
                'id_val'     => $ketemu['id_val'],
                'akun_input' => $akun_lupa,
                'email'      => $email_u,
                'email_baru' => $email_baru_opsional,
                'nama'       => $nama_u,
                'kode'       => $kode_otp,
                'expires_at' => time() + 600,
                'salah_coba' => 0,
            ];
            $_SESSION['lupa_sandi_portal'] = $sesi_baru;
            $sesi_ls = $sesi_baru;

            $kirim = kirim_kode_konfirmasi_email_portal($email_u, $kode_otp, $nama_u, $email_baru_opsional);
            if ($kirim['terkirim']) {
                $info_msg = 'Kode konfirmasi telah dikirim ke email ' . $email_u . ' untuk akun "' . $akun_lupa . '" (tabel ' . $ketemu['tabel'] . '). Silakan cek Inbox atau folder Spam email ' . $email_u . ' untuk memastikan apakah benar itu Anda, lalu buat sandi baru'
                    . ($email_baru_opsional !== '' ? ' (email baru ' . $email_baru_opsional . ' akan disimpan setelah kode dikonfirmasi).' : '.');
            } else {
                $detail_err = trim((string)($kirim['error'] ?? ''));
                $error_msg = 'Kode konfirmasi dikirim ke email ' . $email_u . ', namun pengiriman gagal' . ($detail_err !== '' ? ': ' . $detail_err : '.');
            }
        }
    }
}

// Batalkan sesi konfirmasi lupa sandi
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['batal_lupa_sandi'])) {
    $buka_modal_lupa_sandi = true;
    unset($_SESSION['lupa_sandi_portal']);
    $sesi_ls = null;
    $info_msg = 'Permintaan konfirmasi lupa sandi dibatalkan.';
}

// Tahap 2: Konfirmasi kode email ("Apakah benar itu Anda?") lalu simpan sandi baru (dan email baru jika diisi)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['proses_lupa_sandi'])) {
    $buka_modal_lupa_sandi = true;
    $kode_konfirmasi = trim((string)($_POST['kode_konfirmasi_email'] ?? ''));
    $sandi_baru = (string)($_POST['sandi_baru'] ?? '');
    $sandi_konfirmasi = (string)($_POST['sandi_konfirmasi'] ?? '');
    $email_baru_opsional = trim((string)($_POST['email_baru'] ?? ($sesi_ls['email_baru'] ?? '')));

    if (!is_array($sesi_ls) || !isset($sesi_ls['id_val']) || $sesi_ls['id_val'] === '') {
        $error_msg = 'Konfirmasi email wajib dilakukan terlebih dahulu. Masukkan username/email lalu klik "Kirim Kode Email".';
    } elseif (time() > (int)($sesi_ls['expires_at'] ?? 0)) {
        $email_sesi = (string)($sesi_ls['email'] ?? $email_konfirmasi_utama);
        unset($_SESSION['lupa_sandi_portal']);
        $sesi_ls = null;
        $error_msg = 'Kode konfirmasi yang dikirim ke email ' . $email_sesi . ' sudah kedaluwarsa. Silakan kirim ulang kode konfirmasi.';
    } elseif ((int)($sesi_ls['salah_coba'] ?? 0) >= 5) {
        $email_sesi = (string)($sesi_ls['email'] ?? $email_konfirmasi_utama);
        unset($_SESSION['lupa_sandi_portal']);
        $sesi_ls = null;
        $error_msg = 'Terlalu banyak percobaan kode yang salah untuk email ' . $email_sesi . '. Silakan kirim ulang kode konfirmasi baru.';
    } elseif ($kode_konfirmasi === '' || !hash_equals((string)($sesi_ls['kode'] ?? ''), $kode_konfirmasi)) {
        $_SESSION['lupa_sandi_portal']['salah_coba'] = (int)($sesi_ls['salah_coba'] ?? 0) + 1;
        $sesi_ls = $_SESSION['lupa_sandi_portal'];
        $error_msg = 'Konfirmasi gagal: kode tidak sesuai dengan yang dikirim ke email ' . (string)$sesi_ls['email'] . '. Periksa kembali Inbox atau Spam email tersebut.';
    } elseif ($sandi_baru === '' || $sandi_konfirmasi === '') {
        $error_msg = 'Lengkapi kata sandi baru dan konfirmasi kata sandi.';
    } elseif (strlen($sandi_baru) < 6) {
        $error_msg = 'Kata sandi baru minimal 6 karakter.';
    } elseif ($sandi_baru !== $sandi_konfirmasi) {
        $error_msg = 'Konfirmasi kata sandi baru tidak sama.';
    } elseif ($email_baru_opsional !== '' && !filter_var($email_baru_opsional, FILTER_VALIDATE_EMAIL)) {
        $error_msg = 'Format email baru tidak valid.';
    } elseif (!$ada_koneksi_db || empty($sesi_ls['tabel']) || empty($sesi_ls['pk']) || empty($sesi_ls['kol_p'])) {
        $error_msg = 'Koneksi database atau struktur kolom sandi tidak tersedia.';
    } else {
        $tbl_esc = mysqli_real_escape_string($koneksi, (string)$sesi_ls['tabel']);
        $pk_esc  = mysqli_real_escape_string($koneksi, (string)$sesi_ls['pk']);
        $kol_p_esc = mysqli_real_escape_string($koneksi, (string)$sesi_ls['kol_p']);
        $kol_e_name = (string)($sesi_ls['kol_e'] ?? '');
        $id_target = (string)$sesi_ls['id_val'];
        $email_terverifikasi = (string)($sesi_ls['email'] ?? $email_konfirmasi_utama);
        $hash_p = password_hash($sandi_baru, PASSWORD_DEFAULT);

        $set_sql = ["`$kol_p_esc` = ?"];
        $types_u = 's';
        $params_u = [$hash_p];

        $update_email_berhasil = false;
        if ($email_baru_opsional !== '' && $kol_e_name !== '') {
            $kol_e_esc = mysqli_real_escape_string($koneksi, $kol_e_name);
            $set_sql[] = "`$kol_e_esc` = ?";
            $types_u .= 's';
            $params_u[] = $email_baru_opsional;
            $update_email_berhasil = true;
        }

        if (ctype_digit($id_target)) {
            $id_int = (int)$id_target;
            $types_u .= 'i';
            $params_u[] = $id_int;
        } else {
            $types_u .= 's';
            $params_u[] = $id_target;
        }

        $stmt_u = mysqli_prepare($koneksi, "UPDATE `$tbl_esc` SET " . implode(', ', $set_sql) . " WHERE `$pk_esc` = ? LIMIT 1");
        if ($stmt_u) {
            mysqli_stmt_bind_param($stmt_u, $types_u, ...$params_u);

            if (mysqli_stmt_execute($stmt_u)) {
                unset($_SESSION['lupa_sandi_portal']);
                $sesi_ls = null;
                $info_msg = 'Konfirmasi melalui email ' . $email_terverifikasi . ' berhasil. Kata sandi baru telah disimpan'
                    . ($update_email_berhasil ? ' dan email akun diperbarui menjadi ' . $email_baru_opsional : '')
                    . '—silakan masuk menggunakan kata sandi baru Anda.';
                $buka_modal_lupa_sandi = false;
            } else {
                $error_msg = 'Gagal menyimpan kata sandi baru.';
            }
            mysqli_stmt_close($stmt_u);
        } else {
            $error_msg = 'Gagal menyiapkan perintah pembaruan kata sandi.';
        }
    }
}

$sedang_konfirmasi_email = is_array($sesi_ls) && isset($sesi_ls['id_val']) && $sesi_ls['id_val'] !== '';
$nilai_akun_lupa = (string)($sesi_ls['akun_input'] ?? ($_POST['akun_lupa'] ?? ''));
$nilai_email_baru = (string)($sesi_ls['email_baru'] ?? ($_POST['email_baru'] ?? ''));

if (!empty($_SESSION['login_error'])) {
    $error_msg = (string)$_SESSION['login_error'];
    unset($_SESSION['login_error']);
} elseif (!empty($_GET['error']) && $error_msg === '') {
    $error_msg = (string)$_GET['error'];
}

if (!empty($_SESSION['login_info'])) {
    $info_msg = (string)$_SESSION['login_info'];
    unset($_SESSION['login_info']);
} elseif (isset($_GET['terdaftar']) && $_GET['terdaftar'] === '1' && $info_msg === '') {
    $info_msg = 'Akun baru berhasil dibuat. Silakan masuk menggunakan username atau email dan kata sandi Anda.';
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Portal E-Office Diskominfo Sangihe</title>
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
            padding: 10px 16px;
        }
        .card-custom {
            background-color: #3b6998;
            border: 1px solid #2f5780;
            border-radius: 16px;
            width: 100%;
            max-width: 450px;
            box-shadow: 0 14px 32px rgba(47, 87, 128, 0.25);
            padding: 18px 24px;
        }
        .header-box {
            background-color: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 4px;
            height: 142px;
            overflow: hidden;
            text-align: center;
            box-shadow: 0 4px 12px rgba(15, 23, 42, 0.14);
            display: flex;
            justify-content: center;
            align-items: center;
            margin-bottom: 12px;
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
            font-size: 0.82rem;
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
        .forgot-row {
            display: flex;
            justify-content: flex-end;
            margin-top: 3px;
        }
        .forgot-link {
            color: #dbeafe;
            font-size: 0.79rem;
            font-weight: 500;
            text-decoration: none;
            cursor: pointer;
        }
        .forgot-link:hover {
            text-decoration: underline;
            color: #ffffff;
        }
        .btn-login {
            background-color: #2b537c;
            border: 1px solid #224364;
            border-radius: 8px;
            padding: 9px;
            font-size: 0.92rem;
            font-weight: 600;
            color: #ffffff;
            width: 100%;
            transition: all 0.2s ease;
            box-shadow: 0 4px 12px rgba(20, 42, 66, 0.28);
            margin-top: 2px;
        }
        .btn-login:hover {
            background-color: #4372a1;
            border-color: #325980;
            color: #ffffff;
            transform: translateY(-1px);
        }
        .divider {
            display: flex;
            align-items: center;
            text-align: center;
            color: #cbd5e1;
            font-size: 0.7rem;
            font-weight: 500;
            margin: 10px 0;
        }
        .divider::before, .divider::after {
            content: '';
            flex: 1;
            border-bottom: 1px solid rgba(255, 255, 255, 0.25);
        }
        .divider::before {
            margin-right: 10px;
        }
        .divider::after {
            margin-left: 10px;
        }
        .btn-google {
            background-color: #eef2f7;
            color: #1e293b;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            padding: 8px 10px;
            font-size: 0.88rem;
            font-weight: 600;
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            text-decoration: none;
            transition: background-color 0.2s;
        }
        .btn-google:hover {
            background-color: #ffffff;
            color: #0f172a;
        }
        .register-hint {
            text-align: center;
            color: #e2e8f0;
            font-size: 0.79rem;
            margin-top: 9px;
        }
        .register-hint a {
            color: #ffffff;
            font-weight: 600;
            text-decoration: underline;
        }
        .register-hint a:hover {
            color: #dbeafe;
        }
        .back-link {
            display: block;
            text-align: center;
            color: #cbd5e1;
            font-size: 0.8rem;
            text-decoration: none;
            margin-top: 8px;
            transition: color 0.2s;
        }
        .back-link:hover {
            color: #ffffff;
        }
        .modal-content-biru {
            background-color: #3b6998;
            color: #f8fafc;
            border: 1px solid #2f5780;
            border-radius: 14px;
        }
        .modal-content-biru .modal-header {
            background-color: #2f5780;
            color: #ffffff;
            border-bottom: 1px solid rgba(255, 255, 255, 0.15);
            border-top-left-radius: 13px;
            border-top-right-radius: 13px;
        }
        .modal-content-biru .modal-footer {
            background-color: #325c87;
            border-top: 1px solid rgba(255, 255, 255, 0.15);
            border-bottom-left-radius: 13px;
            border-bottom-right-radius: 13px;
        }
    </style>
</head>
<body>

    <div class="card card-custom">
        <div class="header-box">
            <img src="assets/logo_sangihe.png" alt="Logo LSM Diskominfo Sangihe" class="logo-img">
        </div>

        <?php if ($info_msg !== ''): ?>
            <div class="alert alert-success py-1 px-3 small mb-2" role="alert">
                <?= htmlspecialchars($info_msg, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <?php if ($error_msg !== ''): ?>
            <div class="alert alert-danger py-1 px-3 small mb-2" role="alert">
                <?= htmlspecialchars($error_msg, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <form action="proses_login.php" method="POST" autocomplete="off">
            <div class="mb-2">
                <label class="form-label">Username atau Email</label>
                <input type="text" id="usernameInput" name="username" class="form-control" value="" placeholder="Masukkan username atau email..." autocomplete="off" required>
            </div>
            
            <div class="mb-2">
                <label class="form-label">Kata Sandi</label>
                <div class="sandi-wrapper">
                    <input type="password" id="passwordInput" name="password" class="form-control" value="" placeholder="••••••••••••" autocomplete="new-password" required>
                    <button type="button" id="toggleSandiBtn" class="btn-toggle-sandi" aria-label="Tampilkan sandi" title="Tampilkan sandi"></button>
                </div>
                <div class="forgot-row">
                    <a href="#modalLupaSandi" class="forgot-link" data-bs-toggle="modal" data-bs-target="#modalLupaSandi">Lupa Sandi?</a>
                </div>
            </div>

            <button type="submit" class="btn-login">Masuk Ke Dasbor</button>
        </form>

        <div class="register-hint">
            Belum memiliki akun pengirim? <a href="daftar_pengirim.php">Buat Akun Baru</a>
        </div>

        <div class="divider">ATAU MASUK SEBAGAI PENGIRIM PUBLIK</div>

        <a href="login_google.php" class="btn-google">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 48 48">
                <path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/>
                <path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/>
                <path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/>
                <path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.46-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/>
            </svg>
            Masuk dengan Akun Google
        </a>

        <a href="index.php" class="back-link">← Kembali ke Beranda</a>
    </div>

    <div class="modal fade" id="modalLupaSandi" tabindex="-1" aria-labelledby="modalLupaSandiLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content modal-content-biru">
                <div class="modal-header">
                    <h6 class="modal-title fw-bold" id="modalLupaSandiLabel">Lupa Sandi &mdash; Konfirmasi Email</h6>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Tutup"></button>
                </div>
                <form action="" method="POST" autocomplete="off">
                    <div class="modal-body">
                        <div class="small text-white-50 mb-3">
                            Kode konfirmasi akan dikirim ke email <strong class="text-white"><?= htmlspecialchars($email_konfirmasi_utama, ENT_QUOTES, 'UTF-8'); ?></strong> untuk memastikan apakah benar itu Anda sebelum membuat sandi baru.
                        </div>

                        <?php if ($info_msg !== ''): ?>
                            <div class="alert alert-success py-2 px-3 small mb-3" role="alert">
                                <?= htmlspecialchars($info_msg, ENT_QUOTES, 'UTF-8'); ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($error_msg !== ''): ?>
                            <div class="alert alert-danger py-2 px-3 small mb-3" role="alert">
                                <?= htmlspecialchars($error_msg, ENT_QUOTES, 'UTF-8'); ?>
                            </div>
                        <?php endif; ?>

                        <div class="mb-3">
                            <label class="form-label">Username atau Email Akun</label>
                            <div class="input-group">
                                <input type="text" name="akun_lupa" class="form-control" placeholder="Masukkan username atau email..." value="<?= htmlspecialchars($nilai_akun_lupa, ENT_QUOTES, 'UTF-8'); ?>" required>
                                <button type="submit" name="kirim_kode_lupa_sandi" value="1" formnovalidate class="btn btn-light btn-sm fw-semibold px-3">
                                    <?= $sedang_konfirmasi_email ? 'Kirim Ulang Kode' : 'Kirim Kode Email'; ?>
                                </button>
                            </div>
                        </div>

                        <?php if ($sedang_konfirmasi_email): ?>
                            <div class="alert alert-light text-dark py-2 px-3 small mb-3">
                                <strong>Apakah benar ini Anda?</strong> Kode konfirmasi dikirim ke email <strong><?= htmlspecialchars((string)$sesi_ls['email'], ENT_QUOTES, 'UTF-8'); ?></strong>. Masukkan kode dari email tersebut untuk membuat sandi baru.
                            </div>
                        <?php endif; ?>

                        <div class="mb-3">
                            <label class="form-label">Kode Konfirmasi Email (6 Digit)</label>
                            <input type="text" name="kode_konfirmasi_email" class="form-control" maxlength="6" pattern="[0-9]{6}" placeholder="Contoh: 123456" autocomplete="one-time-code" <?= $sedang_konfirmasi_email ? 'required' : ''; ?>>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Kata Sandi Baru</label>
                            <div class="sandi-wrapper">
                                <input type="password" id="sandiBaruInput" name="sandi_baru" class="form-control" placeholder="Minimal 6 karakter..." minlength="6" autocomplete="new-password" <?= $sedang_konfirmasi_email ? 'required' : ''; ?>>
                                <button type="button" class="btn-toggle-sandi toggle-sandi-target" data-target="sandiBaruInput" aria-label="Tampilkan sandi" title="Tampilkan sandi"></button>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Konfirmasi Kata Sandi Baru</label>
                            <div class="sandi-wrapper">
                                <input type="password" id="sandiKonfirmasiInput" name="sandi_konfirmasi" class="form-control" placeholder="Ulangi kata sandi baru..." minlength="6" autocomplete="new-password" <?= $sedang_konfirmasi_email ? 'required' : ''; ?>>
                                <button type="button" class="btn-toggle-sandi toggle-sandi-target" data-target="sandiKonfirmasiInput" aria-label="Tampilkan sandi" title="Tampilkan sandi"></button>
                            </div>
                        </div>
                        <div class="mb-2">
                            <label class="form-label">Email Baru <span class="text-white-50 fw-normal">(Opsional)</span></label>
                            <input type="email" name="email_baru" class="form-control" placeholder="Kosongkan jika tidak ingin mengganti email..." value="<?= htmlspecialchars($nilai_email_baru, ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                    </div>
                    <div class="modal-footer justify-content-between">
                        <div>
                            <?php if ($sedang_konfirmasi_email): ?>
                                <button type="submit" name="batal_lupa_sandi" value="1" formnovalidate class="btn btn-outline-light btn-sm">Batalkan</button>
                            <?php else: ?>
                                <button type="button" class="btn btn-outline-light btn-sm" data-bs-dismiss="modal">Tutup</button>
                            <?php endif; ?>
                        </div>
                        <button type="submit" name="proses_lupa_sandi" value="1" class="btn btn-login btn-sm w-auto px-3 mt-0">Konfirmasi &amp; Simpan Sandi Baru</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const svgMataTerbuka = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
            const svgMataTertutup = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.94 10.94 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>';

            function perbaruiTombolMata(btn, sedangTerlihat) {
                btn.innerHTML = sedangTerlihat ? svgMataTertutup : svgMataTerbuka;
                btn.setAttribute('aria-label', sedangTerlihat ? 'Sembunyikan sandi' : 'Tampilkan sandi');
                btn.setAttribute('title', sedangTerlihat ? 'Sembunyikan sandi' : 'Tampilkan sandi');
            }

            const userInput = document.getElementById('usernameInput');
            const toggleBtn = document.getElementById('toggleSandiBtn');
            const passInput = document.getElementById('passwordInput');

            setTimeout(function () {
                if (userInput && !userInput.dataset.userEdited) {
                    userInput.value = '';
                }
                if (passInput && !passInput.dataset.userEdited) {
                    passInput.value = '';
                }
            }, 60);

            if (userInput) {
                userInput.addEventListener('input', function () {
                    userInput.dataset.userEdited = '1';
                });
            }
            if (passInput) {
                passInput.addEventListener('input', function () {
                    passInput.dataset.userEdited = '1';
                });
            }

            if (toggleBtn && passInput) {
                perbaruiTombolMata(toggleBtn, passInput.type === 'text');
                toggleBtn.addEventListener('click', function () {
                    const tampil = passInput.type === 'password';
                    passInput.type = tampil ? 'text' : 'password';
                    perbaruiTombolMata(toggleBtn, tampil);
                });
            }

            document.querySelectorAll('.toggle-sandi-target').forEach(function (btn) {
                const target = document.getElementById(btn.getAttribute('data-target'));
                if (!target) return;
                perbaruiTombolMata(btn, target.type === 'text');
                btn.addEventListener('click', function () {
                    const tampil = target.type === 'password';
                    target.type = tampil ? 'text' : 'password';
                    perbaruiTombolMata(btn, tampil);
                });
            });

            <?php if ($buka_modal_lupa_sandi): ?>
            const modalEl = document.getElementById('modalLupaSandi');
            if (modalEl && typeof bootstrap !== 'undefined') {
                new bootstrap.Modal(modalEl).show();
            }
            <?php endif; ?>
        });
    </script>
</body>
</html>