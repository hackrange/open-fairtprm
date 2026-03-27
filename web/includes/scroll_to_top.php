<!-- Scroll to top button -->
<div id="scrollTopBtn" title="Scroll to top" style="display:none; position:fixed; bottom:30px; right:30px; z-index:99999; width:42px; height:42px; border-radius:50%; border:none; background:var(--theme-header-color); opacity:0.3; box-shadow:0 4px 12px rgba(0,0,0,0.2); cursor:pointer; align-items:center; justify-content:center; transition:opacity 0.2s;" onmouseover="this.style.opacity='0.7'" onmouseout="this.style.opacity='0.3'">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 19V5M5 12l7-7 7 7"/></svg>
</div>
<script nonce="<?php echo cspNonce(); ?>">
(function(){
    var btn = document.getElementById('scrollTopBtn');
    if (!btn) return;
    var mc = document.querySelector('.main-content');
    var scroller = (mc && mc.scrollHeight > mc.clientHeight) ? mc : null;

    function getScrollTop() {
        if (scroller) return scroller.scrollTop;
        return window.pageYOffset || document.documentElement.scrollTop || 0;
    }
    function scrollToTop() {
        if (scroller) scroller.scrollTo({top:0, behavior:'smooth'});
        else window.scrollTo({top:0, behavior:'smooth'});
    }
    function check() {
        btn.style.display = getScrollTop() > 300 ? 'flex' : 'none';
    }

    btn.addEventListener('click', scrollToTop);
    if (scroller) scroller.addEventListener('scroll', check);
    window.addEventListener('scroll', check);
    setInterval(check, 500);
})();
</script>
