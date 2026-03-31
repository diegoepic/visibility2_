<?php
$currentMod = $_GET['mod'] ?? 'home';
$currentSub = $_GET['sub'] ?? '';
?>

<aside class="sidebar">
    <div class="brand-box">
        <img src="https://visibility.cl/visibility2/app/assets/imagenes/logo/logo-Visibility.png" alt="Logo" class="brand-logo">
        <div class="brand-text">
            <span>Visibility</span>
        </div>
    </div>

    <div class="user-box">
        <div class="user-avatar">
            <i class="fa-solid fa-user"></i>
        </div>
        <div class="user-info">
            <strong><?= htmlspecialchars($_SESSION['nombre'] ?? 'MANUEL GOMEZ') ?></strong>
            <span><?= htmlspecialchars($_SESSION['empresa'] ?? 'MENTECREATIVA') ?></span>
        </div>
    </div>

    <nav class="sidebar-nav">

        <a href="?mod=home" class="nav-item <?= $currentMod === 'home' ? 'active' : '' ?>">
            <span class="nav-icon"><i class="fa-solid fa-house"></i></span>
            <span class="nav-label">Home</span>
            <span class="nav-dot"></span>
        </a>

        <a href="?mod=login" class="nav-item <?= $currentMod === 'login' ? 'active' : '' ?>">
            <span class="nav-icon"><i class="fa-solid fa-users"></i></span>
            <span class="nav-label">Usuario</span>
            <span class="nav-dot"></span>
        </a>

        <a href="?mod=favoritos" class="nav-item <?= $currentMod === 'favoritos' ? 'active' : '' ?>">
            <span class="nav-icon"><i class="fa-solid fa-building"></i></span>
            <span class="nav-label">Locales</span>
            <span class="nav-dot"></span>
        </a>

        <a href="?mod=mail" class="nav-item <?= $currentMod === 'mail' ? 'active' : '' ?>">
            <span class="nav-icon"><i class="fa-solid fa-envelope"></i></span>
            <span class="nav-label">Mail</span>
            <span class="nav-dot"></span>
        </a>

        <div class="has-submenu <?= $currentMod === 'servicios' ? 'open' : '' ?>">
            <div class="nav-item nav-item-parent <?= $currentMod === 'servicios' ? 'active' : '' ?>">
                <a href="?mod=servicios" class="nav-link-main">
                    <span class="nav-icon"><i class="fa-solid fa-screwdriver-wrench"></i></span>
                    <span class="nav-label">Servicios</span>
                </a>

                <button class="submenu-toggle" type="button" aria-label="Expandir submenú">
                    <i class="fa-solid fa-chevron-down"></i>
                </button>
            </div>

            <div class="submenu">
                <a href="?mod=servicios&sub=hosting" class="submenu-item <?= $currentSub === 'hosting' ? 'active' : '' ?>">
                    Hosting
                </a>
                <a href="?mod=servicios&sub=soporte" class="submenu-item <?= $currentSub === 'soporte' ? 'active' : '' ?>">
                    Soporte
                </a>
                <a href="?mod=servicios&sub=integraciones" class="submenu-item <?= $currentSub === 'integraciones' ? 'active' : '' ?>">
                    Integraciones
                </a>
            </div>
        </div>

    </nav>
</aside>