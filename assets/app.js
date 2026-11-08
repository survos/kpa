import './stimulus_bootstrap.js';

 // stimulus

import './styles/app.css';

// import 'bootstrap';
// import 'bootstrap/dist/css/bootstrap.min.css'
// import 'bootstrap-icons/font/bootstrap-icons.min.css'

// import 'bootstrap/dist/css/bootstrap.min.css';
import '@tabler/core/dist/css/tabler.min.css';
// import 'bootstrap';
import '@tabler/core';
// import "@andypf/json-viewer"


// --- logging setup (loglevel + nice prefixes)
import log from 'loglevel';
import prefix from 'loglevel-plugin-prefix';

prefix.reg(log);
prefix.apply(log, {
    timestampFormatter: (date) => date.toISOString(),
    format(level, name, timestamp) {
        // [time] [level] [name]
        return `[${timestamp}] ${level.toUpperCase()} ${name ? '[' + name + ']' : ''}:`;
    },
});

// Default level: change here while testing
// Levels: 'trace' | 'debug' | 'info' | 'warn' | 'error' | 'silent'
// const DEFAULT_LEVEL = (window.__LOG_LEVEL__ ?? 'debug');
const DEFAULT_LEVEL = (window.__LOG_LEVEL__ ?? 'trace');


// Apply default to root and known child loggers
log.setLevel(DEFAULT_LEVEL);

// Handy helper to configure a named logger with a specific level, e.g. "insta"
export function getLogger(name, level = null) {
    const logger = log.getLogger(name);
    if (level) {
        logger.setLevel(level);
    }
    return logger;
}

// Expose in window for quick fiddling in DevTools:
// window.getLogger('insta').setLevel('trace')
window.getLogger = getLogger;
window.log = log;

console.info('[app] loglevel ready; default=', DEFAULT_LEVEL);
