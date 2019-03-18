const $ = require('jquery');
window.$ = $;
window.jQuery = $;

const util = require('util');
window.util = util;

require('bootstrap');
require('datatables');
require('./main.js');
require('./images.js');
require('./ajax.js');