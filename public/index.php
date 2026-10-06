<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Auth.php';

if (!is_logged_in()) {
    header('Location: login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>MOP Board — Dashboard MOP Project</title>
<link rel="stylesheet" href="css/app.css">
<!--
  Chart.js is vendored locally (public/js/vendor/chart.umd.min.js, pinned v4.4.4)
  instead of loaded from a CDN so the CSP script-src can stay 'self' with no
  third-party origin allow-listed. The integrity attribute (SRI, SHA-384) still
  guards against local tampering: the browser refuses to execute the file if
  its hash doesn't match. Regenerate after upgrading the vendored file with:
    openssl dgst -sha384 -binary public/js/vendor/chart.umd.min.js | openssl base64 -A
-->
<script src="js/vendor/chart.umd.min.js"
        integrity="sha384-NrKB+u6Ts6AtkIhwPixiKTzgSKNblyhlk0Sohlgar9UHUBzai/sgnNNWWd291xqt"
        crossorigin="anonymous"></script>
</head>
<body>
<div id="app">
  <aside class="sidebar">
    <div class="sidebar-brand">
      <span class="brand-mark">MOP</span>
      <div>
        <div class="brand-title">MOP Board</div>
        <div class="brand-sub">Method of Procedure</div>
      </div>
    </div>
    <button id="btnNewProject" class="btn btn-primary btn-block">+ Project Baru</button>
    <div class="sidebar-search">
      <input type="text" id="projectSearch" placeholder="Cari project...">
    </div>
    <div id="projectList" class="project-list"></div>
    <a href="logout.php" class="btn btn-ghost btn-block">Logout (<?= htmlspecialchars(ADMIN_USERNAME, ENT_QUOTES, 'UTF-8') ?>)</a>
  </aside>

  <main class="main">
    <div id="emptyState" class="empty-state">
      <h2>Belum ada project dipilih</h2>
      <p>Buat project baru atau pilih salah satu di sidebar untuk mulai mengelola MOP.</p>
    </div>

    <div id="projectView" class="project-view hidden">
      <header class="topbar">
        <div class="topbar-title">
          <h1 id="projectName">—</h1>
          <p id="projectDesc" class="muted"></p>
        </div>
        <div class="topbar-actions">
          <button id="btnEditProject" class="btn btn-ghost">Edit Project</button>
          <button id="btnDeleteProject" class="btn btn-ghost btn-danger">Hapus</button>
        </div>
      </header>

      <nav class="tabs">
        <button class="tab-btn active" data-tab="dashboard">Dashboard</button>
        <button class="tab-btn" data-tab="data">Data MOP</button>
        <button class="tab-btn" data-tab="columns">Kolom</button>
        <button class="tab-btn" data-tab="io">Import / Export</button>
      </nav>

      <section id="tab-dashboard" class="tab-panel active"></section>
      <section id="tab-data" class="tab-panel"></section>
      <section id="tab-columns" class="tab-panel"></section>
      <section id="tab-io" class="tab-panel"></section>
    </div>
  </main>
</div>

<div id="modalRoot"></div>
<div id="toastRoot" class="toast-root"></div>

<script src="js/app.js"></script>
</body>
</html>
