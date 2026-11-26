<?php
$host = (string)(getenv('DB_HOST') ?: 'localhost');
$user = (string)(getenv('DB_USER') ?: 'root');
$pass = (string)(getenv('DB_PASS') ?: '');
$db   = (string)(getenv('DB_NAME') ?: 'lsm_kominfo');

$koneksi = mysqli_connect($host, $user, $pass, $db);

if (!$koneksi) {
    error_log("Koneksi database gagal: " . mysqli_connect_error());
    die("Koneksi database gagal. Silakan periksa konfigurasi server.");
}

mysqli_set_charset($koneksi, "utf8mb4");

if (!function_exists('normalisasi_kode_bidang_akses')) {
    function normalisasi_kode_bidang_akses(string $nilai): string {
        $v = strtolower(trim($nilai));
        $map = [
            'kabid01' => 'bidang1',
            'bidang1' => 'bidang1',
            'kabid02' => 'bidang2',
            'bidang2' => 'bidang2',
            'kabid03' => 'bidang3',
            'bidang3' => 'bidang3',
            'kabid04' => 'bidang4',
            'bidang4' => 'bidang4',
        ];
        return $map[$v] ?? $v;
    }
}

if (!function_exists('ambil_profil_pejabat')) {
    function ambil_profil_pejabat($koneksi = null) {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }

        $username_raw = strtolower(trim((string)(
            $_SESSION['username'] ?? ($_SESSION['user'] ?? '')
        )));
        $username = normalisasi_kode_bidang_akses($username_raw);
        $alias_key = [
            'bidang1' => 'kabid01',
            'bidang2' => 'kabid02',
            'bidang3' => 'kabid03',
            'bidang4' => 'kabid04',
        ][$username] ?? $username;

        $map_pejabat = [
            'sekretaris'  => [
                'nama'   => 'Julian Pesik, SSTP',
                'nip'    => '19850804 201001 2 004',
                'bidang' => 'Sekretariat',
            ],
            'kepaladinas' => [
                'nama'   => 'Ronald Lumiu, SH',
                'nip'    => '19821113 200604 1 006',
                'bidang' => 'Kepala Dinas',
            ],
            'kabid01'     => [
                'nama'   => 'Ellen L. Megawe, S.Pd, M.Pd',
                'nip'    => '19770804 200312 2 006',
                'bidang' => 'Pengelolaan Informasi & Saluran Komunikasi Publik',
            ],
            'kabid02'     => [
                'nama'   => 'Deasy A. Tatengkeng, SE',
                'nip'    => '19811203 200903 2 007',
                'bidang' => 'Penyelenggaraan e-Government',
            ],
            'kabid03'     => [
                'nama'   => 'Raymond Saselah, ST',
                'nip'    => '19780508 200903 1 002',
                'bidang' => 'Pengembangan Sumber Daya Komunikasi & Informatika',
            ],
            'kabid04'     => [
                'nama'   => 'Novrita F. Dededaka, SSTP',
                'nip'    => '19861122 200412 2 001',
                'bidang' => 'Statistik Sektoral & Persandian',
            ],
        ];

        $fallback = $map_pejabat[$alias_key] ?? ($map_pejabat[$username_raw] ?? []);

        $nama   = trim((string)($_SESSION['nama'] ?? ($_SESSION['google_name'] ?? '')));
        $nip    = trim((string)($_SESSION['nip'] ?? ''));
        $bidang = trim((string)($_SESSION['bidang'] ?? ''));

        if (($nip === '' || $nama === '') && $koneksi && $username_raw !== '') {
            $u_esc   = mysqli_real_escape_string($koneksi, $username_raw);
            $cek_kol = mysqli_query($koneksi, "SHOW COLUMNS FROM `users` LIKE 'nip'");
            $ada_nip = ($cek_kol && mysqli_num_rows($cek_kol) > 0);

            $sql = "SELECT nama" . ($ada_nip ? ", nip" : "") . " FROM `users` WHERE LOWER(TRIM(username)) = '$u_esc' LIMIT 1";
            $res = mysqli_query($koneksi, $sql);
            if ($res && ($row = mysqli_fetch_assoc($res))) {
                if ($nama === '' && !empty($row['nama'])) {
                    $nama = trim((string)$row['nama']);
                }
                if ($nip === '' && $ada_nip && !empty($row['nip'])) {
                    $nip = trim((string)$row['nip']);
                }
            }
        }

        if ($nama === '') {
            $nama = $fallback['nama'] ?? (strtoupper($username_raw) ?: 'Pengguna');
        }
        if ($nip === '') {
            $nip = $fallback['nip'] ?? '-';
        }
        if ($bidang === '') {
            $bidang = $fallback['bidang'] ?? '';
        }

        $_SESSION['nama'] = $nama;
        $_SESSION['nip']  = $nip;
        if ($bidang !== '') {
            $_SESSION['bidang'] = $bidang;
        }

        return [
            'nama'   => $nama,
            'nip'    => $nip,
            'bidang' => $bidang,
        ];
    }
}

if (!function_exists('url_dashboard_sesuai_user')) {
    function url_dashboard_sesuai_user($prefix = '') {
        $role  = strtolower(str_replace([' ', '-'], '_', trim((string)($_SESSION['role'] ?? ($_SESSION['level'] ?? '')))));
        $uname = normalisasi_kode_bidang_akses((string)($_SESSION['username'] ?? ($_SESSION['user'] ?? '')));

        if (in_array($role, ['admin', 'administrator', 'superadmin'], true) || $uname === 'admin') {
            return $prefix . 'admin/dashboard.php';
        }
        if (in_array($role, ['sekretariat', 'front_office', 'fo', 'admin_surat'], true)) {
            return $prefix . 'sekretariat/dashboard.php';
        }
        if (in_array($role, ['sekretaris', 'sekban', 'sekretaris_dinas'], true)) {
            return $prefix . 'sekretaris/dashboard.php';
        }
        if (in_array($role, ['kepala_dinas', 'kadis'], true)) {
            return $prefix . 'kadis/dashboard.php';
        }
        if (strpos($role, 'kabid') !== false || strpos($role, 'bidang') !== false) {
            if ($uname === 'bidang1') {
                return $prefix . 'kabid/bidang1.php';
            }
            if ($uname === 'bidang2') {
                return $prefix . 'kabid/bidang2.php';
            }
            if ($uname === 'bidang3') {
                return $prefix . 'kabid/bidang3.php';
            }
            if ($uname === 'bidang4') {
                return $prefix . 'kabid/bidang4.php';
            }
            return $prefix . 'kabid/dashboard.php';
        }

        return $prefix . 'login.php';
    }
}

if (!function_exists('wajib_akses_halaman')) {
    function wajib_akses_halaman($koneksi, array $role_diizinkan, $kode_bidang_wajib = '', $prefix = '../') {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }

        $sudah_login = (
            isset($_SESSION['user']) ||
            isset($_SESSION['id_user']) ||
            isset($_SESSION['username']) ||
            isset($_SESSION['email'])
        );

        if (!$sudah_login) {
            header("Location: " . $prefix . "login.php");
            exit();
        }

        $role_aktif = strtolower(str_replace([' ', '-'], '_', trim((string)($_SESSION['role'] ?? ($_SESSION['level'] ?? '')))));
        $role_allow = array_map(function ($r) {
            return strtolower(str_replace([' ', '-'], '_', trim((string)$r)));
        }, $role_diizinkan);

        $tujuan_aman = url_dashboard_sesuai_user($prefix);

        if (!in_array($role_aktif, $role_allow, true)) {
            $tujuan_js = htmlspecialchars($tujuan_aman, ENT_QUOTES, 'UTF-8');
            echo "<script>alert('Akses ditolak! Anda tidak memiliki hak akses ke halaman ini.'); window.location='{$tujuan_js}';</script>";
            exit();
        }

        if ($kode_bidang_wajib !== '') {
            $uname_aktif = normalisasi_kode_bidang_akses((string)($_SESSION['username'] ?? ($_SESSION['user'] ?? '')));
            $kode_wajib  = normalisasi_kode_bidang_akses((string)$kode_bidang_wajib);
            if ($uname_aktif !== $kode_wajib) {
                $tujuan_js = htmlspecialchars($tujuan_aman, ENT_QUOTES, 'UTF-8');
                echo "<script>alert('Akses ditolak! Halaman ini khusus untuk " . htmlspecialchars(strtoupper($kode_bidang_wajib), ENT_QUOTES, 'UTF-8') . ".'); window.location='{$tujuan_js}';</script>";
                exit();
            }
        }
    }
}

if (!function_exists('kirim_otp_smtp')) {
    function kirim_otp_smtp(string $email_tujuan, string $kode_otp, string $nama_akun = 'Pengguna', string &$detail_status = ''): bool {
        $subjek = 'Kode Verifikasi OTP Pengaturan Profil - Layanan Surat Diskominfo Sangihe';
        $pesan  = "Halo {$nama_akun},\n\nKode OTP verifikasi email aktif Anda adalah: {$kode_otp}\nKode ini berlaku selama 10 menit.\n\nAbaikan pesan ini jika Anda tidak meminta kode OTP.";

        $smtp_host = (string)(getenv('SMTP_HOST') ?: 'smtp.gmail.com');
        $smtp_port = (int)(getenv('SMTP_PORT') ?: 587);
        $smtp_user = (string)(getenv('SMTP_USER') ?: '');
        $smtp_pass = (string)(getenv('SMTP_PASS') ?: '');
        $smtp_from = (string)(getenv('SMTP_FROM') ?: '');
        $smtp_nama = (string)(getenv('SMTP_NAME') ?: 'Portal E-Office Diskominfo Sangihe');

        foreach ([__DIR__ . '/config_smtp.php', __DIR__ . '/config/smtp.php', __DIR__ . '/config/mail.php'] as $cfg_file) {
            if (file_exists($cfg_file) && is_file($cfg_file)) {
                include_once $cfg_file;
            }
        }

        $smtp_host = trim((string)($GLOBALS['smtp_host'] ?? $smtp_host));
        $smtp_port = (int)($GLOBALS['smtp_port'] ?? $smtp_port);
        $smtp_user = trim((string)($GLOBALS['smtp_user'] ?? $smtp_user));
        $smtp_pass = str_replace(' ', '', (string)($GLOBALS['smtp_pass'] ?? $smtp_pass));
        $smtp_from = trim((string)($GLOBALS['smtp_from'] ?? $smtp_from));
        $smtp_nama = trim((string)($GLOBALS['smtp_nama'] ?? $smtp_nama));

        if ($smtp_user === '' || $smtp_pass === '') {
            $detail_status = "Konfigurasi SMTP_USER atau SMTP_PASS belum diatur di environment atau config_smtp.php";
            return false;
        }

        if ($smtp_from === '' || stripos($smtp_from, 'akun_pengirim') !== false) {
            $smtp_from = $smtp_user;
        }

        $baca_respon = function ($fp, array $kode_valid): array {
            $data = '';
            while (!feof($fp)) {
                $baris = fgets($fp, 515);
                if ($baris === false) {
                    break;
                }
                $data .= $baris;
                if (strlen($baris) >= 4 && $baris[3] === ' ') {
                    break;
                }
            }
            $kode = substr($data, 0, 3);
            return [in_array($kode, $kode_valid, true), trim($data)];
        };

        $kirim_perintah = function ($fp, string $cmd, array $kode_valid) use ($baca_respon): array {
            fwrite($fp, $cmd . "\r\n");
            return $baca_respon($fp, $kode_valid);
        };

        $context = stream_context_create([
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ],
        ]);

        $transport = ($smtp_port === 465) ? 'ssl://' : 'tcp://';
        $errno = 0;
        $errstr = '';
        $fp = @stream_socket_client($transport . $smtp_host . ':' . $smtp_port, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $context);
        if (!$fp) {
            $detail_status = "Koneksi SMTP gagal ({$errstr})";
            return false;
        }

        stream_set_timeout($fp, 15);
        [$ok, $resp] = $baca_respon($fp, ['220']);
        if (!$ok) {
            fclose($fp);
            $detail_status = "Greeting SMTP ditolak ({$resp})";
            return false;
        }

        $host_lokal = $_SERVER['SERVER_NAME'] ?? 'localhost';
        [$ok, $resp] = $kirim_perintah($fp, "EHLO {$host_lokal}", ['250']);
        if (!$ok) {
            fclose($fp);
            $detail_status = "EHLO gagal ({$resp})";
            return false;
        }

        if ($smtp_port !== 465) {
            [$ok, $resp] = $kirim_perintah($fp, "STARTTLS", ['220']);
            if (!$ok) {
                fclose($fp);
                $detail_status = "STARTTLS gagal ({$resp})";
                return false;
            }
            $crypto_ok = @stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT);
            if (!$crypto_ok) {
                $crypto_ok = @stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            }
            if (!$crypto_ok) {
                fclose($fp);
                $detail_status = "Enkripsi TLS gagal diaktifkan";
                return false;
            }
            [$ok, $resp] = $kirim_perintah($fp, "EHLO {$host_lokal}", ['250']);
            if (!$ok) {
                fclose($fp);
                $detail_status = "EHLO setelah TLS gagal ({$resp})";
                return false;
            }
        }

        [$ok, $resp] = $kirim_perintah($fp, "AUTH LOGIN", ['334']);
        if (!$ok) {
            fclose($fp);
            $detail_status = "AUTH LOGIN gagal ({$resp})";
            return false;
        }

        [$ok, $resp] = $kirim_perintah($fp, base64_encode($smtp_user), ['334']);
        if (!$ok) {
            fclose($fp);
            $detail_status = "Username SMTP ditolak ({$resp})";
            return false;
        }

        [$ok, $resp] = $kirim_perintah($fp, base64_encode($smtp_pass), ['235']);
        if (!$ok) {
            fclose($fp);
            $detail_status = "App Password SMTP ditolak ({$resp})";
            return false;
        }

        [$ok, $resp] = $kirim_perintah($fp, "MAIL FROM:<{$smtp_from}>", ['250']);
        if (!$ok) {
            fclose($fp);
            $detail_status = "MAIL FROM ditolak ({$resp})";
            return false;
        }

        [$ok, $resp] = $kirim_perintah($fp, "RCPT TO:<{$email_tujuan}>", ['250', '251']);
        if (!$ok) {
            fclose($fp);
            $detail_status = "RCPT TO ditolak ({$resp})";
            return false;
        }

        [$ok, $resp] = $kirim_perintah($fp, "DATA", ['354']);
        if (!$ok) {
            fclose($fp);
            $detail_status = "DATA ditolak ({$resp})";
            return false;
        }

        $nama_header   = '=?UTF-8?B?' . base64_encode($smtp_nama) . '?=';
        $subjek_header = '=?UTF-8?B?' . base64_encode($subjek) . '?=';
        $body_bersih   = preg_replace("/\r\n|\r|\n/", "\r\n", $pesan);
        $body_bersih   = preg_replace('/^\./m', '..', $body_bersih);

        $headers_dan_body =
            "Date: " . date('r') . "\r\n" .
            "From: {$nama_header} <{$smtp_from}>\r\n" .
            "To: <{$email_tujuan}>\r\n" .
            "Subject: {$subjek_header}\r\n" .
            "MIME-Version: 1.0\r\n" .
            "Content-Type: text/plain; charset=UTF-8\r\n" .
            "Content-Transfer-Encoding: 8bit\r\n\r\n" .
            $body_bersih . "\r\n.\r\n";

        fwrite($fp, $headers_dan_body);
        [$ok, $resp] = $baca_respon($fp, ['250']);
        @fwrite($fp, "QUIT\r\n");
        fclose($fp);

        if (!$ok) {
            $detail_status = "Pengiriman isi email gagal ({$resp})";
            return false;
        }

        $detail_status = 'Terkirim via SMTP Gmail';
        return true;
    }
}

if (!function_exists('set_notifikasi_eoffice')) {
    function set_notifikasi_eoffice(string $pesan, string $tipe = 'info', string $judul = ''): void {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $_SESSION['flash_notif_eoffice'] = [
            'pesan' => $pesan,
            'tipe'  => $tipe,
            'judul' => $judul,
        ];
    }
}

if (!function_exists('ambil_notifikasi_flash_eoffice')) {
    function ambil_notifikasi_flash_eoffice(): ?array {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        if (!empty($_SESSION['flash_notif_eoffice']) && is_array($_SESSION['flash_notif_eoffice'])) {
            $data = $_SESSION['flash_notif_eoffice'];
            unset($_SESSION['flash_notif_eoffice']);
            return $data;
        }
        return null;
    }
}

if (!function_exists('css_notifikasi_eoffice')) {
    function css_notifikasi_eoffice(): string {
        return <<<'CSS'
<style id="notif-eoffice-global-style">
.notif-eoffice {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 12px 15px;
    border-radius: 12px;
    border: 1px solid transparent;
    border-left-width: 4px;
    box-shadow: 0 8px 20px rgba(15, 23, 42, 0.14);
    font-size: 0.875rem;
    line-height: 1.48;
    animation: notifEofficeMasuk 0.24s ease-out;
    position: relative;
}
.notif-eoffice .notif-ikon {
    width: 32px;
    height: 32px;
    border-radius: 9px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    font-size: 1rem;
    font-weight: 700;
}
.notif-eoffice .notif-teks {
    flex: 1;
    min-width: 0;
    word-break: break-word;
}
.notif-eoffice .notif-judul {
    font-weight: 700;
    font-size: 0.9rem;
    margin-bottom: 2px;
    display: block;
}
.notif-eoffice .btn-close {
    padding: 6px;
    font-size: 0.72rem;
    opacity: 0.65;
    flex-shrink: 0;
}
.notif-eoffice .btn-close:hover {
    opacity: 1;
}
.notif-sukses {
    background-color: #ecfdf5;
    border-color: #a7f3d0;
    border-left-color: #10b981;
    color: #065f46;
}
.notif-sukses .notif-ikon {
    background-color: #d1fae5;
    color: #059669;
}
.notif-error {
    background-color: #fef2f2;
    border-color: #fecaca;
    border-left-color: #ef4444;
    color: #991b1b;
}
.notif-error .notif-ikon {
    background-color: #fee2e2;
    color: #dc2626;
}
.notif-peringatan {
    background-color: #fffbeb;
    border-color: #fde68a;
    border-left-color: #f59e0b;
    color: #92400e;
}
.notif-peringatan .notif-ikon {
    background-color: #fef3c7;
    color: #d97706;
}
.notif-info {
    background-color: #eff6ff;
    border-color: #bfdbfe;
    border-left-color: #3b82f6;
    color: #1e40af;
}
.notif-info .notif-ikon {
    background-color: #dbeafe;
    color: #2563eb;
}
@keyframes notifEofficeMasuk {
    from {
        opacity: 0;
        transform: translateY(-6px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}
</style>
CSS;
    }
}

if (!function_exists('tampilkan_notifikasi_eoffice')) {
    function tampilkan_notifikasi_eoffice(string $pesan, string $tipe = 'info', string $judul = '', bool $bisa_tutup = true): string {
        if (trim($pesan) === '') {
            return '';
        }

        $tipe_bersih = strtolower(trim($tipe));
        if (in_array($tipe_bersih, ['success', 'sukses'], true)) {
            $kelas_tipe    = 'notif-sukses';
            $judul_default = 'Berhasil';
            $simbol        = '✓';
        } elseif (in_array($tipe_bersih, ['danger', 'error', 'gagal'], true)) {
            $kelas_tipe    = 'notif-error';
            $judul_default = 'Perhatian';
            $simbol        = '!';
        } elseif (in_array($tipe_bersih, ['warning', 'peringatan', 'warn'], true)) {
            $kelas_tipe    = 'notif-peringatan';
            $judul_default = 'Peringatan';
            $simbol        = '⚠';
        } else {
            $kelas_tipe    = 'notif-info';
            $judul_default = 'Informasi';
            $simbol        = 'i';
        }

        $judul_tampil = $judul !== '' ? $judul : $judul_default;
        $tombol_tutup = $bisa_tutup
            ? '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>'
            : '';

        return '<div class="alert notif-eoffice ' . $kelas_tipe . ' alert-dismissible fade show mb-3" role="alert">'
            . '<span class="notif-ikon" aria-hidden="true">' . htmlspecialchars($simbol, ENT_QUOTES, 'UTF-8') . '</span>'
            . '<div class="notif-teks">'
            . '<span class="notif-judul">' . htmlspecialchars($judul_tampil, ENT_QUOTES, 'UTF-8') . '</span>'
            . '<span>' . htmlspecialchars($pesan, ENT_QUOTES, 'UTF-8') . '</span>'
            . '</div>'
            . $tombol_tutup
            . '</div>';
    }
}

if (!function_exists('render_notifikasi_otomatis_eoffice')) {
    function render_notifikasi_otomatis_eoffice(): string {
        $html = '';
        $flash = ambil_notifikasi_flash_eoffice();
        if ($flash !== null && !empty($flash['pesan'])) {
            $html .= tampilkan_notifikasi_eoffice(
                (string)$flash['pesan'],
                (string)($flash['tipe'] ?? 'info'),
                (string)($flash['judul'] ?? '')
            );
        }

        if (!empty($_GET['error'])) {
            $html .= tampilkan_notifikasi_eoffice((string)$_GET['error'], 'danger', 'Terjadi Kendala');
        } elseif (!empty($_GET['sukses'])) {
            $html .= tampilkan_notifikasi_eoffice((string)$_GET['sukses'], 'success', 'Berhasil');
        } elseif (!empty($_GET['notif'])) {
            $html .= tampilkan_notifikasi_eoffice((string)$_GET['notif'], 'info', 'Pemberitahuan');
        }

        return $html;
    }
}

if (!function_exists('js_notifikasi_global_eoffice')) {
    function js_notifikasi_global_eoffice(): string {
        return <<<'HTML'
<script id="notif-eoffice-alert-override">
(function () {
    if (window.__notifEofficeAlertInstalled) return;
    window.__notifEofficeAlertInstalled = true;

    function pastikanWadahToast() {
        var wrap = document.getElementById('notif-eoffice-toast-wrap');
        if (!wrap) {
            wrap = document.createElement('div');
            wrap.id = 'notif-eoffice-toast-wrap';
            wrap.style.position = 'fixed';
            wrap.style.top = '20px';
            wrap.style.right = '20px';
            wrap.style.zIndex = '99999';
            wrap.style.maxWidth = '420px';
            wrap.style.width = 'calc(100% - 32px)';
            wrap.style.display = 'flex';
            wrap.style.flexDirection = 'column';
            wrap.style.gap = '10px';
            if (document.body) {
                document.body.appendChild(wrap);
            }
        }
        return wrap;
    }

    function deteksiTipePesan(teks) {
        var s = String(teks || '').toLowerCase();
        if (s.indexOf('gagal') !== -1 || s.indexOf('ditolak') !== -1 || s.indexOf('error') !== -1 || s.indexOf('tidak valid') !== -1 || s.indexOf('tidak ditemukan') !== -1) {
            return { kelas: 'notif-error', judul: 'Perhatian', simbol: '!' };
        }
        if (s.indexOf('peringatan') !== -1 || s.indexOf('wajib') !== -1 || s.indexOf('kedaluwarsa') !== -1) {
            return { kelas: 'notif-peringatan', judul: 'Peringatan', simbol: '⚠' };
        }
        if (s.indexOf('berhasil') !== -1 || s.indexOf('sukses') !== -1 || s.indexOf('tersimpan') !== -1 || s.indexOf('didisposisikan') !== -1) {
            return { kelas: 'notif-sukses', judul: 'Berhasil', simbol: '✓' };
        }
        return { kelas: 'notif-info', judul: 'Informasi', simbol: 'i' };
    }

    window.tampilkanToastEoffice = function (pesan, durasiMs) {
        var isi = String(pesan || '').trim();
        if (!isi) return;

        var wrap = pastikanWadahToast();
        if (!wrap) return;

        var meta = deteksiTipePesan(isi);
        var ms   = (typeof durasiMs === 'number' && durasiMs > 0) ? durasiMs : 3800;

        var card = document.createElement('div');
        card.className = 'notif-eoffice ' + meta.kelas;
        card.setAttribute('role', 'alert');

        var ikon = document.createElement('span');
        ikon.className = 'notif-ikon';
        ikon.setAttribute('aria-hidden', 'true');
        ikon.textContent = meta.simbol;

        var teksBox = document.createElement('div');
        teksBox.className = 'notif-teks';

        var judulEl = document.createElement('span');
        judulEl.className = 'notif-judul';
        judulEl.textContent = meta.judul;

        var isiEl = document.createElement('span');
        isiEl.textContent = isi;

        teksBox.appendChild(judulEl);
        teksBox.appendChild(isiEl);

        var btnTutup = document.createElement('button');
        btnTutup.type = 'button';
        btnTutup.className = 'btn-close';
        btnTutup.setAttribute('aria-label', 'Tutup');

        function tutupCard() {
            if (!card.parentNode) return;
            card.style.opacity = '0';
            card.style.transform = 'translateY(-6px)';
            card.style.transition = 'opacity 0.2s ease, transform 0.2s ease';
            setTimeout(function () {
                if (card.parentNode) card.parentNode.removeChild(card);
            }, 210);
        }

        btnTutup.addEventListener('click', tutupCard);

        card.appendChild(ikon);
        card.appendChild(teksBox);
        card.appendChild(btnTutup);
        wrap.appendChild(card);

        setTimeout(tutupCard, ms);
    };

    var antreanAlert = [];
    function prosesAntreanAlert() {
        while (antreanAlert.length > 0 && document.body) {
            window.tampilkanToastEoffice(antreanAlert.shift(), 3800);
        }
    }

    window.alert = function (pesan) {
        if (!document.body) {
            antreanAlert.push(pesan);
            document.addEventListener('DOMContentLoaded', prosesAntreanAlert, { once: true });
            return;
        }
        window.tampilkanToastEoffice(pesan, 3800);
    };
})();
</script>
HTML;
    }
}

if (!defined('NOTIF_EOFFICE_OB_REGISTERED')) {
    define('NOTIF_EOFFICE_OB_REGISTERED', true);
    ob_start(function (string $buffer): string {
        if (stripos($buffer, '<body') === false) {
            return $buffer;
        }
        $assets = css_notifikasi_eoffice() . "\n" . js_notifikasi_global_eoffice();
        if (stripos($buffer, '</body>') !== false) {
            return preg_replace('/<\/body>/i', $assets . "\n</body>", $buffer, 1);
        }
        return $buffer . "\n" . $assets;
    });
}