<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$path_koneksi = file_exists(__DIR__ . '/../koneksi.php') ? __DIR__ . '/../koneksi.php' : __DIR__ . '/koneksi.php';
include_once $path_koneksi;

$ada_sesi = isset($_SESSION['user'])
    || isset($_SESSION['id_user'])
    || isset($_SESSION['user_id'])
    || isset($_SESSION['id'])
    || isset($_SESSION['username'])
    || isset($_SESSION['email'])
    || isset($_SESSION['google_email']);

if (!$ada_sesi) {
    $path_login = file_exists(__DIR__ . '/login.php') ? 'login.php' : '../login.php';
    header("Location: " . $path_login);
    exit();
}

function kolom_ada_pg_dash(mysqli $koneksi, string $tabel, string $kolom): bool {
    $t = mysqli_real_escape_string($koneksi, $tabel);
    $k = mysqli_real_escape_string($koneksi, $kolom);
    $res = mysqli_query($koneksi, "SHOW COLUMNS FROM `$t` LIKE '$k'");
    return ($res && mysqli_num_rows($res) > 0);
}

function tabel_ada_pg_dash(mysqli $koneksi, string $tabel): bool {
    $t = mysqli_real_escape_string($koneksi, $tabel);
    $res = mysqli_query($koneksi, "SHOW TABLES LIKE '$t'");
    return ($res && mysqli_num_rows($res) > 0);
}

function deteksi_pk_pg_dash(mysqli $koneksi, string $tabel): string {
    $t = mysqli_real_escape_string($koneksi, $tabel);
    $res = mysqli_query($koneksi, "SHOW KEYS FROM `$t` WHERE Key_name = 'PRIMARY'");
    if ($res && ($row = mysqli_fetch_assoc($res)) && !empty($row['Column_name'])) {
        return (string)$row['Column_name'];
    }
    return 'id';
}

function cari_kolom_pg_dash(mysqli $koneksi, string $tabel, array $kandidat): string {
    foreach ($kandidat as $kolom) {
        if (kolom_ada_pg_dash($koneksi, $tabel, $kolom)) {
            return $kolom;
        }
    }
    return '';
}

function kirim_otp_profil_pg(string $email_tujuan, string $kode_otp, string $nama_user): array {
    if (function_exists('kirim_otp_smtp')) {
        $detail = '';
        $ok = kirim_otp_smtp($email_tujuan, $kode_otp, $nama_user !== '' ? $nama_user : 'Pengirim', $detail);
        return [
            'terkirim' => (bool)$ok,
            'error'    => $ok ? '' : $detail,
        ];
    }

    $subjek = 'Kode Persetujuan Pembaruan Profil Pengirim - Portal E-Office Diskominfo Sangihe';
    $isi = "Halo " . ($nama_user !== '' ? $nama_user : 'Pengirim') . ",\n\n"
        . "Kode persetujuan untuk memperbarui data profil dan/atau keamanan akun Pengirim:\n"
        . "KODE OTP: " . $kode_otp . "\n\n"
        . "Kode berlaku selama 10 menit.\n\n"
        . "Dinas Komunikasi dan Informatika Daerah Kabupaten Kepulauan Sangihe";

    $headers = "From: Portal E-Office Diskominfo Sangihe <no-reply@diskominfo-sangihe.go.id>\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n";
    $ok = @mail($email_tujuan, $subjek, $isi, $headers);
    return [
        'terkirim' => (bool)$ok,
        'error'    => $ok ? '' : 'Fungsi pengiriman email tidak tersedia atau gagal.',
    ];
}

$id_login    = (int)($_SESSION['user_id'] ?? $_SESSION['id_user'] ?? $_SESSION['id'] ?? (is_array($_SESSION['user'] ?? null) ? ($_SESSION['user']['id'] ?? 0) : 0));
$user_login  = trim((string)($_SESSION['username'] ?? (is_array($_SESSION['user'] ?? null) ? ($_SESSION['user']['username'] ?? '') : '')));
$email_login = trim((string)($_SESSION['email'] ?? $_SESSION['google_email'] ?? (is_array($_SESSION['user'] ?? null) ? ($_SESSION['user']['email'] ?? '') : '')));

// [LETAK 1] Kosongkan default nama agar akun baru tidak ikut mencocokkan nama orang lain
$nama_tampil = '';
if (!empty($_SESSION['google_name'])) {
    $nama_tampil = trim((string)$_SESSION['google_name']);
} elseif (is_array($_SESSION['user'] ?? null) && !empty($_SESSION['user']['name'])) {
    $nama_tampil = trim((string)$_SESSION['user']['name']);
} elseif (is_array($_SESSION['user'] ?? null) && !empty($_SESSION['user']['nama'])) {
    $nama_tampil = trim((string)$_SESSION['user']['nama']);
} elseif (!empty($_SESSION['nama'])) {
    $nama_tampil = trim((string)$_SESSION['nama']);
} elseif (!empty($_SESSION['name'])) {
    $nama_tampil = trim((string)$_SESSION['name']);
} elseif ($user_login !== '') {
    $nama_tampil = $user_login;
}

$ada_tabel_users = (isset($koneksi) && $koneksi instanceof mysqli) ? tabel_ada_pg_dash($koneksi, 'users') : false;
$pk_users        = $ada_tabel_users ? deteksi_pk_pg_dash($koneksi, 'users') : 'id';
$kol_u_nama      = $ada_tabel_users ? cari_kolom_pg_dash($koneksi, 'users', ['nama', 'nama_lengkap', 'full_name']) : '';
$kol_u_email     = $ada_tabel_users ? cari_kolom_pg_dash($koneksi, 'users', ['email']) : '';
$kol_u_username  = $ada_tabel_users ? cari_kolom_pg_dash($koneksi, 'users', ['username', 'user']) : '';
$kol_u_password  = $ada_tabel_users ? cari_kolom_pg_dash($koneksi, 'users', ['password', 'sandi', 'kata_sandi']) : '';

$data_akun_pg = [];
if ($ada_tabel_users) {
    $pk_u_esc = mysqli_real_escape_string($koneksi, $pk_users);
    if ($id_login > 0) {
        $stmt_u = mysqli_prepare($koneksi, "SELECT * FROM `users` WHERE `$pk_u_esc` = ? LIMIT 1");
        if ($stmt_u) {
            mysqli_stmt_bind_param($stmt_u, 'i', $id_login);
            mysqli_stmt_execute($stmt_u);
            $res_u = mysqli_stmt_get_result($stmt_u);
            $data_akun_pg = mysqli_fetch_assoc($res_u) ?: [];
            mysqli_stmt_close($stmt_u);
        }
    }
    if (empty($data_akun_pg) && $kol_u_email !== '' && $email_login !== '') {
        $stmt_u = mysqli_prepare($koneksi, "SELECT * FROM `users` WHERE LOWER(TRIM(`$kol_u_email`)) = LOWER(?) LIMIT 1");
        if ($stmt_u) {
            mysqli_stmt_bind_param($stmt_u, 's', $email_login);
            mysqli_stmt_execute($stmt_u);
            $res_u = mysqli_stmt_get_result($stmt_u);
            $data_akun_pg = mysqli_fetch_assoc($res_u) ?: [];
            mysqli_stmt_close($stmt_u);
        }
    }
    if (empty($data_akun_pg) && $kol_u_username !== '' && $user_login !== '') {
        $stmt_u = mysqli_prepare($koneksi, "SELECT * FROM `users` WHERE LOWER(TRIM(`$kol_u_username`)) = LOWER(?) LIMIT 1");
        if ($stmt_u) {
            mysqli_stmt_bind_param($stmt_u, 's', $user_login);
            mysqli_stmt_execute($stmt_u);
            $res_u = mysqli_stmt_get_result($stmt_u);
            $data_akun_pg = mysqli_fetch_assoc($res_u) ?: [];
            mysqli_stmt_close($stmt_u);
        }
    }

    if (!empty($data_akun_pg)) {
        if ($kol_u_nama !== '' && !empty($data_akun_pg[$kol_u_nama])) {
            $nama_tampil = trim((string)$data_akun_pg[$kol_u_nama]);
        } elseif (!empty($data_akun_pg['name'])) {
            $nama_tampil = trim((string)$data_akun_pg['name']);
        }
        if ($id_login === 0 && !empty($data_akun_pg[$pk_users])) {
            $id_login = (int)$data_akun_pg[$pk_users];
        }
        if ($kol_u_username !== '' && !empty($data_akun_pg[$kol_u_username])) {
            $user_login = trim((string)$data_akun_pg[$kol_u_username]);
        }
        if ($kol_u_email !== '' && !empty($data_akun_pg[$kol_u_email])) {
            $email_login = trim((string)$data_akun_pg[$kol_u_email]);
        }
    }
}

$nama_label = $nama_tampil !== '' ? $nama_tampil : ($user_login !== '' ? $user_login : 'Pengirim');

$menu = strtolower(trim((string)($_GET['menu'] ?? 'dashboard')));
$menu_valid = ['dashboard', 'kirim_surat', 'riwayat_surat', 'lacak_surat', 'arsip_surat', 'profil'];
if (!in_array($menu, $menu_valid, true)) {
    $menu = 'dashboard';
}

$pesan_profil_sukses = '';
$pesan_profil_error  = '';
$draft_profil_pg     = $_SESSION['draft_profil_pg'] ?? [];
$otp_aktif_pg        = $_SESSION['otp_profil_pg'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['kirim_kode_profil_pg'])) {
    $menu          = 'profil';
    $nama_baru     = trim((string)($_POST['nama_profil'] ?? ''));
    $email_baru    = trim((string)($_POST['email_profil'] ?? ''));
    $username_baru = trim((string)($_POST['username_profil'] ?? ''));
    $sandi_baru    = (string)($_POST['sandi_baru_profil'] ?? '');
    $sandi_konf    = (string)($_POST['sandi_konfirmasi_profil'] ?? '');

    if ($nama_baru === '') {
        $pesan_profil_error = 'Nama lengkap / nama pengirim wajib diisi.';
    } elseif ($kol_u_username !== '' && $username_baru === '') {
        $pesan_profil_error = 'Username login wajib diisi.';
    } elseif ($email_baru === '' || !filter_var($email_baru, FILTER_VALIDATE_EMAIL)) {
        $pesan_profil_error = 'Masukkan alamat email aktif yang valid untuk menerima kode persetujuan.';
    } elseif ($sandi_baru !== '' && strlen($sandi_baru) < 6) {
        $pesan_profil_error = 'Kata sandi baru minimal 6 karakter.';
    } elseif ($sandi_baru !== '' && $sandi_baru !== $sandi_konf) {
        $pesan_profil_error = 'Konfirmasi kata sandi baru tidak sama.';
    } else {
        $kode_otp = (string)random_int(100000, 999999);
        $draft_baru = [
            'nama'          => $nama_baru,
            'email'         => $email_baru,
            'username'      => $username_baru,
            'password_hash' => ($sandi_baru !== '') ? password_hash($sandi_baru, PASSWORD_DEFAULT) : '',
        ];
        $otp_baru = [
            'email'      => $email_baru,
            'kode'       => $kode_otp,
            'expires_at' => time() + 600,
        ];

        $_SESSION['draft_profil_pg'] = $draft_baru;
        $_SESSION['otp_profil_pg']   = $otp_baru;
        $draft_profil_pg = $draft_baru;
        $otp_aktif_pg    = $otp_baru;

        $hasil_kirim = kirim_otp_profil_pg($email_baru, $kode_otp, $nama_baru);
        if ($hasil_kirim['terkirim']) {
            $pesan_profil_sukses = 'Kode persetujuan telah dikirim ke email aktif ' . $email_baru . '. Silakan cek Inbox atau folder Spam, lalu masukkan kodenya.';
        } else {
            $detail_err = trim((string)($hasil_kirim['error'] ?? ''));
            $pesan_profil_error  = 'Gagal mengirim kode ke email ' . $email_baru . ($detail_err !== '' ? ' (' . $detail_err . ')' : '') . '.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['batal_kode_profil_pg'])) {
    $menu = 'profil';
    unset($_SESSION['draft_profil_pg'], $_SESSION['otp_profil_pg']);
    $draft_profil_pg     = [];
    $otp_aktif_pg        = null;
    $pesan_profil_sukses = 'Permintaan verifikasi email dibatalkan.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['simpan_profil_pg'])) {
    $menu       = 'profil';
    $kode_input = trim((string)($_POST['kode_persetujuan_email'] ?? ''));

    if (!is_array($otp_aktif_pg) || empty($draft_profil_pg)) {
        $pesan_profil_error = 'Pembaruan profil membutuhkan persetujuan email aktif. Isi email aktif lalu klik "Kirim Kode ke Email" terlebih dahulu.';
    } elseif ($kode_input === '') {
        $pesan_profil_error = 'Masukkan kode persetujuan yang dikirim ke email aktif Anda.';
    } elseif (time() > (int)($otp_aktif_pg['expires_at'] ?? 0)) {
        $pesan_profil_error = 'Kode persetujuan sudah kedaluwarsa. Klik "Kirim Kode ke Email" untuk meminta kode baru.';
    } elseif (!hash_equals((string)($otp_aktif_pg['kode'] ?? ''), $kode_input)) {
        $pesan_profil_error = 'Kode persetujuan email tidak sesuai. Periksa kembali kode di email aktif Anda.';
    } elseif (!isset($koneksi) || !($koneksi instanceof mysqli) || !$ada_tabel_users || empty($data_akun_pg)) {
        $pesan_profil_error = 'Data akun pengguna pada tabel users tidak ditemukan.';
    } else {
        $nama_baru     = (string)($draft_profil_pg['nama'] ?? '');
        $email_baru    = (string)($draft_profil_pg['email'] ?? '');
        $username_baru = (string)($draft_profil_pg['username'] ?? '');
        $hash_baru     = (string)($draft_profil_pg['password_hash'] ?? '');

        $id_target_u = (int)($data_akun_pg[$pk_users] ?? $id_login);
        $pk_u_esc    = mysqli_real_escape_string($koneksi, $pk_users);
        $set_u       = [];
        $bentrok_u   = false;

        if ($kol_u_username !== '' && $username_baru !== '') {
            $u_cek_esc = mysqli_real_escape_string($koneksi, strtolower(trim($username_baru)));
            $res_cek_u = mysqli_query($koneksi, "SELECT `$pk_u_esc` FROM `users` WHERE LOWER(TRIM(`$kol_u_username`)) = '$u_cek_esc' AND `$pk_u_esc` != $id_target_u LIMIT 1");
            if ($res_cek_u && mysqli_num_rows($res_cek_u) > 0) {
                $bentrok_u = true;
                $pesan_profil_error = 'Username "' . $username_baru . '" sudah digunakan oleh akun lain. Gunakan username yang berbeda.';
            }
        }

        if (!$bentrok_u) {
            if ($kol_u_nama !== '') {
                $set_u[] = "`$kol_u_nama` = '" . mysqli_real_escape_string($koneksi, $nama_baru) . "'";
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
                $pesan_profil_error = 'Tidak ada kolom profil yang dapat diperbarui.';
            } else {
                $sql_u = "UPDATE `users` SET " . implode(', ', $set_u) . " WHERE `$pk_u_esc` = $id_target_u LIMIT 1";
                try {
                    $eksekusi_u = mysqli_query($koneksi, $sql_u);
                } catch (mysqli_sql_exception $e) {
                    $eksekusi_u = false;
                    $pesan_profil_error = 'Gagal menyimpan perubahan profil: ' . $e->getMessage();
                }

                if ($eksekusi_u) {
                    $_SESSION['nama'] = $nama_baru;
                    if ($username_baru !== '') {
                        $_SESSION['username'] = $username_baru;
                        $user_login = $username_baru;
                    }
                    if ($email_baru !== '') {
                        $_SESSION['email'] = $email_baru;
                        $email_login = $email_baru;
                    }
                    $nama_tampil = $nama_baru;
                    $nama_label  = $nama_baru;

                    unset($_SESSION['draft_profil_pg'], $_SESSION['otp_profil_pg']);
                    $draft_profil_pg = [];
                    $otp_aktif_pg    = null;

                    $res_u_fresh = mysqli_query($koneksi, "SELECT * FROM `users` WHERE `$pk_u_esc` = $id_target_u LIMIT 1");
                    if ($res_u_fresh && ($r_fresh = mysqli_fetch_assoc($res_u_fresh))) {
                        $data_akun_pg = $r_fresh;
                        if ($kol_u_nama !== '' && !empty($data_akun_pg[$kol_u_nama])) {
                            $nama_tampil = trim((string)$data_akun_pg[$kol_u_nama]);
                            $nama_label  = $nama_tampil;
                        }
                        if ($kol_u_email !== '' && isset($data_akun_pg[$kol_u_email])) {
                            $email_login = trim((string)$data_akun_pg[$kol_u_email]);
                        }
                        if ($kol_u_username !== '' && isset($data_akun_pg[$kol_u_username])) {
                            $user_login = trim((string)$data_akun_pg[$kol_u_username]);
                        }
                    }

                    $pesan_profil_sukses = 'Persetujuan email berhasil diverifikasi. Profil dan keamanan akun Pengirim telah diperbarui.' . ($hash_baru !== '' ? ' Kata sandi baru juga aktif.' : '');
                } elseif ($pesan_profil_error === '') {
                    $pesan_profil_error = 'Gagal menyimpan perubahan profil: ' . mysqli_error($koneksi);
                }
            }
        }
    }
}

$val_nama_form     = (string)($draft_profil_pg['nama'] ?? $nama_label);
$val_username_form = (string)($draft_profil_pg['username'] ?? $user_login);
$val_email_form    = (string)($draft_profil_pg['email'] ?? $email_login);
$menunggu_otp_pg   = is_array($otp_aktif_pg) && !empty($draft_profil_pg);

function resolve_url_file_pg(string $raw_file): string {
    $bersih = trim(str_replace('\\', '/', $raw_file));
    if ($bersih === '') {
        return '';
    }
    $nama_saja = basename($bersih);
    $kandidat = [
        ltrim($bersih, '/'),
        '../' . ltrim($bersih, '/'),
        '../uploads/' . $nama_saja,
        'uploads/' . $nama_saja
    ];
    foreach ($kandidat as $rel) {
        $fs = __DIR__ . '/' . $rel;
        if (file_exists($fs) && is_file($fs)) {
            return $rel;
        }
    }
    return (strpos($bersih, '/') !== false) ? $bersih : ('../uploads/' . $nama_saja);
}

function cari_logo_sangihe_pg_dash(): string {
    $base_dir = __DIR__;
    $root_dir = dirname(__DIR__);

    $prioritas_rel = [
        'logo_sangihe.png',
        'logo_sangihe.jpg',
        'logo_sangihe.jpeg',
        'sangihe.png',
        '../logo_sangihe.png',
        '../logo_sangihe.jpg',
        '../logo_sangihe.jpeg',
        '../sangihe.png',
        'assets/logo_sangihe.png',
        '../assets/logo_sangihe.png',
        'assets/img/logo_sangihe.png',
        '../assets/img/logo_sangihe.png',
        '../assets/images/logo_sangihe.png',
        '../img/logo_sangihe.png',
        '../images/logo_sangihe.png',
        '../logo/logo_sangihe.png',
        '../assets/logo.png',
        '../img/logo.png',
        '../uploads/logo_sangihe.png',
    ];

    foreach ($prioritas_rel as $rel) {
        $fs = $base_dir . '/' . $rel;
        if (file_exists($fs) && is_file($fs)) {
            return $rel;
        }
    }

    $scan_dirs = [
        $base_dir,
        $base_dir . '/assets',
        $base_dir . '/assets/img',
        $base_dir . '/img',
        $root_dir,
        $root_dir . '/assets',
        $root_dir . '/assets/img',
        $root_dir . '/assets/images',
        $root_dir . '/img',
        $root_dir . '/images',
        $root_dir . '/logo',
    ];

    $kandidat = [];
    foreach ($scan_dirs as $dir) {
        if (!is_dir($dir)) {
            continue;
        }
        $files = @scandir($dir);
        if (!is_array($files)) {
            continue;
        }
        foreach ($files as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $full = $dir . '/' . $f;
            if (!is_file($full)) {
                continue;
            }
            $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
            if (!in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'gif', 'svg'], true)) {
                continue;
            }
            $fn = strtolower($f);
            if (str_contains($fn, 'sangihe') || str_contains($fn, 'logo') || str_contains($fn, 'kominfo') || str_contains($fn, 'pemda')) {
                $score = (str_contains($fn, 'sangihe') ? 10 : 0) + (str_contains($fn, 'logo') ? 5 : 0);
                $norm_full = str_replace('\\', '/', $full);
                $norm_base = str_replace('\\', '/', $base_dir);
                if (str_starts_with($norm_full, $norm_base . '/')) {
                    $rel_web = substr($norm_full, strlen($norm_base) + 1);
                } else {
                    $rel_web = '../' . ltrim(str_replace(str_replace('\\', '/', $root_dir), '', $norm_full), '/');
                }
                $kandidat[] = ['score' => $score, 'path' => $rel_web];
            }
        }
    }

    if (!empty($kandidat)) {
        usort($kandidat, fn($a, $b) => $b['score'] <=> $a['score']);
        return $kandidat[0]['path'];
    }

    return '';
}

$url_logo = cari_logo_sangihe_pg_dash();

$kol_no_surat  = '';
$kol_tgl_surat = '';
$kol_tgl_kirim = '';
$kol_perihal   = '';
$kol_sifat     = '';
$kol_status    = '';
$kol_file      = '';
$kol_tujuan    = '';

$pesan_kirim = '';
$tipe_pesan_kirim = 'success';

// [LETAK 2] Simpan user_id saat mengirim surat baru
if (isset($_POST['kirim_surat_pengirim']) && isset($koneksi) && $koneksi instanceof mysqli) {
    $no_sr   = trim((string)($_POST['no_surat'] ?? ''));
    $tgl_sr  = trim((string)($_POST['tanggal_surat'] ?? date('Y-m-d')));
    $asal_in = trim((string)($_POST['asal_instansi'] ?? $nama_label));
    $sifat_s = trim((string)($_POST['sifat_surat'] ?? 'Biasa'));
    $hal_s   = trim((string)($_POST['perihal'] ?? ''));

    $k_no_sr   = cari_kolom_pg_dash($koneksi, 'surat_masuk', ['no_surat', 'nomor_surat']);
    $k_tgl_sr  = cari_kolom_pg_dash($koneksi, 'surat_masuk', ['tanggal_surat', 'tgl_surat']);
    $k_tgl_in  = cari_kolom_pg_dash($koneksi, 'surat_masuk', ['tanggal_input', 'tanggal_masuk', 'tgl_masuk']);
    $k_asal    = cari_kolom_pg_dash($koneksi, 'surat_masuk', ['asal_instansi', 'asal_surat', 'instansi_pengirim', 'pengirim']);
    $k_sifat   = cari_kolom_pg_dash($koneksi, 'surat_masuk', ['sifat_surat', 'sifat']);
    $k_hal     = cari_kolom_pg_dash($koneksi, 'surat_masuk', ['perihal', 'subjek', 'hal']);
    $k_file    = cari_kolom_pg_dash($koneksi, 'surat_masuk', ['file_surat', 'lampiran', 'berkas_surat', 'dokumen_surat']);
    $k_status  = cari_kolom_pg_dash($koneksi, 'surat_masuk', ['status_surat', 'status']);
    $k_agenda  = cari_kolom_pg_dash($koneksi, 'surat_masuk', ['no_agenda', 'nomor_agenda']);
    $k_id_peng = cari_kolom_pg_dash($koneksi, 'surat_masuk', ['user_id', 'id_user', 'id_pengirim', 'pengirim_id']);

    $nama_file_upload = '';
    if (!empty($_FILES['file_surat']['name']) && (int)($_FILES['file_surat']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $ext = strtolower((string)pathinfo((string)$_FILES['file_surat']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['pdf', 'jpg', 'jpeg', 'png'], true)) {
            $dir_up = __DIR__ . '/../uploads';
            if (!is_dir($dir_up)) {
                @mkdir($dir_up, 0775, true);
            }
            $nama_baru = 'SURAT_' . time() . '_' . mt_rand(100, 999) . '.' . $ext;
            if (move_uploaded_file((string)$_FILES['file_surat']['tmp_name'], $dir_up . '/' . $nama_baru)) {
                $nama_file_upload = $nama_baru;
            }
        }
    }

    $q_max = mysqli_query($koneksi, "SELECT MAX(`id`) AS max_id FROM `surat_masuk`");
    $d_max = $q_max ? mysqli_fetch_assoc($q_max) : null;
    $next_no = (int)($d_max['max_id'] ?? 0) + 1;
    $no_ag_baru = 'AGD-' . str_pad((string)$next_no, 3, '0', STR_PAD_LEFT);

    $cols = [];
    $vals = [];

    if ($k_agenda !== '') { $cols[] = "`$k_agenda`"; $vals[] = "'" . mysqli_real_escape_string($koneksi, $no_ag_baru) . "'"; }
    if ($k_tgl_in !== '') { $cols[] = "`$k_tgl_in`"; $vals[] = "'" . mysqli_real_escape_string($koneksi, date('Y-m-d')) . "'"; }
    if ($k_no_sr !== '')  { $cols[] = "`$k_no_sr`";  $vals[] = "'" . mysqli_real_escape_string($koneksi, $no_sr) . "'"; }
    if ($k_tgl_sr !== '') { $cols[] = "`$k_tgl_sr`"; $vals[] = "'" . mysqli_real_escape_string($koneksi, $tgl_sr) . "'"; }
    if ($k_asal !== '')   { $cols[] = "`$k_asal`";   $vals[] = "'" . mysqli_real_escape_string($koneksi, $asal_in) . "'"; }
    if ($k_sifat !== '')  { $cols[] = "`$k_sifat`";  $vals[] = "'" . mysqli_real_escape_string($koneksi, $sifat_s) . "'"; }
    if ($k_hal !== '')    { $cols[] = "`$k_hal`";    $vals[] = "'" . mysqli_real_escape_string($koneksi, $hal_s) . "'"; }
    if ($k_file !== '')   { $cols[] = "`$k_file`";   $vals[] = "'" . mysqli_real_escape_string($koneksi, $nama_file_upload) . "'"; }
    if ($k_status !== '') { $cols[] = "`$k_status`"; $vals[] = "'Masuk'"; }
    if ($k_id_peng !== '' && $id_login > 0) {
        $cols[] = "`$k_id_peng`";
        $vals[] = (int)$id_login;
    }

    if (!empty($cols) && mysqli_query($koneksi, "INSERT INTO `surat_masuk` (" . implode(", ", $cols) . ") VALUES (" . implode(", ", $vals) . ")")) {
        $pesan_kirim = "Surat berhasil dikirim dan tercatat di sistem.";
        $tipe_pesan_kirim = 'success';
    } else {
        $pesan_kirim = "Gagal mengirim surat. Silakan periksa kembali data atau struktur tabel.";
        $tipe_pesan_kirim = 'danger';
    }
}

$surat_saya     = [];
$arsip_saya     = [];
$total_terkirim = 0;
$total_proses   = 0;
$total_selesai  = 0;

// [LETAK 3] Filter ketat berdasarkan user_id akun yang sedang login
if (isset($koneksi) && $koneksi instanceof mysqli) {
    $cek_sm = mysqli_query($koneksi, "SHOW TABLES LIKE 'surat_masuk'");
    if ($cek_sm && mysqli_num_rows($cek_sm) > 0) {
        $kol_no_surat  = cari_kolom_pg_dash($koneksi, 'surat_masuk', ['no_surat', 'nomor_surat']);
        $kol_tgl_surat = cari_kolom_pg_dash($koneksi, 'surat_masuk', ['tanggal_surat', 'tgl_surat']);
        $kol_tgl_kirim = cari_kolom_pg_dash($koneksi, 'surat_masuk', ['tanggal_input', 'tanggal_masuk', 'tgl_masuk', 'created_at']);
        $kol_perihal   = cari_kolom_pg_dash($koneksi, 'surat_masuk', ['perihal', 'subjek', 'hal']);
        $kol_sifat     = cari_kolom_pg_dash($koneksi, 'surat_masuk', ['sifat_surat', 'sifat']);
        $kol_status    = cari_kolom_pg_dash($koneksi, 'surat_masuk', ['status_surat', 'status']);
        $kol_file      = cari_kolom_pg_dash($koneksi, 'surat_masuk', ['file_surat', 'lampiran', 'berkas_surat', 'dokumen_surat']);
        $kol_tujuan    = cari_kolom_pg_dash($koneksi, 'surat_masuk', ['disposisi_kepada', 'tujuan_disposisi', 'tujuan_bidang']);

        $kol_id_pengirim = cari_kolom_pg_dash($koneksi, 'surat_masuk', ['user_id', 'id_user', 'id_pengirim', 'pengirim_id']);

        if ($kol_id_pengirim !== '' && $id_login > 0) {
            $sql_where = "WHERE `$kol_id_pengirim` = " . (int)$id_login;
        } else {
            $sql_where = "WHERE 1 = 0";
        }

        $order_by = kolom_ada_pg_dash($koneksi, 'surat_masuk', 'id') ? "ORDER BY `id` DESC" : "";

        $res_s = mysqli_query($koneksi, "SELECT * FROM `surat_masuk` $sql_where $order_by");
        if ($res_s) {
            while ($row = mysqli_fetch_assoc($res_s)) {
                $surat_saya[] = $row;
                $total_terkirim++;
                $st = strtolower(trim((string)($kol_status !== '' ? ($row[$kol_status] ?? '') : '')));
                if (in_array($st, ['selesai', 'diterima', 'diarsipkan', 'arsip'], true)) {
                    $total_selesai++;
                    $arsip_saya[] = $row;
                } elseif (in_array($st, ['di proses', 'diproses', 'sedang diproses', 'verifikasi', 'terverifikasi', 'disposisi', 'didisposisikan'], true)) {
                    $total_proses++;
                }
            }
        }
    }
}

$link_kirim_surat   = file_exists(__DIR__ . '/kirim_surat.php') ? 'kirim_surat.php' : 'dashboard.php?menu=kirim_surat';
$link_riwayat_surat = file_exists(__DIR__ . '/riwayat_surat.php') ? 'riwayat_surat.php' : 'dashboard.php?menu=riwayat_surat';
$link_lacak_surat   = file_exists(__DIR__ . '/lacak_surat.php') ? 'lacak_surat.php' : 'dashboard.php?menu=lacak_surat';
$link_arsip_surat   = file_exists(__DIR__ . '/arsip_surat.php') ? 'arsip_surat.php' : 'dashboard.php?menu=arsip_surat';
$link_profil_surat  = 'dashboard.php?menu=profil';
$link_logout        = file_exists(__DIR__ . '/logout.php') ? 'logout.php' : '../logout.php';
$sub_akun           = $user_login !== '' ? $user_login : ($email_login !== '' ? $email_login : 'pengirim');
$kata_lacak         = trim((string)($_GET['q'] ?? ''));
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Pengirim Surat - E-OFFICE</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            background-color: #eef2f7;
            color: #1e293b;
            font-family: 'Inter', sans-serif;
            margin: 0;
        }
        .sidebar-pg {
            width: 260px;
            min-height: 100vh;
            background-color: #ffffff;
            color: #0f172a;
            border-right: 1px solid #e2e8f0;
            position: fixed;
            top: 0;
            left: 0;
            padding: 20px 16px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            z-index: 100;
        }
        .brand-logo-box {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            padding: 6px 6px 14px;
            margin-bottom: 10px;
        }
        .brand-logo-box img {
            width: 100%;
            max-width: 224px;
            height: auto;
            max-height: 108px;
            object-fit: contain;
            display: block;
            background-color: transparent;
        }
        .sidebar-pg .nav-link {
            color: #334155;
            border-radius: 10px;
            padding: 11px 16px;
            margin-bottom: 6px;
            font-size: 0.95rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
        }
        .sidebar-pg .nav-link:hover {
            background-color: #f1f5f9;
            color: #0f172a;
        }
        .sidebar-pg .nav-link.active {
            background-color: #3b6998;
            color: #ffffff;
        }
        .main-pg {
            margin-left: 260px;
            padding: 28px 32px;
            background-color: #eef2f7;
            min-height: 100vh;
        }
        .header-banner-pg {
            background-color: #3b6998;
            border: 1px solid #2f5780;
            color: #ffffff;
            border-radius: 14px;
            padding: 20px 26px;
            margin-bottom: 16px;
            box-shadow: 0 3px 10px rgba(30, 58, 95, 0.14);
        }
        .badge-pengirim-pg {
            background-color: #ffffff;
            color: #0f172a;
            border-radius: 999px;
            padding: 7px 14px;
            font-size: 0.82rem;
            font-weight: 700;
            letter-spacing: 0.03em;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .welcome-card-pg {
            background-color: #3b6998;
            border: 1px solid #2f5780;
            color: #ffffff;
            border-radius: 14px;
            padding: 22px 26px;
            margin-bottom: 24px;
            box-shadow: 0 3px 10px rgba(30, 58, 95, 0.14);
        }
        .stat-box-pg {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background-color: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 22px;
            box-shadow: 0 2px 10px rgba(15, 23, 42, 0.04);
            height: 100%;
            text-decoration: none;
            color: #0f172a;
            transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease;
            cursor: pointer;
        }
        .stat-box-pg:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 18px rgba(30, 58, 95, 0.12);
            border-color: #3b6998;
            color: #0f172a;
        }
        .stat-box-pg:active {
            transform: translateY(1px) scale(0.99);
            box-shadow: 0 2px 6px rgba(30, 58, 95, 0.1);
        }
        .icon-sq-pg {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.15rem;
            flex-shrink: 0;
        }
        .card-panel-pg {
            background-color: #4372a1;
            border: 1px solid #355f8a;
            border-radius: 14px;
            padding: 24px;
            box-shadow: 0 3px 10px rgba(30, 58, 95, 0.14);
            margin-bottom: 20px;
            color: #ffffff;
        }
        .card-panel-pg .text-muted {
            color: #e2e8f0 !important;
        }
        .kartu-profil-putih-pg {
            background-color: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 24px;
            box-shadow: 0 3px 10px rgba(15, 23, 42, 0.06);
            margin-bottom: 20px;
            color: #1e293b;
        }
        .avatar-profil-pg {
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
        .sandi-wrapper-pg {
            position: relative;
        }
        .sandi-wrapper-pg .form-control {
            padding-right: 42px;
        }
        .btn-toggle-sandi-pg {
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
        .btn-toggle-sandi-pg:hover {
            color: #0d6efd;
            background-color: rgba(13, 110, 253, 0.08);
        }
        .card-panel-pg .table {
            --bs-table-bg: #f8fafc;
            --bs-table-color: #0f172a;
            --bs-table-border-color: #cbd5e1;
            --bs-table-hover-bg: #ffffff;
            border-radius: 10px;
            overflow: hidden;
        }
        .card-panel-pg .table thead.table-dark th {
            background-color: #3b6998;
            color: #ffffff;
            border-color: #2f5780;
        }
        @media (max-width: 768px) {
            .sidebar-pg {
                position: relative;
                width: 100%;
                min-height: auto;
                border-right: none;
                border-bottom: 1px solid #e2e8f0;
            }
            .main-pg {
                margin-left: 0;
                padding: 18px;
            }
        }
    </style>
</head>
<body>
    <aside class="sidebar-pg">
        <div>
            <div class="brand-logo-box">
                <?php if ($url_logo !== ''): ?>
                    <img src="<?= htmlspecialchars($url_logo, ENT_QUOTES, 'UTF-8'); ?>" alt="Logo Diskominfo Sangihe">
                <?php else: ?>
                    <div class="fw-bold text-primary"><i class="bi bi-envelope-fill me-1"></i> Diskominfo Sangihe</div>
                <?php endif; ?>
            </div>

            <nav class="nav flex-column">
                <a href="dashboard.php?menu=dashboard" class="nav-link <?= $menu === 'dashboard' ? 'active' : ''; ?>">
                    <i class="bi bi-speedometer2"></i>
                    <span>Dashboard</span>
                </a>
                <a href="<?= htmlspecialchars($link_kirim_surat, ENT_QUOTES, 'UTF-8'); ?>" class="nav-link <?= $menu === 'kirim_surat' ? 'active' : ''; ?>">
                    <i class="bi bi-send"></i>
                    <span>Kirim Surat Baru</span>
                </a>
                <a href="<?= htmlspecialchars($link_riwayat_surat, ENT_QUOTES, 'UTF-8'); ?>" class="nav-link <?= $menu === 'riwayat_surat' ? 'active' : ''; ?>">
                    <i class="bi bi-clock-history"></i>
                    <span>Riwayat Surat Saya</span>
                </a>
                <a href="<?= htmlspecialchars($link_lacak_surat, ENT_QUOTES, 'UTF-8'); ?>" class="nav-link <?= $menu === 'lacak_surat' ? 'active' : ''; ?>">
                    <i class="bi bi-search"></i>
                    <span>Lacak Surat</span>
                </a>
                <a href="<?= htmlspecialchars($link_arsip_surat, ENT_QUOTES, 'UTF-8'); ?>" class="nav-link <?= $menu === 'arsip_surat' ? 'active' : ''; ?>">
                    <i class="bi bi-archive"></i>
                    <span>Arsip Surat</span>
                </a>
                <a href="<?= htmlspecialchars($link_profil_surat, ENT_QUOTES, 'UTF-8'); ?>" class="nav-link <?= $menu === 'profil' ? 'active' : ''; ?>">
                    <i class="bi bi-gear"></i>
                    <span>Pengaturan Profil</span>
                </a>
            </nav>
        </div>

        <div class="pt-3 border-top">
            <a href="<?= htmlspecialchars($link_logout, ENT_QUOTES, 'UTF-8'); ?>" class="nav-link text-danger mb-0">
                <i class="bi bi-box-arrow-right"></i>
                <span>Keluar</span>
            </a>
        </div>
    </aside>

    <!-- [LETAK 4] Bagian tampilan utama & kartu statistik -->
    <main class="main-pg">
        <?php if ($menu === 'dashboard'): ?>
            <div class="header-banner-pg d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <h4 class="fw-bold mb-1 text-white">Dashboard Pengirim</h4>
                    <div class="small text-white-50">Kirim surat masuk dan pantau status administrasi persuratan</div>
                </div>
                <span class="badge-pengirim-pg">
                    <i class="bi bi-person-badge-fill"></i>
                    <span>PENGIRIM &bull; <?= htmlspecialchars(strtoupper($nama_label), ENT_QUOTES, 'UTF-8'); ?></span>
                </span>
            </div>
        <?php elseif ($menu === 'profil'): ?>
            <div class="header-banner-pg d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <h4 class="fw-bold mb-1 text-white">Pengaturan Profil &mdash; Pengirim</h4>
                    <div class="small text-white-50">Kelola informasi akun dan keamanan kata sandi Pengirim</div>
                </div>
                <span class="badge-pengirim-pg">
                    <i class="bi bi-person-badge-fill"></i>
                    <span>PENGIRIM &bull; <?= htmlspecialchars(strtoupper($nama_label), ENT_QUOTES, 'UTF-8'); ?></span>
                </span>
            </div>
        <?php endif; ?>

        <?php if ($menu !== 'profil'): ?>
            <div class="welcome-card-pg">
                <h4 class="fw-bold mb-1 text-white">Hallo 👋, <?= htmlspecialchars($nama_label, ENT_QUOTES, 'UTF-8'); ?></h4>
                <div class="small text-white-50">
                    Akun pengirim: <strong class="text-white"><?= htmlspecialchars($sub_akun, ENT_QUOTES, 'UTF-8'); ?></strong> &bull; Total surat terkirim: <strong class="text-white"><?= (int)$total_terkirim; ?></strong>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($pesan_profil_sukses !== ''): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($pesan_profil_sukses, ENT_QUOTES, 'UTF-8'); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        <?php if ($pesan_profil_error !== ''): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($pesan_profil_error, ENT_QUOTES, 'UTF-8'); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($pesan_kirim !== ''): ?>
            <div class="alert alert-<?= htmlspecialchars($tipe_pesan_kirim, ENT_QUOTES, 'UTF-8'); ?> alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($pesan_kirim, ENT_QUOTES, 'UTF-8'); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($menu === 'dashboard'): ?>
            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <a href="<?= htmlspecialchars($link_riwayat_surat, ENT_QUOTES, 'UTF-8'); ?>" class="stat-box-pg">
                        <div>
                            <div class="text-muted small fw-semibold">SURAT TERKIRIM</div>
                            <h3 class="fw-bold mb-0 mt-1"><?= (int)$total_terkirim; ?></h3>
                        </div>
                        <div class="icon-sq-pg bg-primary bg-opacity-10 text-primary"><i class="bi bi-send-check"></i></div>
                    </a>
                </div>
                <div class="col-md-4">
                    <a href="<?= htmlspecialchars($link_lacak_surat, ENT_QUOTES, 'UTF-8'); ?>" class="stat-box-pg">
                        <div>
                            <div class="text-muted small fw-semibold">SEDANG DI PROSES</div>
                            <h3 class="fw-bold mb-0 mt-1"><?= (int)$total_proses; ?></h3>
                        </div>
                        <div class="icon-sq-pg text-dark" style="background-color: rgba(245, 158, 11, 0.18);"><i class="bi bi-arrow-repeat"></i></div>
                    </a>
                </div>
                <div class="col-md-4">
                    <a href="<?= htmlspecialchars($link_arsip_surat, ENT_QUOTES, 'UTF-8'); ?>" class="stat-box-pg">
                        <div>
                            <div class="text-muted small fw-semibold">SURAT SELESAI / ARSIP</div>
                            <h3 class="fw-bold mb-0 mt-1"><?= (int)$total_selesai; ?></h3>
                        </div>
                        <div class="icon-sq-pg bg-success bg-opacity-10 text-success"><i class="bi bi-folder-check"></i></div>
                    </a>
                </div>
            </div>

        <?php elseif ($menu === 'profil'): ?>
            <div class="row g-4">
                <div class="col-lg-4">
                    <div class="kartu-profil-putih-pg h-100">
                        <div class="d-flex align-items-center gap-3 mb-3">
                            <div class="avatar-profil-pg">
                                <i class="bi bi-person-badge"></i>
                            </div>
                            <div>
                                <h6 class="fw-bold mb-1 text-dark"><?= htmlspecialchars($nama_label, ENT_QUOTES, 'UTF-8'); ?></h6>
                                <span class="badge bg-primary">Pengirim</span>
                            </div>
                        </div>
                        <hr>
                        <div class="small mb-2">
                            <div class="text-muted">Peran / Role</div>
                            <div class="fw-semibold text-dark">Pengirim Surat</div>
                        </div>
                        <div class="small mb-2">
                            <div class="text-muted">Username Akun</div>
                            <div class="fw-semibold text-dark"><?= htmlspecialchars($user_login !== '' ? $user_login : '-', ENT_QUOTES, 'UTF-8'); ?></div>
                        </div>
                        <div class="small">
                            <div class="text-muted">Email Aktif</div>
                            <div class="fw-semibold text-dark"><?= htmlspecialchars($email_login !== '' ? $email_login : '-', ENT_QUOTES, 'UTF-8'); ?></div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-8">
                    <div class="kartu-profil-putih-pg">
                        <h6 class="fw-bold mb-2 text-dark"><i class="bi bi-shield-check me-2 text-primary"></i>Perbarui Data Profil &amp; Keamanan Akun (Persetujuan Email Aktif)</h6>
                        <p class="small text-muted mb-3">Setiap pembaruan profil atau kata sandi memerlukan kode persetujuan yang dikirim ke <strong>email aktif</strong> yang Anda isikan.</p>

                        <form method="POST" action="dashboard.php?menu=profil" autocomplete="off">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold text-dark">Nama Lengkap / Nama Pengirim</label>
                                    <input type="text" name="nama_profil" class="form-control" value="<?= htmlspecialchars($val_nama_form, ENT_QUOTES, 'UTF-8'); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold text-dark">Username Login</label>
                                    <input type="text" name="username_profil" class="form-control" value="<?= htmlspecialchars($val_username_form, ENT_QUOTES, 'UTF-8'); ?>" required>
                                </div>
                                <div class="col-md-12">
                                    <label class="form-label small fw-semibold text-dark">Email Aktif (Wajib untuk Persetujuan)</label>
                                    <input type="email" name="email_profil" class="form-control" value="<?= htmlspecialchars($val_email_form, ENT_QUOTES, 'UTF-8'); ?>" placeholder="nama@emailaktif.com" required>
                                </div>

                                <div class="col-12"><hr class="my-1"><div class="small fw-bold text-muted">Keamanan Akun — Ganti Kata Sandi (Opsional)</div></div>

                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold text-dark">Kata Sandi Baru</label>
                                    <div class="sandi-wrapper-pg">
                                        <input type="password" id="sandiBaruProfilPg" name="sandi_baru_profil" class="form-control" placeholder="Minimal 6 karakter..." minlength="6" autocomplete="new-password">
                                        <button type="button" class="btn-toggle-sandi-pg toggle-sandi-pg" data-target="sandiBaruProfilPg" aria-label="Tampilkan sandi" title="Tampilkan sandi">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold text-dark">Konfirmasi Kata Sandi Baru</label>
                                    <div class="sandi-wrapper-pg">
                                        <input type="password" id="sandiKonfirmasiProfilPg" name="sandi_konfirmasi_profil" class="form-control" placeholder="Ulangi kata sandi baru..." minlength="6" autocomplete="new-password">
                                        <button type="button" class="btn-toggle-sandi-pg toggle-sandi-pg" data-target="sandiKonfirmasiProfilPg" aria-label="Tampilkan sandi" title="Tampilkan sandi">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                    </div>
                                </div>

                                <div class="col-12"><hr class="my-1"><div class="small fw-bold text-primary">Persetujuan Melalui Email Aktif</div></div>

                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold text-dark">Kode Persetujuan (6 Digit dari Email)</label>
                                    <input type="text" name="kode_persetujuan_email" class="form-control" maxlength="6" pattern="[0-9]{6}" placeholder="Contoh: 123456" autocomplete="one-time-code">
                                    <div class="form-text">
                                        <?php if ($menunggu_otp_pg): ?>
                                            Kode dikirim ke <strong><?= htmlspecialchars((string)$otp_aktif_pg['email'], ENT_QUOTES, 'UTF-8'); ?></strong>.
                                        <?php else: ?>
                                            Klik <strong>Kirim Kode ke Email</strong> terlebih dahulu.
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="col-md-6 d-flex align-items-start pt-md-4 gap-2 flex-wrap">
                                    <button type="submit" name="kirim_kode_profil_pg" value="1" formnovalidate class="btn btn-outline-primary fw-semibold">
                                        <i class="bi bi-envelope-check me-1"></i> <?= $menunggu_otp_pg ? 'Kirim Ulang Kode' : 'Kirim Kode ke Email'; ?>
                                    </button>
                                    <?php if ($menunggu_otp_pg): ?>
                                        <button type="submit" name="batal_kode_profil_pg" value="1" formnovalidate class="btn btn-outline-danger btn-sm mt-1">
                                            Batalkan
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="mt-4 d-flex justify-content-end gap-2">
                                <a href="dashboard.php?menu=dashboard" class="btn btn-outline-secondary">Kembali ke Dashboard</a>
                                <button type="submit" name="simpan_profil_pg" value="1" class="btn btn-primary fw-semibold px-4">
                                    <i class="bi bi-check2-circle me-1"></i> Verifikasi &amp; Simpan Profil
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

        <?php elseif ($menu === 'kirim_surat'): ?>
            <div class="card-panel-pg">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                    <div>
                        <h5 class="fw-bold mb-1 text-white">Kirim Surat Baru</h5>
                        <div class="text-muted small">Isi data surat dan unggah dokumen (PDF/gambar) untuk dikirim ke Sekretariat.</div>
                    </div>
                    <a href="dashboard.php?menu=dashboard" class="btn btn-sm btn-light text-dark fw-semibold">Kembali ke Dashboard</a>
                </div>

                <form action="dashboard.php?menu=kirim_surat" method="POST" enctype="multipart/form-data">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold text-white">Nomor Surat</label>
                            <input type="text" name="no_surat" class="form-control" placeholder="Contoh: 800/BKPSDM/022/2026" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold text-white">Tanggal Surat</label>
                            <input type="date" name="tanggal_surat" class="form-control" value="<?= date('Y-m-d'); ?>" required>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold text-white">Asal Instansi / Pengirim</label>
                            <input type="text" name="asal_instansi" class="form-control" value="<?= htmlspecialchars($nama_label, ENT_QUOTES, 'UTF-8'); ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold text-white">Sifat Surat</label>
                            <select name="sifat_surat" class="form-select" required>
                                <option value="Biasa">Biasa</option>
                                <option value="Penting">Penting</option>
                                <option value="Segera">Segera</option>
                                <option value="Rahasia">Rahasia</option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold text-white">Perihal / Isi Ringkas Surat</label>
                        <textarea name="perihal" class="form-control" rows="3" required></textarea>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-semibold text-white">Unggah Dokumen Surat (PDF / JPG / PNG)</label>
                        <input type="file" name="file_surat" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
                    </div>

                    <button type="submit" name="kirim_surat_pengirim" class="btn btn-light text-dark fw-semibold px-4">
                        <i class="bi bi-send me-1"></i> Kirim Surat
                    </button>
                </form>
            </div>

        <?php elseif ($menu === 'riwayat_surat'): ?>
            <div class="card-panel-pg">
                <h5 class="fw-bold mb-3 text-white">Riwayat Surat Saya</h5>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th>No</th>
                                <th>No. Surat</th>
                                <th>Tanggal Surat</th>
                                <th>Perihal</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($surat_saya)): ?>
                                <?php foreach ($surat_saya as $i => $sr): ?>
                                    <tr>
                                        <td><?= $i + 1; ?></td>
                                        <td><?= htmlspecialchars((string)($kol_no_surat !== '' ? ($sr[$kol_no_surat] ?? '-') : '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?= htmlspecialchars((string)($kol_tgl_surat !== '' ? ($sr[$kol_tgl_surat] ?? '-') : '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?= htmlspecialchars((string)($kol_perihal !== '' ? ($sr[$kol_perihal] ?? '-') : '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><span class="badge bg-secondary"><?= htmlspecialchars((string)($kol_status !== '' ? ($sr[$kol_status] ?? 'Masuk') : 'Masuk'), ENT_QUOTES, 'UTF-8'); ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="text-center py-4">Belum ada surat yang dikirim oleh akun ini.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        <?php elseif ($menu === 'lacak_surat'): ?>
            <div class="card-panel-pg">
                <h5 class="fw-bold mb-3 text-white">Lacak Status Surat</h5>
                <form method="GET" action="dashboard.php" class="row g-2 mb-3">
                    <input type="hidden" name="menu" value="lacak_surat">
                    <div class="col-md-9">
                        <input type="text" name="q" class="form-control" placeholder="Cari nomor surat atau perihal..." value="<?= htmlspecialchars($kata_lacak, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="col-md-3">
                        <button type="submit" class="btn btn-light text-dark fw-semibold w-100">Cari Surat</button>
                    </div>
                </form>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th>No. Surat</th>
                                <th>Perihal</th>
                                <th>Status Terkini</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $hasil_lacak = array_filter($surat_saya, function ($sr) use ($kata_lacak, $kol_no_surat, $kol_perihal) {
                                if ($kata_lacak === '') return true;
                                $ns = strtolower((string)($kol_no_surat !== '' ? ($sr[$kol_no_surat] ?? '') : ''));
                                $ph = strtolower((string)($kol_perihal !== '' ? ($sr[$kol_perihal] ?? '') : ''));
                                $k  = strtolower($kata_lacak);
                                return str_contains($ns, $k) || str_contains($ph, $k);
                            });
                            ?>
                            <?php if (!empty($hasil_lacak)): ?>
                                <?php foreach ($hasil_lacak as $sr): ?>
                                    <tr>
                                        <td><?= htmlspecialchars((string)($kol_no_surat !== '' ? ($sr[$kol_no_surat] ?? '-') : '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?= htmlspecialchars((string)($kol_perihal !== '' ? ($sr[$kol_perihal] ?? '-') : '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><span class="badge bg-info text-dark"><?= htmlspecialchars((string)($kol_status !== '' ? ($sr[$kol_status] ?? 'Masuk') : 'Masuk'), ENT_QUOTES, 'UTF-8'); ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="3" class="text-center py-4">Tidak ada data surat yang sesuai.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        <?php elseif ($menu === 'arsip_surat'): ?>
            <div class="card-panel-pg">
                <h5 class="fw-bold mb-3 text-white">Arsip Surat Selesai</h5>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th>No. Surat</th>
                                <th>Tanggal Surat</th>
                                <th>Perihal</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($arsip_saya)): ?>
                                <?php foreach ($arsip_saya as $sr): ?>
                                    <tr>
                                        <td><?= htmlspecialchars((string)($kol_no_surat !== '' ? ($sr[$kol_no_surat] ?? '-') : '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?= htmlspecialchars((string)($kol_tgl_surat !== '' ? ($sr[$kol_tgl_surat] ?? '-') : '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?= htmlspecialchars((string)($kol_perihal !== '' ? ($sr[$kol_perihal] ?? '-') : '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><span class="badge bg-success"><?= htmlspecialchars((string)($kol_status !== '' ? ($sr[$kol_status] ?? 'Selesai') : 'Selesai'), ENT_QUOTES, 'UTF-8'); ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="4" class="text-center py-4">Belum ada surat yang selesai atau diarsipkan.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.querySelectorAll('.toggle-sandi-pg').forEach(function (btn) {
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