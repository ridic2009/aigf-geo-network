/**
 * Optional analytics bridge.
 *
 * Deliberately empty in v1: no third-party tags are shipped with the network.
 * When an analytics vendor is chosen, implement `window.aigfTrack(event, data)`
 * here and add the script tag in engine/layouts/_default/base.html.twig.
 * main.js calls this function for affiliate clicks and degrades silently when
 * it is not defined, so nothing else has to change.
 */
(function () {
    'use strict';
    // window.aigfTrack = function (event, data) { /* vendor call */ };
})();
