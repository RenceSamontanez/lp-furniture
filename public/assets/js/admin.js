/* ============================================================
   LP Furniture — Admin Panel JS
   Sidebar toggle for mobile
   ============================================================ */
   document.addEventListener('DOMContentLoaded', function () {
    const sidebar  = document.getElementById('admin-sidebar');
    const overlay  = document.getElementById('admin-overlay');
    const burger   = document.getElementById('admin-burger');
    const closeBtn = document.getElementById('admin-sidebar-close');
  
    function openSidebar() {
      sidebar?.classList.add('open');
      overlay?.classList.add('show');
    }
  
    function closeSidebar() {
      sidebar?.classList.remove('open');
      overlay?.classList.remove('show');
    }
  
    burger?.addEventListener('click', openSidebar);
    closeBtn?.addEventListener('click', closeSidebar);
    overlay?.addEventListener('click', closeSidebar);
  });