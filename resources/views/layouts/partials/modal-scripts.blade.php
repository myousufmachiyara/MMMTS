{{-- ────────────────────────────────────────────────────────────────
     Shared Magnific Popup modal helpers (scroll lock + focus trap)
     + Select2 init. Extracted from vouchers/index.blade.php so every
     index page that uses .modal-with-form / openMfpModal() gets the
     same fixed behaviour instead of re-pasting this block per view.
     ──────────────────────────────────────────────────────────────── --}}
<style>
body.modal-open-noscroll {
    overflow: hidden !important;
    padding-right: var(--scrollbar-width, 0px);
}
body.modal-open-noscroll section.body,
body.modal-open-noscroll .inner-wrapper,
body.modal-open-noscroll .content-body,
body.modal-open-noscroll main,
body.modal-open-noscroll .page-wrapper {
    overflow: hidden !important;
}
.mfp-wrap { z-index: 10000 !important; }
.mfp-bg   { z-index: 9999  !important; }
</style>

<script>
// ── Scroll lock helpers ──────────────────────────────────────────
function getScrollbarWidth() {
    var d = document.createElement('div');
    d.style.cssText = 'width:100px;height:100px;overflow:scroll;position:absolute;top:-9999px';
    document.body.appendChild(d);
    var w = d.offsetWidth - d.clientWidth;
    document.body.removeChild(d);
    return w;
}
function preventScroll(e) {
    var mc = document.querySelector('.mfp-content');
    if (mc && mc.contains(e.target)) return;
    e.preventDefault();
}
function lockScroll() {
    document.documentElement.style.setProperty('--scrollbar-width', getScrollbarWidth() + 'px');
    document.body.classList.add('modal-open-noscroll');
    document.addEventListener('wheel',     preventScroll, { passive: false });
    document.addEventListener('touchmove', preventScroll, { passive: false });
}
function unlockScroll() {
    document.body.classList.remove('modal-open-noscroll');
    document.documentElement.style.removeProperty('--scrollbar-width');
    document.removeEventListener('wheel',     preventScroll);
    document.removeEventListener('touchmove', preventScroll);
}

// ── Focus trap helpers ───────────────────────────────────────────
var FOCUSABLE = 'a[href],button:not([disabled]),input:not([disabled]):not([type="hidden"]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])';
var _trapHandler = null;

function trapFocus(modalEl) {
    var els = Array.from(modalEl.querySelectorAll(FOCUSABLE))
                   .filter(function(el){ return el.offsetParent !== null; });
    if (!els.length) return;
    var first = els[0], last = els[els.length - 1];
    setTimeout(function(){ first.focus(); }, 60);
    if (_trapHandler) document.removeEventListener('keydown', _trapHandler);
    _trapHandler = function(e) {
        if (e.key !== 'Tab') return;
        if (e.shiftKey) { if (document.activeElement === first) { e.preventDefault(); last.focus(); } }
        else            { if (document.activeElement === last)  { e.preventDefault(); first.focus(); } }
    };
    document.addEventListener('keydown', _trapHandler);
}
function releaseTrap() {
    if (_trapHandler) { document.removeEventListener('keydown', _trapHandler); _trapHandler = null; }
}

// ── Central modal opener — all modals use this ───────────────────
function openMfpModal(src) {
    $.magnificPopup.open({
        items: { src: src },
        type: 'inline',
        callbacks: {
            open: function() {
                lockScroll();
                trapFocus(this.content[0]);
            },
            close: function() {
                releaseTrap();
                unlockScroll();
            }
        }
    });
}

// ── Escape key cleanup ───────────────────────────────────────────
$(document).on('keydown', function(e) {
    if (e.key === 'Escape' && document.body.classList.contains('modal-open-noscroll')) {
        releaseTrap();
        unlockScroll();
    }
});

// ── Add modal — still uses theme class-based open ────────────────
$(document).on('click', '.modal-with-form', function() {
    setTimeout(function() {
        var modal = document.querySelector('.mfp-content .modal-block');
        if (modal) { lockScroll(); trapFocus(modal); }
    }, 80);
});
$(document).on('click', '.modal-dismiss, .mfp-close', function() {
    releaseTrap();
    unlockScroll();
});

// ── Select2 init (harmless no-op if no .select2-js elements on this page) ──
$(document).ready(function() {
    $('.select2-js').select2({ width: '100%' });
});
</script>
