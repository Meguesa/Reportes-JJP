<?php

declare(strict_types=1);

function reportes_render_header(array $user): void
{
    $name = htmlspecialchars(trim((string) ($user['name'] ?? 'Usuario')), ENT_QUOTES, 'UTF-8');
    $email = htmlspecialchars(strtolower(trim((string) ($user['email'] ?? ''))), ENT_QUOTES, 'UTF-8');
    ?>
    <header class="reportes-header">
      <div class="shell reportes-header-inner">
        <div class="reportes-brand">
          <img class="reportes-header-logo" src="/mapa/assets/logo.jpg" alt="Jardines de Juan Pablo">
          <div class="reportes-identity">
            <strong>Reportes</strong>
            <span>Portal Interno JdJP · Jardines de Juan Pablo</span>
          </div>
        </div>

        <div class="reportes-header-context">Consulta y seguimiento de reportes</div>

        <div class="reportes-header-actions">
          <a class="header-action" href="/">Regresar al portal</a>
          <details class="account-menu">
            <summary class="account-trigger" aria-label="Abrir menú de usuario" title="<?= $name ?>">
              <svg viewBox="0 0 24 24" aria-hidden="true">
                <circle cx="12" cy="8" r="4" fill="currentColor" />
                <path d="M4 20c0-4.1 3.6-6 8-6s8 1.9 8 6v1H4z" fill="currentColor" />
              </svg>
            </summary>
            <div class="account-menu-panel">
              <div class="account-menu-info">
                <strong><?= $name ?></strong>
                <span><?= $email ?></span>
              </div>
              <a class="account-menu-logout" href="/logout.php">Cerrar sesión</a>
            </div>
          </details>
        </div>
      </div>
    </header>
    <?php
}
