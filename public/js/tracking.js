// Minimal tracking helper (Google Analytics 4)
// Sends click events and observes section visibility.
(function(){
  if(!window.gtag){
    window.gtag = function(){console.warn('gtag not ready', arguments)};
  }

  // mapping from data-track to event name
  const EVENT_MAP = {
    'hero-cta': 'click_cta_hero',
    'hero-cta-secondary': 'click_cta_hero_secondary',
    'nav-cta': 'click_cta_nav',
    'system-game': 'click_cta_system_game',
    'system-prize': 'click_cta_system_prize',
    'system-coopbot': 'click_cta_system_coopbot',
    'system-gov': 'click_cta_system_gov',
    'mobile-cta': 'click_cta_mobile'
  };
  // additional mappings
  EVENT_MAP['main-cta'] = 'click_cta_main';
  EVENT_MAP['submit-contact-form'] = 'click_cta_contact_submit';

  function sendEvent(name, params){
    try{ gtag('event', name, params || {}); }
    catch(e){ console.warn('gtag error', e); }
    console.log('TRACK EVENT', name, params||{});
  }

  // Click listener for elements with data-track
  document.addEventListener('click', function(e){
    const el = e.target.closest && e.target.closest('[data-track]');
    if(!el) return;
    const key = el.getAttribute('data-track');
    const eventName = EVENT_MAP[key] || ('click_' + key);
    const href = el.getAttribute('href') || null;
    const label = (el.textContent && el.textContent.trim().slice(0,120)) || href || '';
    const params = { label: label };
    if(href) params.href = href;

    // try to extract a system param from data-system attribute or href query
    const ds = el.dataset && el.dataset.system;
    if(ds) params.system = ds;
    else if(href){
      try{
        const u = new URL(href, location.origin);
        if(u.searchParams.has('system')) params.system = u.searchParams.get('system');
      }catch(e){}
    } else {
      // search ancestor for data-system
      const anc = el.closest && el.closest('[data-system]');
      if(anc && anc.dataset && anc.dataset.system) params.system = anc.dataset.system;
    }

    sendEvent(eventName, params);

    // fire backend tracking (non-blocking)
    try{
      fetch('/api/track', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ event_name: eventName, event_label: params.label || '', page: location.pathname })
      }).catch(()=>{});
    }catch(e){}
  }, {passive:true});

  // Form submissions
  const ctaForm = document.getElementById('ctaForm');
  if(ctaForm){
    ctaForm.addEventListener('submit', function(e){
      const email = ctaForm.querySelector('input[type="email"]')?.value || '';
      sendEvent('submit_email_cta', {email: email});
      // no preventDefault: allow normal submit
    }, {passive:true});
  }

  // send form submit events to backend as well
  if(ctaForm){
    ctaForm.addEventListener('submit', function(){
      try{ fetch('/api/track', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ event_name:'submit_email_cta', event_label: ctaForm.querySelector('input[type="email"]')?.value || '', page: location.pathname }) }).catch(()=>{}); }catch(e){}
    });
  }

  const contactForm = document.getElementById('contactForm');
  if(contactForm){
    contactForm.addEventListener('submit', function(e){
      sendEvent('submit_contact_form', {});
    }, {passive:true});
  }

  if(contactForm){
    contactForm.addEventListener('submit', function(){
      try{ fetch('/api/track', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ event_name:'submit_contact_form', event_label:'contact_form', page: location.pathname }) }).catch(()=>{}); }catch(e){}
    });
  }

  // IntersectionObserver for section views
  const sectionsToTrack = [
    {id:'hero', event:'view_section_hero'},
    {id:'systems', event:'view_section_systems'},
    {id:'why-us', event:'view_section_benefits'},
    {id:'free-trial', event:'view_section_main_cta'},
    {id:'contact', event:'view_section_contact'}
  ];

  const io = new IntersectionObserver((entries)=>{
    entries.forEach(entry=>{
      if(entry.isIntersecting && entry.intersectionRatio > 0.4){
        const s = sectionsToTrack.find(x=> x.id === entry.target.id);
        if(s){ sendEvent(s.event, {visibility: 'enter'}); }
        io.unobserve(entry.target);
      }
    });
  }, {threshold:[0.25,0.4,0.75]});

  sectionsToTrack.forEach(s=>{
    const el = document.getElementById(s.id);
    if(el) io.observe(el);
  });

  // send page_view on load (visitor count)
  window.addEventListener('load', function(){
    try{ sendEvent('page_view', {path: location.pathname}); }catch(e){}
    try{ fetch('/api/track', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ event_name:'page_view', event_label: document.title || '', page: location.pathname }) }).catch(()=>{}); }catch(e){}
  });

  // Mobile sticky CTA: ensure it's tracked if present
  const mobile = document.getElementById('mobileCta') || document.querySelector('.mobile-cta');
  if(mobile){
    mobile.setAttribute('data-track','mobile-cta');
    const closeBtn = document.getElementById('mobileCtaClose') || mobile.querySelector('.mobile-cta__close');
    const MOBILE_KEY = 'mobileCtaHidden';

    function computeHeight(){
      // ensure layout measured
      const h = mobile.offsetHeight || 0;
      document.documentElement.style.setProperty('--mobile-cta-height', h + 'px');
      if(!mobile.classList.contains('mobile-cta--visible')) return;
      document.documentElement.classList.add('has-mobile-cta');
    }

    function showCta(){
      if(localStorage.getItem(MOBILE_KEY)) return;
      mobile.classList.add('mobile-cta--visible');
      document.documentElement.classList.add('has-mobile-cta');
      computeHeight();
      sendEvent('impression_mobile_cta', {});
    }

    function hideCta(){
      mobile.classList.remove('mobile-cta--visible');
      document.documentElement.classList.remove('has-mobile-cta');
      localStorage.setItem(MOBILE_KEY,'1');
      sendEvent('dismiss_mobile_cta', {});
      document.documentElement.style.setProperty('--mobile-cta-height','0px');
    }

    // show by default on small screens unless dismissed
    if(window.matchMedia && window.matchMedia('(max-width:991.98px)').matches){
      if(!localStorage.getItem(MOBILE_KEY)) showCta();
    }

    // attach close handler
    if(closeBtn){ closeBtn.addEventListener('click', function(e){ e.preventDefault(); hideCta(); }, {passive:true}); }

    // recompute height on resize / mutation
    const updatePadding = () => computeHeight();
    window.addEventListener('resize', updatePadding);
    const mo = new MutationObserver(updatePadding);
    mo.observe(mobile, {attributes:true, childList:true, subtree:true});
    // initial compute after small delay to ensure fonts/images loaded
    setTimeout(computeHeight, 300);
  }

})();
