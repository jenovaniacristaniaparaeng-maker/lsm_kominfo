<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>E-Office Diskominfo Sangihe</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { background-color: #f4f6f9; font-family: 'Inter', sans-serif; display: flex; height: 100vh; overflow: hidden; }
        .sidebar { width: 260px; background-color: #0b132b; color: #ffffff; display: flex; flex-direction: column; justify-content: space-between; padding: 20px 15px; height: 100vh; position: fixed; top: 0; left: 0; z-index: 100; }
        .sidebar-brand { font-weight: 700; font-size: 1.1rem; display: flex; align-items: center; gap: 10px; padding-bottom: 20px; border-bottom: 1px solid #1c2541; }
        .sidebar-brand small { display: block; font-size: 0.65rem; color: #8d99ae; }
        .nav-menu { list-style: none; padding: 0; margin: 20px 0; flex-grow: 1; }
        .nav-item { margin-bottom: 8px; }
        .nav-link { color: #8d99ae; text-decoration: none; display: flex; align-items: center; gap: 12px; padding: 10px 15px; border-radius: 8px; font-size: 0.9rem; font-weight: 500; transition: all 0.2s; }
        .nav-link:hover, .nav-link.active { background-color: #3a86ff; color: #ffffff; }
        .sidebar-footer { border-top: 1px solid #1c2541; padding-top: 15px; }
        .logout-link { color: #ff6b6b; text-decoration: none; display: flex; align-items: center; gap: 10px; font-size: 0.9rem; padding: 8px 15px; border-radius: 8px; }
        .main-content { margin-left: 260px; flex-grow: 1; padding: 25px; overflow-y: auto; background-color: #f8f9fa; width: calc(100% - 260px); }
        .top-navbar { background: #ffffff; border-radius: 12px; padding: 15px 25px; box-shadow: 0 2px 10px rgba(0,0,0,0.03); display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; }
        .user-badge { display: flex; align-items: center; gap: 12px; }
        .user-avatar { width: 40px; height: 40px; background-color: #3a86ff; color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700; }
        .dashboard-card { background: #ffffff; border-radius: 12px; padding: 25px; box-shadow: 0 2px 10px rgba(0,0,0,0.03); border: 1px solid #eaeaea; }
        .table th { font-size: 0.85rem; font-weight: 600; color: #495057; background-color: #f8f9fa; }
        .table td { font-size: 0.85rem; vertical-align: middle; }
    </style>
</head>
<body>