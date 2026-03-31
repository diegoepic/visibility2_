
document.addEventListener('DOMContentLoaded', function () {
  const dropdowns = document.querySelectorAll('.dropdown');
  const navItems = document.querySelectorAll('.nav-item');

  function clearActive() {
    navItems.forEach(item => item.classList.remove('active'));
  }

  // Click en items normales y botones dropdown
  navItems.forEach(item => {
    item.addEventListener('click', function () {
      clearActive();
      this.classList.add('active');
    });
  });

  // Manejo dropdown
  dropdowns.forEach(dropdown => {
    const trigger = dropdown.querySelector('.dropdown-toggle');
    const links = dropdown.querySelectorAll('.dropdown-link');

    trigger.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();

      const isOpen = dropdown.classList.contains('open');

      document.querySelectorAll('.dropdown.open').forEach(item => {
        if (item !== dropdown) {
          item.classList.remove('open');
          const btn = item.querySelector('.dropdown-toggle');
          if (btn) btn.setAttribute('aria-expanded', 'false');
        }
      });

      clearActive();
      trigger.classList.add('active');

      dropdown.classList.toggle('open', !isOpen);
      trigger.setAttribute('aria-expanded', !isOpen ? 'true' : 'false');
    });

    links.forEach(link => {
      link.addEventListener('click', function () {
        clearActive();
        trigger.classList.add('active');
      });
    });
  });

  document.addEventListener('click', function (e) {
    document.querySelectorAll('.dropdown.open').forEach(dropdown => {
      if (!dropdown.contains(e.target)) {
        dropdown.classList.remove('open');
        const btn = dropdown.querySelector('.dropdown-toggle');
        if (btn) btn.setAttribute('aria-expanded', 'false');
      }
    });
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      document.querySelectorAll('.dropdown.open').forEach(dropdown => {
        dropdown.classList.remove('open');
        const btn = dropdown.querySelector('.dropdown-toggle');
        if (btn) btn.setAttribute('aria-expanded', 'false');
      });
    }
  });
});