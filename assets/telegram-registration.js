const webApp = window.Telegram?.WebApp;
const registration = document.querySelector('#telegram-registration');
if (webApp && document.querySelector('#telegram-registration-complete')) {
    webApp.ready();
    webApp.close();
} else if (registration && webApp?.initData) {
    const initData = registration.querySelector('[name="init_data"]');
    if (initData) initData.value = webApp.initData;
    registration.hidden = false;
    document.querySelector('#telegram-registration-help').hidden = true;
    webApp.ready();
    webApp.expand();
}
