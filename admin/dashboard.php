<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include '../koneksi.php';

$ada_sesi_login = isset($_SESSION['user'])
    || isset($_SESSION['id_user'])
    || isset($_SESSION['user_id'])
    || isset($_SESSION['id'])
    || isset($_SESSION['username']);

if (!$ada_sesi_login) {
    header("Location: ../login.php");
    exit();
}

$role_session = strtolower(str_replace([' ', '-'], '_', trim((string)(
    $_SESSION['role'] ?? ($_SESSION['level'] ?? (is_array($_SESSION['user'] ?? null) ? ($_SESSION['user']['role'] ?? ($_SESSION['user']['level'] ?? '')) : ''))
))));

if ($role_session !== 'admin') {
    $tujuan_aman = function_exists('url_dashboard_sesuai_user') ? url_dashboard_sesuai_user('../') : '../login.php';
    $tujuan_js = htmlspecialchars($tujuan_aman, ENT_QUOTES, 'UTF-8');
    echo "<script>alert('Akses ditolak! Halaman ini khusus untuk Administrator Sistem.'); window.location='{$tujuan_js}';</script>";
    exit();
}

function kolom_ada_adm(mysqli $koneksi, string $tabel, string $kolom): bool {
    $t = mysqli_real_escape_string($koneksi, $tabel);
    $k = mysqli_real_escape_string($koneksi, $kolom);
    $res = mysqli_query($koneksi, "SHOW COLUMNS FROM `$t` LIKE '$k'");
    return ($res && mysqli_num_rows($res) > 0);
}

function tabel_ada_adm(mysqli $koneksi, string $tabel): bool {
    $t = mysqli_real_escape_string($koneksi, $tabel);
    $res = mysqli_query($koneksi, "SHOW TABLES LIKE '$t'");
    return ($res && mysqli_num_rows($res) > 0);
}

function cari_kolom_adm(mysqli $koneksi, string $tabel, array $kandidat): string {
    foreach ($kandidat as $kolom) {
        if (kolom_ada_adm($koneksi, $tabel, $kolom)) {
            return $kolom;
        }
    }
    return '';
}

function deteksi_pk_adm(mysqli $koneksi, string $tabel, string $fallback = 'id'): string {
    $t = mysqli_real_escape_string($koneksi, $tabel);
    $res = mysqli_query($koneksi, "SHOW KEYS FROM `$t` WHERE Key_name = 'PRIMARY'");
    if ($res && ($row = mysqli_fetch_assoc($res)) && !empty($row['Column_name'])) {
        return (string)$row['Column_name'];
    }
    return $fallback;
}

function pastikan_kolom_email_ganti_sandi(mysqli $koneksi, string $tabel = 'users'): string {
    if (!tabel_ada_adm($koneksi, $tabel)) {
        return '';
    }
    $kolom_target = 'email_ganti_sandi';
    if (!kolom_ada_adm($koneksi, $tabel, $kolom_target)) {
        $t = mysqli_real_escape_string($koneksi, $tabel);
        @mysqli_query($koneksi, "ALTER TABLE `$t` ADD COLUMN `$kolom_target` VARCHAR(191) NULL DEFAULT NULL");
    }
    return kolom_ada_adm($koneksi, $tabel, $kolom_target) ? $kolom_target : '';
}

function badge_status_adm(string $status_raw): string {
    $label_bersih = trim($status_raw) !== '' ? trim($status_raw) : '-';
    $s = strtolower(preg_replace('/\s+/', ' ', $label_bersih));
    $teks = htmlspecialchars($label_bersih, ENT_QUOTES, 'UTF-8');

    if ($s === '-') {
        return '<span class="badge bg-secondary">' . $teks . '</span>';
    }
    if (str_contains($s, 'tolak') || str_contains($s, 'reject')) {
        return '<span class="badge bg-danger">' . $teks . '</span>';
    }
    if (str_contains($s, 'selesai') || str_contains($s, 'arsip') || str_contains($s, 'done')) {
        return '<span class="badge bg-success">' . $teks . '</span>';
    }
    if (str_contains($s, 'proses') || str_contains($s, 'progres') || str_contains($s, 'disposisi') || str_contains($s, 'verif')) {
        return '<span class="badge bg-warning text-dark">' . $teks . '</span>';
    }
    return '<span class="badge bg-primary">' . $teks . '</span>';
}

function badge_sifat_adm(string $sifat_raw): string {
    $label_bersih = trim($sifat_raw) !== '' ? trim($sifat_raw) : 'Biasa';
    $s = strtolower(preg_replace('/\s+/', ' ', $label_bersih));
    $teks = htmlspecialchars($label_bersih, ENT_QUOTES, 'UTF-8');

    if (str_contains($s, 'rahasia')) {
        return '<span class="badge bg-dark">' . $teks . '</span>';
    }
    if (str_contains($s, 'segera') || str_contains($s, 'kilat') || str_contains($s, 'mendesak')) {
        return '<span class="badge bg-danger">' . $teks . '</span>';
    }
    if (str_contains($s, 'penting')) {
        return '<span class="badge bg-warning text-dark">' . $teks . '</span>';
    }
    if (str_contains($s, 'biasa')) {
        return '<span class="badge bg-secondary">' . $teks . '</span>';
    }
    return '<span class="badge bg-primary">' . $teks . '</span>';
}

$menu_aktif = strtolower(trim((string)($_GET['menu'] ?? 'dashboard')));
if (!in_array($menu_aktif, ['dashboard', 'users', 'surat', 'profil'], true)) {
    $menu_aktif = 'dashboard';
}

$id_user_login  = (int)($_SESSION['user_id'] ?? $_SESSION['id_user'] ?? $_SESSION['id'] ?? (is_array($_SESSION['user'] ?? null) ? ($_SESSION['user']['id'] ?? 0) : 0));
$username_login = trim((string)($_SESSION['username'] ?? (is_array($_SESSION['user'] ?? null) ? ($_SESSION['user']['username'] ?? '') : '')));
$email_login    = trim((string)($_SESSION['email'] ?? (is_array($_SESSION['user'] ?? null) ? ($_SESSION['user']['email'] ?? '') : '')));

$ada_users         = tabel_ada_adm($koneksi, 'users');
$pk_users          = $ada_users ? deteksi_pk_adm($koneksi, 'users', 'id') : 'id';
$kol_u_nama        = $ada_users ? cari_kolom_adm($koneksi, 'users', ['nama', 'nama_lengkap', 'name']) : '';
$kol_u_username    = $ada_users ? cari_kolom_adm($koneksi, 'users', ['username', 'user']) : '';
$kol_u_email       = $ada_users ? cari_kolom_adm($koneksi, 'users', ['email']) : '';
$kol_u_role        = $ada_users ? cari_kolom_adm($koneksi, 'users', ['role', 'level']) : '';
$kol_u_pass        = $ada_users ? cari_kolom_adm($koneksi, 'users', ['password', 'sandi', 'kata_sandi']) : '';
$kol_u_email_sandi = $ada_users ? pastikan_kolom_email_ganti_sandi($koneksi, 'users') : '';

$email_default_otp = 'jenovaniacristaniaparaeng@gmail.com';
$nama_admin = trim((string)($_SESSION['nama'] ?? ($_SESSION['name'] ?? ($username_login !== '' ? $username_login : 'Administrator'))));
$email_terakhir_ganti_sandi = '';

if ($ada_users) {
    $pk_u_esc = mysqli_real_escape_string($koneksi, $pk_users);
    $row_a = null;
    if ($id_user_login > 0) {
        $r_a = mysqli_query($koneksi, "SELECT * FROM `users` WHERE `$pk_u_esc` = $id_user_login LIMIT 1");
        if ($r_a) {
            $row_a = mysqli_fetch_assoc($r_a) ?: null;
        }
    } elseif ($username_login !== '' && $kol_u_username !== '') {
        $u_esc = mysqli_real_escape_string($koneksi, $username_login);
        $r_a = mysqli_query($koneksi, "SELECT * FROM `users` WHERE LOWER(TRIM(`$kol_u_username`)) = LOWER('$u_esc') LIMIT 1");
        if ($r_a) {
            $row_a = mysqli_fetch_assoc($r_a) ?: null;
            if ($row_a) {
                $id_user_login = (int)($row_a[$pk_users] ?? $id_user_login);
            }
        }
    }

    if (is_array($row_a)) {
        if ($kol_u_nama !== '' && !empty($row_a[$kol_u_nama])) {
            $nama_admin = trim((string)$row_a[$kol_u_nama]);
        }
        if ($kol_u_username !== '' && !empty($row_a[$kol_u_username])) {
            $username_login = trim((string)$row_a[$kol_u_username]);
        }
        if ($kol_u_email !== '' && isset($row_a[$kol_u_email]) && trim((string)$row_a[$kol_u_email]) !== '') {
            $email_login = trim((string)$row_a[$kol_u_email]);
        }
        if ($kol_u_email_sandi !== '' && isset($row_a[$kol_u_email_sandi]) && trim((string)$row_a[$kol_u_email_sandi]) !== '') {
            $email_terakhir_ganti_sandi = trim((string)$row_a[$kol_u_email_sandi]);
        }
    }
}

$pesan_sukses = '';
$pesan_error  = '';

// Aksi 1: Tambah akun pengguna baru
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['tambah_user_adm']) && $ada_users) {
    $menu_aktif = 'users';
    $nama_baru  = trim((string)($_POST['nama_user'] ?? ''));
    $uname_baru = trim((string)($_POST['username_user'] ?? ''));
    $email_baru = trim((string)($_POST['email_user'] ?? ''));
    $role_baru  = strtolower(trim((string)($_POST['role_user'] ?? 'sekretariat')));
    $pass_baru  = (string)($_POST['password_user'] ?? '');

    if ($nama_baru === '' || ($kol_u_username !== '' && $uname_baru === '') || ($kol_u_pass !== '' && strlen($pass_baru) < 6)) {
        $pesan_error = 'Lengkapi nama, username, dan password minimal 6 karakter.';
    } elseif ($email_baru !== '' && !filter_var($email_baru, FILTER_VALIDATE_EMAIL)) {
        $pesan_error = 'Format email pengguna tidak valid.';
    } else {
        $duplikat = false;
        if ($kol_u_username !== '' && $uname_baru !== '') {
            $u_esc = mysqli_real_escape_string($koneksi, $uname_baru);
            $pk_u_esc = mysqli_real_escape_string($koneksi, $pk_users);
            $cek_d = mysqli_query($koneksi, "SELECT `$pk_u_esc` FROM `users` WHERE LOWER(TRIM(`$kol_u_username`)) = LOWER('$u_esc') LIMIT 1");
            if ($cek_d && mysqli_num_rows($cek_d) > 0) {
                $duplikat = true;
            }
        }

        if ($duplikat) {
            $pesan_error = 'Username tersebut sudah terdaftar.';
        } else {
            $cols = [];
            $vals = [];

            if ($kol_u_nama !== '') {
                $cols[] = "`$kol_u_nama`";
                $vals[] = "'" . mysqli_real_escape_string($koneksi, $nama_baru) . "'";
            }
            if ($kol_u_username !== '') {
                $cols[] = "`$kol_u_username`";
                $vals[] = "'" . mysqli_real_escape_string($koneksi, $uname_baru) . "'";
            }
            if ($kol_u_email !== '') {
                $cols[] = "`$kol_u_email`";
                $vals[] = "'" . mysqli_real_escape_string($koneksi, $email_baru) . "'";
            }
            if ($kol_u_role !== '') {
                $cols[] = "`$kol_u_role`";
                $vals[] = "'" . mysqli_real_escape_string($koneksi, $role_baru) . "'";
            }
            if ($kol_u_pass !== '') {
                $hash_p = password_hash($pass_baru, PASSWORD_DEFAULT);
                $cols[] = "`$kol_u_pass`";
                $vals[] = "'" . mysqli_real_escape_string($koneksi, $hash_p) . "'";
            }

            if (!empty($cols)) {
                $sql_ins = "INSERT INTO `users` (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $vals) . ")";
                if (mysqli_query($koneksi, $sql_ins)) {
                    $pesan_sukses = 'Akun pengguna baru berhasil ditambahkan.';
                } else {
                    $pesan_error = 'Gagal menambah akun: ' . mysqli_error($koneksi);
                }
            }
        }
    }
}

// Aksi 2: Edit akun pengguna
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_user_adm']) && $ada_users) {
    $menu_aktif = 'users';
    $target_id  = (int)($_POST['id_user_edit'] ?? 0);
    $nama_edit  = trim((string)($_POST['nama_user_edit'] ?? ''));
    $email_edit = trim((string)($_POST['email_user_edit'] ?? ''));
    $role_edit  = strtolower(trim((string)($_POST['role_user_edit'] ?? '')));
    $pass_reset = (string)($_POST['password_reset_edit'] ?? '');

    if ($target_id <= 0 || $nama_edit === '') {
        $pesan_error = 'Data akun yang diubah belum lengkap.';
    } elseif ($email_edit !== '' && !filter_var($email_edit, FILTER_VALIDATE_EMAIL)) {
        $pesan_error = 'Format email tidak valid.';
    } elseif ($pass_reset !== '' && strlen($pass_reset) < 6) {
        $pesan_error = 'Password reset minimal 6 karakter.';
    } else {
        $set_parts = [];
        if ($kol_u_nama !== '') {
            $set_parts[] = "`$kol_u_nama` = '" . mysqli_real_escape_string($koneksi, $nama_edit) . "'";
        }
        if ($kol_u_email !== '') {
            $set_parts[] = "`$kol_u_email` = '" . mysqli_real_escape_string($koneksi, $email_edit) . "'";
        }
        if ($kol_u_role !== '' && $role_edit !== '') {
            $set_parts[] = "`$kol_u_role` = '" . mysqli_real_escape_string($koneksi, $role_edit) . "'";
        }
        if ($kol_u_pass !== '' && $pass_reset !== '') {
            $set_parts[] = "`$kol_u_pass` = '" . mysqli_real_escape_string($koneksi, password_hash($pass_reset, PASSWORD_DEFAULT)) . "'";
        }

        if (!empty($set_parts)) {
            $pk_u_esc = mysqli_real_escape_string($koneksi, $pk_users);
            $sql_upd = "UPDATE `users` SET " . implode(', ', $set_parts) . " WHERE `$pk_u_esc` = $target_id LIMIT 1";
            if (mysqli_query($koneksi, $sql_upd)) {
                $pesan_sukses = 'Data akun pengguna berhasil diperbarui.';
            } else {
                $pesan_error = 'Gagal memperbarui akun: ' . mysqli_error($koneksi);
            }
        }
    }
}

// Aksi 3: Hapus akun pengguna (kecuali akun sendiri)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['hapus_user_adm']) && $ada_users) {
    $menu_aktif = 'users';
    $hapus_id   = (int)($_POST['id_user_hapus'] ?? 0);

    if ($hapus_id <= 0) {
        $pesan_error = 'ID pengguna tidak valid.';
    } elseif ($id_user_login > 0 && $hapus_id === $id_user_login) {
        $pesan_error = 'Akun admin yang sedang digunakan tidak dapat dihapus.';
    } else {
        $pk_u_esc = mysqli_real_escape_string($koneksi, $pk_users);
        if (mysqli_query($koneksi, "DELETE FROM `users` WHERE `$pk_u_esc` = $hapus_id LIMIT 1")) {
            $pesan_sukses = 'Akun pengguna berhasil dihapus.';
        } else {
            $pesan_error = 'Gagal menghapus akun: ' . mysqli_error($koneksi);
        }
    }
}

// Batalkan verifikasi OTP profil jika diminta
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['batal_otp_profil_adm'])) {
    $menu_aktif = 'profil';
    unset($_SESSION['otp_profil_adm']);
    $pesan_error = 'Verifikasi OTP dibatalkan. Perubahan email/password belum disimpan.';
}

// Verifikasi kode OTP saat Admin mengubah email atau password
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verifikasi_otp_profil_adm'])) {
    $menu_aktif = 'profil';
    $kode_input = trim((string)($_POST['kode_otp_adm'] ?? ''));
    $pending    = $_SESSION['otp_profil_adm'] ?? null;

    if (!is_array($pending) || empty($pending['kode'])) {
        $pesan_error = 'Sesi kode OTP tidak ditemukan. Silakan simpan ulang perubahan profil Anda.';
    } elseif (time() > (int)($pending['expired_at'] ?? 0)) {
        unset($_SESSION['otp_profil_adm']);
        $pesan_error = 'Kode OTP sudah kedaluwarsa. Silakan kirim ulang kode OTP.';
    } elseif ($kode_input === '' || !hash_equals((string)$pending['kode'], $kode_input)) {
        $pesan_error = 'Kode OTP tidak sesuai. Periksa kembali kode OTP yang dikirim ke email terakhir ganti sandi.';
    } else {
        $nama_simpan  = (string)($pending['nama'] ?? $nama_admin);
        $uname_simpan = (string)($pending['username'] ?? $username_login);
        $email_simpan = (string)($pending['email'] ?? $email_login);
        $pass_simpan  = (string)($pending['password_baru'] ?? '');

        // Email terakhir ganti sandi di-update ke email baru (jika diubah/diisi) atau email tersimpan saat ini
        $email_sandi_baru = '';
        if ($email_simpan !== '' && filter_var($email_simpan, FILTER_VALIDATE_EMAIL)) {
            $email_sandi_baru = $email_simpan;
        } elseif ($email_terakhir_ganti_sandi !== '') {
            $email_sandi_baru = $email_terakhir_ganti_sandi;
        } elseif ($email_login !== '') {
            $email_sandi_baru = $email_login;
        } else {
            $email_sandi_baru = $email_default_otp;
        }

        $simpan_ok = true;
        $pk_u_esc = mysqli_real_escape_string($koneksi, $pk_users);
        if ($ada_users && $id_user_login > 0) {
            $set_p = [];
            if ($kol_u_nama !== '') {
                $set_p[] = "`$kol_u_nama` = '" . mysqli_real_escape_string($koneksi, $nama_simpan) . "'";
            }
            if ($kol_u_username !== '' && $uname_simpan !== '') {
                $set_p[] = "`$kol_u_username` = '" . mysqli_real_escape_string($koneksi, $uname_simpan) . "'";
            }
            if ($kol_u_email !== '') {
                $set_p[] = "`$kol_u_email` = '" . mysqli_real_escape_string($koneksi, $email_simpan) . "'";
            }
            if ($kol_u_pass !== '' && $pass_simpan !== '') {
                $set_p[] = "`$kol_u_pass` = '" . mysqli_real_escape_string($koneksi, password_hash($pass_simpan, PASSWORD_DEFAULT)) . "'";
            }
            if ($kol_u_email_sandi !== '' && $email_sandi_baru !== '') {
                $set_p[] = "`$kol_u_email_sandi` = '" . mysqli_real_escape_string($koneksi, $email_sandi_baru) . "'";
            }
            if (!empty($set_p)) {
                $simpan_ok = (bool)mysqli_query($koneksi, "UPDATE `users` SET " . implode(', ', $set_p) . " WHERE `$pk_u_esc` = $id_user_login LIMIT 1");
            }
        }

        if ($simpan_ok) {
            unset($_SESSION['otp_profil_adm']);
            $_SESSION['nama'] = $nama_simpan;
            $_SESSION['name'] = $nama_simpan;
            if (is_array($_SESSION['user'] ?? null)) {
                $_SESSION['user']['nama'] = $nama_simpan;
            }
            if ($uname_simpan !== '') {
                $_SESSION['username'] = $uname_simpan;
                $username_login = $uname_simpan;
            }
            $_SESSION['email'] = $email_simpan;
            $email_login = $email_simpan;
            $nama_admin = $nama_simpan;
            $email_terakhir_ganti_sandi = $email_sandi_baru;
            $pesan_sukses = 'Kode OTP terverifikasi. Perubahan profil berhasil disimpan; email terakhir ganti sandi kini ' . $email_sandi_baru . '.';
        } else {
            $pesan_error = 'Gagal menyimpan perubahan profil: ' . mysqli_error($koneksi);
        }
    }
}

// Aksi 4: Simpan pengaturan profil Admin sendiri (OTP dikirim ke email terakhir ganti sandi)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['simpan_profil_adm'])) {
    $menu_aktif = 'profil';
    $nama_adm   = trim((string)($_POST['nama_profil_adm'] ?? ''));
    $uname_adm  = trim((string)($_POST['username_profil_adm'] ?? ''));
    $email_adm  = trim((string)($_POST['email_profil_adm'] ?? ''));
    $pass_adm_1 = (string)($_POST['pass_baru_adm'] ?? '');
    $pass_adm_2 = (string)($_POST['pass_konf_adm'] ?? '');

    if ($nama_adm === '') {
        $pesan_error = 'Nama administrator tidak boleh kosong.';
    } elseif ($kol_u_username !== '' && $uname_adm === '') {
        $pesan_error = 'Username administrator tidak boleh kosong.';
    } elseif ($email_adm !== '' && !filter_var($email_adm, FILTER_VALIDATE_EMAIL)) {
        $pesan_error = 'Format email administrator tidak valid.';
    } elseif ($pass_adm_1 !== '' && strlen($pass_adm_1) < 6) {
        $pesan_error = 'Password baru minimal 6 karakter.';
    } elseif ($pass_adm_1 !== '' && $pass_adm_1 !== $pass_adm_2) {
        $pesan_error = 'Konfirmasi password baru tidak cocok.';
    } else {
        $duplikat_uname = false;
        $pk_u_esc = mysqli_real_escape_string($koneksi, $pk_users);
        if ($ada_users && $kol_u_username !== '' && $uname_adm !== '') {
            $u_cek_esc = mysqli_real_escape_string($koneksi, $uname_adm);
            $sql_cek_u = "SELECT `$pk_u_esc` FROM `users` WHERE LOWER(TRIM(`$kol_u_username`)) = LOWER('$u_cek_esc')";
            if ($id_user_login > 0) {
                $sql_cek_u .= " AND `$pk_u_esc` != $id_user_login";
            }
            $sql_cek_u .= " LIMIT 1";
            $res_cek_u = mysqli_query($koneksi, $sql_cek_u);
            if ($res_cek_u && mysqli_num_rows($res_cek_u) > 0) {
                $duplikat_uname = true;
            }
        }

        if ($duplikat_uname) {
            $pesan_error = 'Username tersebut sudah dipakai akun lain.';
        } else {
            $email_berubah    = (strtolower($email_adm) !== strtolower($email_login));
            $password_berubah = ($pass_adm_1 !== '');

            if ($email_berubah || $password_berubah) {
                // Prioritas tujuan OTP:
                // 1. Email terakhir ganti sandi yang sudah tersimpan ($email_terakhir_ganti_sandi)
                // 2. Email aktif akun saat ini ($email_login)
                // 3. Fallback awal sementara ($email_default_otp) bila belum pernah diganti/tersimpan
                if ($email_terakhir_ganti_sandi !== '' && filter_var($email_terakhir_ganti_sandi, FILTER_VALIDATE_EMAIL)) {
                    $email_tujuan_otp = $email_terakhir_ganti_sandi;
                } elseif ($email_login !== '' && filter_var($email_login, FILTER_VALIDATE_EMAIL)) {
                    $email_tujuan_otp = $email_login;
                } else {
                    $email_tujuan_otp = $email_default_otp;
                }

                if ($email_tujuan_otp === '' || !filter_var($email_tujuan_otp, FILTER_VALIDATE_EMAIL)) {
                    $pesan_error = 'Email terakhir ganti sandi belum tersedia atau tidak valid untuk menerima kode OTP.';
                } elseif (!function_exists('kirim_otp_smtp')) {
                    $pesan_error = 'Fungsi pengiriman OTP (kirim_otp_smtp) belum tersedia di koneksi.php.';
                } else {
                    $kode_otp = (string)random_int(100000, 999999);
                    $detail_smtp = '';
                    if (kirim_otp_smtp($email_tujuan_otp, $kode_otp, $nama_adm, $detail_smtp)) {
                        $_SESSION['otp_profil_adm'] = [
                            'kode'          => $kode_otp,
                            'email_otp'     => $email_tujuan_otp,
                            'expired_at'    => time() + 600,
                            'nama'          => $nama_adm,
                            'username'      => $uname_adm,
                            'email'         => $email_adm,
                            'password_baru' => $pass_adm_1,
                        ];
                        $pesan_sukses = 'Kode OTP telah dikirim ke email terakhir ganti sandi (' . $email_tujuan_otp . '). Masukkan kode OTP di bawah untuk mengonfirmasi perubahan.';
                    } else {
                        $pesan_error = 'Gagal mengirim kode OTP ke email terakhir ganti sandi (' . $email_tujuan_otp . '): ' . ($detail_smtp ?: 'periksa konfigurasi SMTP');
                    }
                }
            } else {
                $simpan_ok = true;
                if ($ada_users && $id_user_login > 0) {
                    $set_p = [];
                    if ($kol_u_nama !== '') {
                        $set_p[] = "`$kol_u_nama` = '" . mysqli_real_escape_string($koneksi, $nama_adm) . "'";
                    }
                    if ($kol_u_username !== '' && $uname_adm !== '') {
                        $set_p[] = "`$kol_u_username` = '" . mysqli_real_escape_string($koneksi, $uname_adm) . "'";
                    }
                    if (!empty($set_p)) {
                        $simpan_ok = (bool)mysqli_query($koneksi, "UPDATE `users` SET " . implode(', ', $set_p) . " WHERE `$pk_u_esc` = $id_user_login LIMIT 1");
                    }
                }

                if ($simpan_ok) {
                    $_SESSION['nama'] = $nama_adm;
                    $_SESSION['name'] = $nama_adm;
                    if (is_array($_SESSION['user'] ?? null)) {
                        $_SESSION['user']['nama'] = $nama_adm;
                    }
                    if ($uname_adm !== '') {
                        $_SESSION['username'] = $uname_adm;
                        $username_login = $uname_adm;
                    }
                    $nama_admin = $nama_adm;
                    $pesan_sukses = 'Profil administrator berhasil disimpan.';
                } else {
                    $pesan_error = 'Gagal menyimpan profil: ' . mysqli_error($koneksi);
                }
            }
        }
    }
}

// Aksi 5: Hapus surat secara permanen di Monitoring Surat
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['hapus_surat_permanen']) && tabel_ada_adm($koneksi, 'surat_masuk')) {
    $menu_aktif    = 'surat';
    $id_surat_hps  = (int)($_POST['id_surat_hapus'] ?? 0);
    $pk_surat      = deteksi_pk_adm($koneksi, 'surat_masuk', 'id');
    $k_fl_hps      = cari_kolom_adm($koneksi, 'surat_masuk', ['file_surat', 'lampiran', 'berkas_surat']);

    if ($id_surat_hps <= 0) {
        $pesan_error = 'ID surat tidak valid.';
    } else {
        $pk_s_esc = mysqli_real_escape_string($koneksi, $pk_surat);
        $file_lama = '';

        if ($k_fl_hps !== '') {
            $q_fl = mysqli_query($koneksi, "SELECT `$k_fl_hps` FROM `surat_masuk` WHERE `$pk_s_esc` = $id_surat_hps LIMIT 1");
            if ($q_fl && ($row_fl = mysqli_fetch_assoc($q_fl))) {
                $file_lama = trim((string)($row_fl[$k_fl_hps] ?? ''));
            }
        }

        if (mysqli_query($koneksi, "DELETE FROM `surat_masuk` WHERE `$pk_s_esc` = $id_surat_hps LIMIT 1")) {
            if ($file_lama !== '') {
                $nama_file_aman = basename(str_replace('\\', '/', $file_lama));
                $base_upload = realpath(dirname(__DIR__) . '/uploads');
                $target_file = realpath(dirname(__DIR__) . '/uploads/' . $nama_file_aman);
                if ($base_upload && $target_file && strpos($target_file, $base_upload) === 0 && is_file($target_file)) {
                    @unlink($target_file);
                }
            }
            $pesan_sukses = 'Surat berhasil dihapus secara permanen dari sistem.';
        } else {
            $pesan_error = 'Gagal menghapus surat secara permanen: ' . mysqli_error($koneksi);
        }
    }
}

// Sinkronkan nama & data user terbaru dari database setelah proses POST
if ($ada_users) {
    $pk_u_esc = mysqli_real_escape_string($koneksi, $pk_users);
    $row_sync = null;
    if ($id_user_login > 0) {
        $r_sync = mysqli_query($koneksi, "SELECT * FROM `users` WHERE `$pk_u_esc` = $id_user_login LIMIT 1");
        if ($r_sync) {
            $row_sync = mysqli_fetch_assoc($r_sync) ?: null;
        }
    } elseif ($username_login !== '' && $kol_u_username !== '') {
        $u_sync_esc = mysqli_real_escape_string($koneksi, $username_login);
        $r_sync = mysqli_query($koneksi, "SELECT * FROM `users` WHERE LOWER(TRIM(`$kol_u_username`)) = LOWER('$u_sync_esc') LIMIT 1");
        if ($r_sync) {
            $row_sync = mysqli_fetch_assoc($r_sync) ?: null;
        }
    }

    if (is_array($row_sync)) {
        if ($kol_u_nama !== '' && isset($row_sync[$kol_u_nama]) && trim((string)$row_sync[$kol_u_nama]) !== '') {
            $nama_admin = trim((string)$row_sync[$kol_u_nama]);
            $_SESSION['nama'] = $nama_admin;
            $_SESSION['name'] = $nama_admin;
            if (is_array($_SESSION['user'] ?? null)) {
                $_SESSION['user']['nama'] = $nama_admin;
            }
        }
        if ($kol_u_username !== '' && isset($row_sync[$kol_u_username]) && trim((string)$row_sync[$kol_u_username]) !== '') {
            $username_login = trim((string)$row_sync[$kol_u_username]);
            $_SESSION['username'] = $username_login;
        }
        if ($kol_u_email !== '' && isset($row_sync[$kol_u_email])) {
            $email_login = trim((string)$row_sync[$kol_u_email]);
            $_SESSION['email'] = $email_login;
        }
        if ($kol_u_email_sandi !== '' && isset($row_sync[$kol_u_email_sandi]) && trim((string)$row_sync[$kol_u_email_sandi]) !== '') {
            $email_terakhir_ganti_sandi = trim((string)$row_sync[$kol_u_email_sandi]);
        }
    }
}

if ($email_terakhir_ganti_sandi !== '') {
    $email_tampilan_otp = $email_terakhir_ganti_sandi;
} elseif ($email_login !== '') {
    $email_tampilan_otp = $email_login;
} else {
    $email_tampilan_otp = $email_default_otp;
}

$pending_otp_profil = (isset($_SESSION['otp_profil_adm']) && is_array($_SESSION['otp_profil_adm']) && !empty($_SESSION['otp_profil_adm']['kode']))
    ? $_SESSION['otp_profil_adm']
    : null;

// Logo instansi
$kandidat_logo = [
    '../assets/logo_sangihe.png',
    '../logo_sangihe.png',
    '../assets/img/logo_sangihe.png',
    'assets/logo_sangihe.png',
];
$url_logo = '../assets/logo_sangihe.png';
foreach ($kandidat_logo as $rel) {
    if (file_exists(__DIR__ . '/' . $rel) && is_file(__DIR__ . '/' . $rel)) {
        $url_logo = $rel;
        break;
    }
}

// Statistik ringkasan surat & user
$total_surat_all = 0;
$total_proses    = 0;
$total_selesai   = 0;
$total_users     = 0;

if (tabel_ada_adm($koneksi, 'surat_masuk')) {
    $q_all = mysqli_query($koneksi, "SELECT COUNT(*) AS jml FROM `surat_masuk`");
    $total_surat_all = (int)(mysqli_fetch_assoc($q_all)['jml'] ?? 0);

    if (kolom_ada_adm($koneksi, 'surat_masuk', 'status_surat')) {
        $q_pr = mysqli_query($koneksi, "SELECT COUNT(*) AS jml FROM `surat_masuk` WHERE LOWER(TRIM(status_surat)) IN ('diproses', 'di proses')");
        $total_proses = (int)(mysqli_fetch_assoc($q_pr)['jml'] ?? 0);

        $q_sl = mysqli_query($koneksi, "SELECT COUNT(*) AS jml FROM `surat_masuk` WHERE LOWER(TRIM(status_surat)) IN ('selesai', 'diarsipkan', 'arsip')");
        $total_selesai = (int)(mysqli_fetch_assoc($q_sl)['jml'] ?? 0);
    }
}

if ($ada_users) {
    $q_us = mysqli_query($koneksi, "SELECT COUNT(*) AS jml FROM `users`");
    $total_users = (int)(mysqli_fetch_assoc($q_us)['jml'] ?? 0);
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Admin - E-Office Diskominfo Sangihe</title>
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
        .header-biru-admin {
            background-color: #1e3a8a;
            border: 1px solid #172554;
            color: #ffffff;
            border-radius: 14px;
            padding: 20px 24px;
            box-shadow: 0 8px 20px rgba(30, 58, 138, 0.16);
        }
        .badge-putih-adm {
            background-color: #ffffff;
            color: #1e293b;
            font-size: 0.75rem;
            font-weight: 700;
            letter-spacing: 0.4px;
            padding: 6px 14px;
            border-radius: 999px;
            text-transform: uppercase;
            box-shadow: 0 2px 6px rgba(15, 23, 42, 0.12);
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .card-putih-adm {
            background-color: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 20px 22px;
            box-shadow: 0 4px 14px rgba(15, 23, 42, 0.04);
        }
        .stat-link-adm {
            text-decoration: none;
            color: inherit;
            display: block;
            height: 100%;
        }
        .stat-box-adm {
            background-color: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 20px;
            box-shadow: 0 4px 14px rgba(15, 23, 42, 0.04);
            height: 100%;
            transition: transform 0.16s ease, border-color 0.16s ease, box-shadow 0.16s ease;
        }
        .stat-link-adm:hover .stat-box-adm {
            transform: translateY(-2px);
            border-color: #93c5fd;
            box-shadow: 0 8px 18px rgba(30, 58, 138, 0.1);
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
                <img src="<?= htmlspecialchars($url_logo, ENT_QUOTES, 'UTF-8'); ?>" alt="Logo Diskominfo Sangihe" class="sidebar-logo-img">
            </div>
            <ul class="nav nav-pills flex-column">
                <li class="nav-item"><a href="dashboard.php?menu=dashboard" class="nav-link <?= $menu_aktif === 'dashboard' ? 'active' : ''; ?>"><i class="bi bi-speedometer2 me-2"></i> Dashboard Admin</a></li>
                <li class="nav-item"><a href="dashboard.php?menu=users" class="nav-link <?= $menu_aktif === 'users' ? 'active' : ''; ?>"><i class="bi bi-people-fill me-2"></i> Manajemen Pengguna</a></li>
                <li class="nav-item"><a href="dashboard.php?menu=surat" class="nav-link <?= $menu_aktif === 'surat' ? 'active' : ''; ?>"><i class="bi bi-envelope-paper-fill me-2"></i> Monitoring Surat</a></li>
                <li class="nav-item"><a href="dashboard.php?menu=profil" class="nav-link <?= $menu_aktif === 'profil' ? 'active' : ''; ?>"><i class="bi bi-gear-fill me-2"></i> Pengaturan Profil</a></li>
            </ul>
        </div>
        <div class="pt-3 border-top border-light-subtle">
            <a href="../logout.php" class="logout-link"><i class="bi bi-box-arrow-left me-2"></i> Keluar Sistem</a>
        </div>
    </aside>

    <main class="main-content">
        <div class="container-fluid px-0">
            <div class="header-biru-admin mb-4 d-flex justify-content-between align-items-center flex-wrap gap-3">
                <div>
                    <h4 class="fw-bold mb-1 text-white">Panel Administrator Sistem</h4>
                    <p class="mb-0 small" style="color: #dbeafe;">Kelola akun pengguna, hak akses, dan pantau alur persuratan &bull; <strong class="text-white"><?= htmlspecialchars($nama_admin, ENT_QUOTES, 'UTF-8'); ?></strong></p>
                </div>
                <div>
                    <span class="badge-putih-adm"><i class="bi bi-shield-check-fill text-primary"></i> <span>ADMIN SISTEM</span></span>
                </div>
            </div>

            <?php if ($pesan_sukses !== ''): ?>
                <div class="alert alert-success alert-dismissible fade show shadow-sm rounded-3 mb-3" role="alert">
                    <i class="bi bi-check-circle-fill me-2"></i><?= htmlspecialchars($pesan_sukses, ENT_QUOTES, 'UTF-8'); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <?php if ($pesan_error !== ''): ?>
                <div class="alert alert-danger alert-dismissible fade show shadow-sm rounded-3 mb-3" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($pesan_error, ENT_QUOTES, 'UTF-8'); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <?php if ($menu_aktif === 'users'): ?>
                <div class="card-putih-adm">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                        <div>
                            <h5 class="fw-bold mb-0"><i class="bi bi-people me-2 text-primary"></i>Manajemen Pengguna</h5>
                            <div class="small text-muted">Total <?= $total_users; ?> akun terdaftar di sistem</div>
                        </div>
                        <button type="button" class="btn btn-primary btn-sm fw-semibold px-3" data-bs-toggle="modal" data-bs-target="#modalTambahUserAdm">
                            <i class="bi bi-person-plus-fill me-1"></i> Tambah Pengguna
                        </button>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-bordered table-hover table-sm align-middle small mb-0">
                            <thead class="table-dark text-center">
                                <tr>
                                    <th width="5%">No</th>
                                    <th>Nama</th>
                                    <th>Username</th>
                                    <th>Email</th>
                                    <th width="13%">Role</th>
                                    <th width="18%">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $no_u = 1;
                                if ($ada_users) {
                                    $pk_u_esc = mysqli_real_escape_string($koneksi, $pk_users);
                                    $res_list_u = mysqli_query($koneksi, "SELECT * FROM `users` ORDER BY `$pk_u_esc` DESC");
                                    if ($res_list_u && mysqli_num_rows($res_list_u) > 0) {
                                        while ($ru = mysqli_fetch_assoc($res_list_u)) {
                                            $uid_r   = (int)($ru[$pk_users] ?? ($ru['id'] ?? 0));
                                            $nama_r  = $kol_u_nama !== '' ? (string)($ru[$kol_u_nama] ?? '-') : '-';
                                            $uname_r = $kol_u_username !== '' ? (string)($ru[$kol_u_username] ?? '-') : '-';
                                            $email_r = $kol_u_email !== '' ? (string)($ru[$kol_u_email] ?? '-') : '-';
                                            $role_r  = $kol_u_role !== '' ? (string)($ru[$kol_u_role] ?? '-') : '-';
                                ?>
                                <tr>
                                    <td class="text-center"><?= $no_u++; ?></td>
                                    <td class="fw-semibold"><?= htmlspecialchars($nama_r, ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?= htmlspecialchars($uname_r, ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?= htmlspecialchars($email_r !== '' ? $email_r : '-', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td class="text-center"><span class="badge bg-primary"><?= htmlspecialchars($role_r, ENT_QUOTES, 'UTF-8'); ?></span></td>
                                    <td class="text-center text-nowrap">
                                        <button type="button" class="btn btn-sm btn-warning text-dark py-0 px-2" data-bs-toggle="modal" data-bs-target="#modalEditUser<?= $uid_r; ?>">
                                            <i class="bi bi-pencil-square"></i> Edit
                                        </button>
                                        <?php if ($uid_r !== $id_user_login): ?>
                                            <form method="POST" action="dashboard.php?menu=users" class="d-inline" onsubmit="return confirm('Hapus akun ini?');">
                                                <input type="hidden" name="id_user_hapus" value="<?= $uid_r; ?>">
                                                <button type="submit" name="hapus_user_adm" value="1" class="btn btn-sm btn-danger py-0 px-2">
                                                    <i class="bi bi-trash"></i> Hapus
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>

                                <div class="modal fade" id="modalEditUser<?= $uid_r; ?>" tabindex="-1" aria-hidden="true">
                                    <div class="modal-dialog">
                                        <div class="modal-content">
                                            <form method="POST" action="dashboard.php?menu=users">
                                                <div class="modal-header">
                                                    <h6 class="modal-title fw-bold">Edit Akun: <?= htmlspecialchars($uname_r, ENT_QUOTES, 'UTF-8'); ?></h6>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                </div>
                                                <div class="modal-body">
                                                    <input type="hidden" name="id_user_edit" value="<?= $uid_r; ?>">
                                                    <div class="mb-3">
                                                        <label class="form-label small fw-semibold">Nama Lengkap</label>
                                                        <input type="text" name="nama_user_edit" class="form-control" value="<?= htmlspecialchars($nama_r, ENT_QUOTES, 'UTF-8'); ?>" required>
                                                    </div>
                                                    <div class="mb-3">
                                                        <label class="form-label small fw-semibold">Email</label>
                                                        <input type="email" name="email_user_edit" class="form-control" value="<?= htmlspecialchars($email_r !== '-' ? $email_r : '', ENT_QUOTES, 'UTF-8'); ?>">
                                                    </div>
                                                    <div class="mb-3">
                                                        <label class="form-label small fw-semibold">Role</label>
                                                        <input type="text" name="role_user_edit" class="form-control" value="<?= htmlspecialchars($role_r, ENT_QUOTES, 'UTF-8'); ?>" required>
                                                    </div>
                                                    <div class="mb-2">
                                                        <label class="form-label small fw-semibold">Reset Password (Opsional)</label>
                                                        <input type="password" name="password_reset_edit" class="form-control" value="" autocomplete="new-password" placeholder="Kosongkan jika tidak diubah">
                                                    </div>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
                                                    <button type="submit" name="edit_user_adm" value="1" class="btn btn-primary btn-sm fw-semibold">Simpan Perubahan</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                                <?php
                                        }
                                    } else {
                                        echo '<tr><td colspan="6" class="text-center text-muted py-3">Belum ada data pengguna.</td></tr>';
                                    }
                                } else {
                                    echo '<tr><td colspan="6" class="text-center text-muted py-3">Tabel users belum tersedia.</td></tr>';
                                }
                                ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="modal fade" id="modalTambahUserAdm" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <form method="POST" action="dashboard.php?menu=users">
                                <div class="modal-header">
                                    <h6 class="modal-title fw-bold"><i class="bi bi-person-plus-fill me-1 text-primary"></i> Tambah Akun Pengguna Baru</h6>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <div class="mb-3">
                                        <label class="form-label small fw-semibold">Nama Lengkap <span class="text-danger">*</span></label>
                                        <input type="text" name="nama_user" class="form-control" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label small fw-semibold">Username <span class="text-danger">*</span></label>
                                        <input type="text" name="username_user" class="form-control" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label small fw-semibold">Role / Hak Akses</label>
                                        <select name="role_user" class="form-select">
                                            <option value="sekretariat">Sekretariat</option>
                                            <option value="sekretaris">Sekretaris</option>
                                            <option value="kepala_dinas">Kepala Dinas</option>
                                            <option value="kabid">Kepala Bidang</option>
                                            <option value="pengirim">Pengirim Surat</option>
                                            <option value="admin">Admin</option>
                                        </select>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label small fw-semibold">Email</label>
                                        <input type="email" name="email_user" class="form-control" placeholder="opsional">
                                    </div>
                                    <div class="mb-2">
                                        <label class="form-label small fw-semibold">Password <span class="text-danger">*</span></label>
                                        <div class="input-group">
                                            <input type="password" name="password_user" id="passBaruAdm" class="form-control" value="" autocomplete="new-password" minlength="6" required>
                                            <button type="button" class="btn btn-outline-secondary btn-toggle-pass" data-target="passBaruAdm"><i class="bi bi-eye"></i></button>
                                        </div>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
                                    <button type="submit" name="tambah_user_adm" value="1" class="btn btn-primary btn-sm fw-semibold">Simpan Akun</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

            <?php elseif ($menu_aktif === 'surat'): ?>
                <div class="card-putih-adm">
                    <h5 class="fw-bold mb-3"><i class="bi bi-envelope-paper me-2 text-primary"></i>Monitoring Seluruh Surat Masuk</h5>
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped align-middle small mb-0">
                            <thead class="table-dark text-center">
                                <tr>
                                    <th width="5%">No</th>
                                    <th>No. Agenda</th>
                                    <th>Asal Instansi</th>
                                    <th>Nomor Surat</th>
                                    <th>Perihal</th>
                                    <th>Sifat</th>
                                    <th>Status</th>
                                    <th>Dokumen</th>
                                    <th width="14%">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $no_s = 1;
                                if (tabel_ada_adm($koneksi, 'surat_masuk')) {
                                    $pk_surat = deteksi_pk_adm($koneksi, 'surat_masuk', 'id');
                                    $k_ag = cari_kolom_adm($koneksi, 'surat_masuk', ['no_agenda', 'nomor_agenda']);
                                    $k_as = cari_kolom_adm($koneksi, 'surat_masuk', ['asal_instansi', 'asal_surat', 'pengirim']);
                                    $k_ns = cari_kolom_adm($koneksi, 'surat_masuk', ['no_surat', 'nomor_surat']);
                                    $k_pr = cari_kolom_adm($koneksi, 'surat_masuk', ['perihal', 'subjek', 'hal']);
                                    $k_sf = cari_kolom_adm($koneksi, 'surat_masuk', ['sifat_surat', 'sifat']);
                                    $k_st = cari_kolom_adm($koneksi, 'surat_masuk', ['status_surat', 'status']);
                                    $k_fl = cari_kolom_adm($koneksi, 'surat_masuk', ['file_surat', 'lampiran', 'berkas_surat']);

                                    $ord = kolom_ada_adm($koneksi, 'surat_masuk', $pk_surat) ? "ORDER BY `" . mysqli_real_escape_string($koneksi, $pk_surat) . "` DESC" : "";
                                    $q_s = mysqli_query($koneksi, "SELECT * FROM `surat_masuk` $ord");
                                    if ($q_s && mysqli_num_rows($q_s) > 0) {
                                        while ($rs = mysqli_fetch_assoc($q_s)) {
                                            $sid_r = (int)($rs[$pk_surat] ?? ($rs['id'] ?? 0));
                                            $v_ag = $k_ag !== '' ? (string)($rs[$k_ag] ?? '-') : '-';
                                            $v_as = $k_as !== '' ? (string)($rs[$k_as] ?? '-') : '-';
                                            $v_ns = $k_ns !== '' ? (string)($rs[$k_ns] ?? '-') : '-';
                                            $v_pr = $k_pr !== '' ? (string)($rs[$k_pr] ?? '-') : '-';
                                            $v_sf = $k_sf !== '' ? (string)($rs[$k_sf] ?? 'Biasa') : 'Biasa';
                                            $v_st = $k_st !== '' ? (string)($rs[$k_st] ?? '-') : '-';
                                            $v_fl = $k_fl !== '' ? (string)($rs[$k_fl] ?? '') : '';
                                            $rahasia = (strtolower(trim($v_sf)) === 'rahasia');
                                ?>
                                <tr>
                                    <td class="text-center"><?= $no_s++; ?></td>
                                    <td class="text-center"><?= htmlspecialchars($v_ag, ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?= htmlspecialchars($v_as, ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?= htmlspecialchars($v_ns, ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?= htmlspecialchars($v_pr, ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td class="text-center"><?= badge_sifat_adm($v_sf); ?></td>
                                    <td class="text-center"><?= badge_status_adm($v_st); ?></td>
                                    <td class="text-center">
                                        <?php if ($rahasia): ?>
                                            <span class="badge bg-dark"><i class="bi bi-lock-fill"></i> Rahasia</span>
                                        <?php elseif ($v_fl !== ''): ?>
                                            <a href="../uploads/<?= htmlspecialchars(basename($v_fl), ENT_QUOTES, 'UTF-8'); ?>" target="_blank" class="btn btn-sm btn-success py-0 px-2">Lihat</a>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center text-nowrap">
                                        <?php if ($sid_r > 0): ?>
                                            <form method="POST" action="dashboard.php?menu=surat" class="d-inline" onsubmit="return confirm('Hapus surat ini secara permanen? Data tidak dapat dikembalikan.');">
                                                <input type="hidden" name="id_surat_hapus" value="<?= $sid_r; ?>">
                                                <button type="submit" name="hapus_surat_permanen" value="1" class="btn btn-sm btn-danger py-0 px-2">
                                                    <i class="bi bi-trash-fill"></i> Hapus Permanen
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php
                                        }
                                    } else {
                                        echo '<tr><td colspan="9" class="text-center text-muted py-3">Belum ada data surat masuk.</td></tr>';
                                    }
                                } else {
                                    echo '<tr><td colspan="9" class="text-center text-muted py-3">Tabel surat_masuk belum tersedia.</td></tr>';
                                }
                                ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            <?php elseif ($menu_aktif === 'profil'): ?>
                <div class="card-putih-adm">
                    <h5 class="fw-bold mb-3"><i class="bi bi-person-gear me-2 text-primary"></i>Pengaturan Profil Administrator</h5>
                    <?php if ($email_tampilan_otp !== ''): ?>
                        <div class="small text-muted mb-3">
                            <i class="bi bi-shield-lock me-1 text-primary"></i>
                            Perubahan <strong>Email</strong> atau <strong>Password</strong> memerlukan kode OTP yang dikirim ke email terakhir ganti sandi: <strong><?= htmlspecialchars($email_tampilan_otp, ENT_QUOTES, 'UTF-8'); ?></strong>.
                        </div>
                    <?php endif; ?>

                    <?php if ($pending_otp_profil !== null): ?>
                        <div class="border rounded-3 p-3 mb-4 bg-light">
                            <h6 class="fw-bold mb-1 text-primary"><i class="bi bi-key-fill me-1"></i>Verifikasi Kode OTP</h6>
                            <p class="small text-muted mb-3">
                                Masukkan 6 digit kode OTP yang dikirim ke <strong><?= htmlspecialchars((string)($pending_otp_profil['email_otp'] ?? $email_tampilan_otp), ENT_QUOTES, 'UTF-8'); ?></strong> (berlaku 10 menit).
                            </p>
                            <form method="POST" action="dashboard.php?menu=profil" class="row g-2 align-items-center" autocomplete="off">
                                <div class="col-sm-5 col-md-4">
                                    <input type="text" name="kode_otp_adm" class="form-control" value="" maxlength="6" pattern="[0-9]{6}" placeholder="Masukkan 6 digit OTP" required>
                                </div>
                                <div class="col-auto">
                                    <button type="submit" name="verifikasi_otp_profil_adm" value="1" class="btn btn-success fw-semibold px-3">
                                        <i class="bi bi-check2-circle me-1"></i> Verifikasi &amp; Simpan
                                    </button>
                                </div>
                                <div class="col-auto">
                                    <button type="submit" name="batal_otp_profil_adm" value="1" class="btn btn-outline-secondary fw-semibold px-3">
                                        Batal
                                    </button>
                                </div>
                            </form>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="dashboard.php?menu=profil" autocomplete="off">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label fw-semibold small">Nama Administrator <span class="text-danger">*</span></label>
                                <input type="text" name="nama_profil_adm" class="form-control" value="<?= htmlspecialchars((string)($pending_otp_profil['nama'] ?? $nama_admin), ENT_QUOTES, 'UTF-8'); ?>" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold small">Username <span class="text-danger">*</span></label>
                                <input type="text" name="username_profil_adm" class="form-control" value="<?= htmlspecialchars((string)($pending_otp_profil['username'] ?? $username_login), ENT_QUOTES, 'UTF-8'); ?>" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold small">Email</label>
                                <input type="email" name="email_profil_adm" class="form-control" value="<?= htmlspecialchars((string)($pending_otp_profil['email'] ?? $email_login), ENT_QUOTES, 'UTF-8'); ?>" placeholder="nama@email.com">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold small">Password Baru</label>
                                <div class="input-group">
                                    <input type="password" name="pass_baru_adm" id="passAdmBaru" class="form-control" value="" autocomplete="new-password" placeholder="Kosongkan jika tidak diubah">
                                    <button type="button" class="btn btn-outline-secondary btn-toggle-pass" data-target="passAdmBaru"><i class="bi bi-eye"></i></button>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold small">Konfirmasi Password Baru</label>
                                <div class="input-group">
                                    <input type="password" name="pass_konf_adm" id="passAdmKonf" class="form-control" value="" autocomplete="new-password" placeholder="Ulangi password baru">
                                    <button type="button" class="btn btn-outline-secondary btn-toggle-pass" data-target="passAdmKonf"><i class="bi bi-eye"></i></button>
                                </div>
                            </div>
                        </div>
                        <div class="mt-4 pt-3 border-top d-flex justify-content-between">
                            <a href="dashboard.php?menu=dashboard" class="btn btn-outline-secondary fw-semibold">Kembali</a>
                            <button type="submit" name="simpan_profil_adm" value="1" class="btn btn-primary fw-semibold px-4">Simpan Profil</button>
                        </div>
                    </form>
                </div>

            <?php else: ?>
                <div class="row g-3">
                    <div class="col-12 col-md-3">
                        <a href="dashboard.php?menu=surat" class="stat-link-adm">
                            <div class="stat-box-adm">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span class="text-uppercase small fw-semibold text-muted">Total Surat Masuk</span>
                                    <i class="bi bi-envelope-paper text-secondary"></i>
                                </div>
                                <h2 class="fw-bold mb-0"><?= $total_surat_all; ?></h2>
                            </div>
                        </a>
                    </div>
                    <div class="col-12 col-md-3">
                        <a href="dashboard.php?menu=surat" class="stat-link-adm">
                            <div class="stat-box-adm">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span class="text-uppercase small fw-semibold text-muted">Sedang Diproses</span>
                                    <i class="bi bi-hourglass-split text-warning"></i>
                                </div>
                                <h2 class="fw-bold mb-0 text-warning"><?= $total_proses; ?></h2>
                            </div>
                        </a>
                    </div>
                    <div class="col-12 col-md-3">
                        <a href="dashboard.php?menu=surat" class="stat-link-adm">
                            <div class="stat-box-adm">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span class="text-uppercase small fw-semibold text-muted">Selesai / Terarsip</span>
                                    <i class="bi bi-check-circle text-success"></i>
                                </div>
                                <h2 class="fw-bold mb-0 text-success"><?= $total_selesai; ?></h2>
                            </div>
                        </a>
                    </div>
                    <div class="col-12 col-md-3">
                        <a href="dashboard.php?menu=users" class="stat-link-adm">
                            <div class="stat-box-adm">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span class="text-uppercase small fw-semibold text-muted">Total Akun Pengguna</span>
                                    <i class="bi bi-people text-primary"></i>
                                </div>
                                <h2 class="fw-bold mb-0 text-primary"><?= $total_users; ?></h2>
                            </div>
                        </a>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.querySelectorAll('.btn-toggle-pass').forEach(function (btn) {
            btn.addEventListener('click', function () {
                const targetId = btn.getAttribute('data-target');
                const inputEl = targetId ? document.getElementById(targetId) : null;
                const iconEl = btn.querySelector('i');
                if (!inputEl) return;
                const isPass = inputEl.getAttribute('type') === 'password';
                inputEl.setAttribute('type', isPass ? 'text' : 'password');
                if (iconEl) {
                    iconEl.classList.toggle('bi-eye', !isPass);
                    iconEl.classList.toggle('bi-eye-slash', isPass);
                }
            });
        });
    </script>
</body>
</html>