<header class="topbar">
    <div class="topbar-left">
        <button class="menu-toggle" type="button">
            <i class="fa-solid fa-bars"></i>
        </button>
        <h1><?= strtoupper(htmlspecialchars($_GET['mod'] ?? 'home')) ?></h1>
    </div>

    <div class="topbar-right">
        <i class="fa-solid fa-magnifying-glass"></i>
        <i class="fa-solid fa-bell"></i>
        <i class="fa-solid fa-user"></i>
    </div>
</header>