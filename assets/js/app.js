const $ = require('jquery');
window.$ = $;
window.jQuery = $;

const util = require('util');
window.util = util;

require('bootstrap');
require('datatables');
require('./main.js');
require('./ajax.js');

require('../img/email/logo_bg.png');
require('../img/email/logo_head.png');
require('../img/email/logo_footer.png');