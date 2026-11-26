<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function normalisasi_role_surat(string $role): string {
    $r = strtolower(trim($role));
    $r = preg_replace('/\s+/', ' ', $r) ?? $r;

    if (in_array($r, ['sekretariat', 'admin', 'tata usaha', 'tu', 'front office'], true)) {
        return 'sekretariat';
    }
    if (in_array($r, ['sekretaris', 'sekban', 'sekdin'], true)) {
        return 'sekretaris';
    }
    if (in_array($r, ['kepala dinas', 'kadis', 'kadin', 'pimpinan'], true)) {
        return 'kepala_dinas';
    }
    if (str_starts_with($r, 'kabid') || str_starts_with($r, 'kepala bidang')) {
        return 'kabid';
    }
    if (in_array($r, ['pengirim', 'user', 'pemohon', 'eksternal'], true)) {
        return 'pengirim';
    }
    return $r;
}

function ambil_role_aktif(): string {
    $raw = $_SESSION['role']
        ?? $_SESSION['level']
        ?? $_SESSION['jabatan']
        ?? (is_array($_SESSION['user'] ?? null) ? ($_SESSION['user']['role'] ?? $_SESSION['user']['level'] ?? '') : '');
    return normalisasi_role_surat((string)$raw);
}

function ambil_bidang_aktif(): string {
    $raw = $_SESSION['bidang']
        ?? $_SESSION['nama_bidang']
        ?? $_SESSION['unit_kerja']
        ?? (is_array($_SESSION['user'] ?? null) ? ($_SESSION['user']['bidang'] ?? $_SESSION['user']['nama_bidang'] ?? '') : '');
    return strtolower(trim(preg_replace('/\s+/', ' ', (string)$raw) ?? (string)$raw));
}

function adalah_surat_rahasia(array $row): bool {
    $sifat = strtolower(trim((string)($row['sifat_surat'] ?? $row['sifat'] ?? '')));
    return ($sifat === 'rahasia');
}

function boleh_lihat_isi_surat(
    array $row,
    ?string $role = null,
    ?string $bidang = null,
    int $idLogin = 0,
    string $emailLogin = '',
    string $userLogin = ''
): bool {
    $roleAktif   = $role !== null ? normalisasi_role_surat($role) : ambil_role_aktif();
    $bidangAktif = $bidang !== null ? strtolower(trim($bidang)) : ambil_bidang_aktif();

    if (!adalah_surat_rahasia($row)) {
        return true;
    }

    if (in_array($roleAktif, ['sekretaris', 'kepala_dinas'], true)) {
        return true;
    }

    if ($roleAktif === 'sekretariat') {
        return false;
    }

    if ($roleAktif === 'kabid') {
        if ($bidangAktif === '') {
            return false;
        }
        $tujuan = strtolower(trim((string)(
            $row['disposisi_kepada']
            ?? $row['tujuan_disposisi']
            ?? $row['tujuan_bidang']
            ?? $row['bidang_tujuan']
            ?? ''
        )));
        return ($tujuan !== '' && (str_contains($tujuan, $bidangAktif) || str_contains($bidangAktif, $tujuan)));
    }

    if ($roleAktif === 'pengirim') {
        $idOwner = (int)($row['user_id'] ?? $row['id_user'] ?? $row['id_pengirim'] ?? $row['pengirim_id'] ?? 0);
        if ($idLogin > 0 && $idOwner > 0 && $idLogin === $idOwner) {
            return true;
        }
        $emailOwner = strtolower(trim((string)($row['email_pengirim'] ?? $row['email'] ?? $row['pengirim_email'] ?? '')));
        if ($emailLogin !== '' && $emailOwner !== '' && strtolower(trim($emailLogin)) === $emailOwner) {
            return true;
        }
        $userOwner = strtolower(trim((string)($row['nama_pengirim'] ?? $row['username'] ?? '')));
        if ($userLogin !== '' && $userOwner !== '' && strtolower(trim($userLogin)) === $userOwner) {
            return true;
        }
        return false;
    }

    return false;
}

function url_lihat_surat(int $idSurat, string $prefix = ''): string {
    if ($idSurat <= 0) {
        return '#';
    }
    return rtrim($prefix, '/') . ($prefix !== '' ? '/' : '') . 'lihat_surat.php?id=' . $idSurat;
}