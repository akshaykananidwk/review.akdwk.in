    </main>
  </div>
</div>
<script>
(function(){
  var toggle = document.getElementById('menuToggle');
  var sidebar = document.getElementById('adminSidebar');
  var backdrop = document.getElementById('sidebarBackdrop');
  if (!toggle || !sidebar || !backdrop) return;
  function openSidebar(){ sidebar.classList.add('open'); backdrop.classList.add('show'); }
  function closeSidebar(){ sidebar.classList.remove('open'); backdrop.classList.remove('show'); }
  toggle.addEventListener('click', function(e){
    e.stopPropagation();
    if (sidebar.classList.contains('open')) { closeSidebar(); } else { openSidebar(); }
  });
  backdrop.addEventListener('click', closeSidebar);
  window.addEventListener('resize', function(){
    if (window.innerWidth > 900) closeSidebar();
  });
})();
</script>
</body>
</html>
