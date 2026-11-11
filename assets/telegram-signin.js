const form = document.querySelector('#telegram-signin');
const miniAppForm = document.querySelector('#telegram-mini-signin');
const webApp = window.Telegram?.WebApp;
const code = location.hash.slice(1);
const launch = new URLSearchParams(code).get('launch');
if (miniAppForm && webApp?.initData) {
    miniAppForm.elements.init_data.value = webApp.initData;
    webApp.ready();
    webApp.expand();
    miniAppForm.requestSubmit();
} else if (miniAppForm && launch && /^[a-f0-9]{64}$/.test(launch)) {
    history.replaceState(null, '', location.pathname + location.search);
    miniAppForm.elements.code.value = launch;
    miniAppForm.requestSubmit();
} else if (document.querySelector('#telegram-keyboard-help')) {
    document.querySelector('#telegram-keyboard-help').hidden = false;
} else if (form && /^[a-f0-9]{64}$/.test(code)) {
    history.replaceState(null, '', location.pathname + location.search);
    form.elements.code.value = code;
    form.hidden = false;
}
