(function() {
  'use strict';

  var blocks = window.wc && window.wc.wcBlocksRegistry;
  var settingsApi = window.wc && window.wc.wcSettings;
  var element = window.wp && window.wp.element;
  var htmlEntities = window.wp && window.wp.htmlEntities;

  if (!blocks || !settingsApi || !element) {
    return;
  }

  var settings = settingsApi.getPaymentMethodData('webirr', {});
  var decode = htmlEntities && htmlEntities.decodeEntities ? htmlEntities.decodeEntities : function(value) {
    return value;
  };
  var title = decode(settings.title || 'WeBirr');
  var description = decode(settings.description || 'Pay with WeBirr using your banking or wallet app.');

  var Content = function() {
    return element.createElement('p', null, description);
  };

  var Label = function(props) {
    var PaymentMethodLabel = props.components.PaymentMethodLabel;
    return element.createElement(PaymentMethodLabel, { text: title });
  };

  blocks.registerPaymentMethod({
    name: 'webirr',
    label: element.createElement(Label, null),
    content: element.createElement(Content, null),
    edit: element.createElement(Content, null),
    canMakePayment: function() {
      return true;
    },
    ariaLabel: title,
    supports: {
      features: settings.supports || []
    }
  });
})();

