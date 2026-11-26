<?php
$smtp_host = (string)(getenv('SMTP_HOST') ?: 'smtp.gmail.com');
$smtp_port = (int)(getenv('SMTP_PORT') ?: 587);
$smtp_user = (string)(getenv('SMTP_USER') ?: '');
$smtp_pass = (string)(getenv('SMTP_PASS') ?: '');
$smtp_from = (string)(getenv('SMTP_FROM') ?: $smtp_user);
$smtp_nama = (string)(getenv('SMTP_FROM_NAME') ?: 'Portal E-Office Diskominfo Sangihe');

$GLOBALS['smtp_host'] = $smtp_host;
$GLOBALS['smtp_port'] = $smtp_port;
$GLOBALS['smtp_user'] = $smtp_user;
$GLOBALS['smtp_pass'] = $smtp_pass;
$GLOBALS['smtp_from'] = $smtp_from;
$GLOBALS['smtp_nama'] = $smtp_nama;