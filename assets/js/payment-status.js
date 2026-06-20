(function() {
  'use strict';

  var config = window.webirrWooCommerce || {};
  var inFlight = false;
  var pollTimer = null;
  var panel = null;

  function ready(callback) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', callback);
    } else {
      callback();
    }
  }

  function start() {
    panel = document.querySelector('[data-webirr-payment-panel]');
    if (!panel || !config.statusUrl || !config.orderKey) {
      return;
    }

    var refresh = panel.querySelector('[data-webirr-refresh]');
    if (refresh) {
      refresh.addEventListener('click', function() {
        refresh.hidden = true;
        checkStatus();
      });
    }

    schedule();
  }

  function schedule() {
    clearTimeout(pollTimer);
    pollTimer = window.setTimeout(checkStatus, Number(config.pollIntervalMs || 5000));
  }

  function statusUrl() {
    var url = new URL(config.statusUrl, window.location.href);
    url.searchParams.set('key', config.orderKey);
    return url.toString();
  }

  function checkStatus() {
    if (inFlight || !panel) {
      return;
    }

    inFlight = true;
    setRefresh(false);
    setStatus('info', 'Checking payment status...', true);

    window.fetch(statusUrl(), {
      headers: {
        'Accept': 'application/json',
        'X-WP-Nonce': config.nonce || ''
      },
      credentials: 'same-origin'
    })
      .then(function(response) {
        return response.json().then(function(body) {
          if (!response.ok || body.error) {
            throw new Error(body.error || 'Unable to check payment status.');
          }
          return body;
        });
      })
      .then(function(body) {
        if (body.complete) {
          showPaid(body);
          return;
        }

        setStatus('warning', 'Payment not received yet.', true);
        schedule();
      })
      .catch(function(error) {
        setStatus('danger', error.message || 'Unable to check payment status.', false);
        setRefresh(true);
      })
      .finally(function() {
        inFlight = false;
      });
  }

  function setStatus(type, message, spinning) {
    var status = panel.querySelector('[data-webirr-status]');
    var text = panel.querySelector('[data-webirr-status-text]');
    var spinner = panel.querySelector('.webirr-wc-spinner');
    if (!status || !text) {
      return;
    }

    status.className = 'webirr-wc-status webirr-wc-status-' + type;
    text.textContent = message;
    if (spinner) {
      spinner.hidden = !spinning;
    }
  }

  function setRefresh(visible) {
    var refresh = panel.querySelector('[data-webirr-refresh]');
    if (refresh) {
      refresh.hidden = !visible;
      refresh.disabled = !visible || inFlight;
    }
  }

  function showPaid(body) {
    clearTimeout(pollTimer);
    setRefresh(false);
    setStatus('success', 'Your payment was successful.', false);

    var confirmation = panel.querySelector('[data-webirr-confirmation]');
    if (confirmation) {
      confirmation.hidden = false;
    }

    var reference = panel.querySelector('[data-webirr-payment-reference]');
    if (reference) {
      reference.textContent = body.paymentReference || '';
    }

    var paidVia = panel.querySelector('[data-webirr-paid-via]');
    if (paidVia) {
      paidVia.textContent = body.paidVia || '';
    }

    if (config.successUrl) {
      window.setTimeout(function() {
        window.location.href = config.successUrl;
      }, 1800);
    }
  }

  ready(start);
})();

