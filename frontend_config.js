const CONFIG = {
  SUPABASE_URL: 'https://ninsrrallzwmkqyosqeu.supabase.co',
  SUPABASE_KEY: 'sb_publishable_Xqvr6eqcgIxQD8ZYGHSetw_YeIly-oO',
  BASE_URL: window.location.origin
};

const IS_NETLIFY = window.location.hostname.includes('netlify.app') || window.location.hostname.includes('netlify');

function API(fn, action) {
  if (IS_NETLIFY) {
    let url = CONFIG.BASE_URL + '/.netlify/functions/' + fn;
    if (action) url += '?action=' + action;
    return url;
  } else {
    const phpMap = { auth: 'auth.php', upload: 'upload_handler.php', payment: 'payment_handler.php', coins: 'coins_api.php', data: 'data_api.php', admin: 'admin_api.php', printer: 'printer_api.php' };
    const php = phpMap[fn] || fn + '.php';
    let url = '../' + php;
    if (action) url += '?action=' + action;
    return url;
  }
}
