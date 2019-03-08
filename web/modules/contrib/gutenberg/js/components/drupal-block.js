/**
* DO NOT EDIT THIS FILE.
* See the following change record for more information,
* https://www.drupal.org/node/2815083
* @preserve
**/'use strict';

var _createClass = function () { function defineProperties(target, props) { for (var i = 0; i < props.length; i++) { var descriptor = props[i]; descriptor.enumerable = descriptor.enumerable || false; descriptor.configurable = true; if ("value" in descriptor) descriptor.writable = true; Object.defineProperty(target, descriptor.key, descriptor); } } return function (Constructor, protoProps, staticProps) { if (protoProps) defineProperties(Constructor.prototype, protoProps); if (staticProps) defineProperties(Constructor, staticProps); return Constructor; }; }();

function _classCallCheck(instance, Constructor) { if (!(instance instanceof Constructor)) { throw new TypeError("Cannot call a class as a function"); } }

function _possibleConstructorReturn(self, call) { if (!self) { throw new ReferenceError("this hasn't been initialised - super() hasn't been called"); } return call && (typeof call === "object" || typeof call === "function") ? call : self; }

function _inherits(subClass, superClass) { if (typeof superClass !== "function" && superClass !== null) { throw new TypeError("Super expression must either be null or a function, not " + typeof superClass); } subClass.prototype = Object.create(superClass && superClass.prototype, { constructor: { value: subClass, enumerable: false, writable: true, configurable: true } }); if (superClass) Object.setPrototypeOf ? Object.setPrototypeOf(subClass, superClass) : subClass.__proto__ = superClass; }

(function (wp, Drupal) {
  var data = wp.data;
  var withSelect = data.withSelect;

  var DrupalBlock = function (_wp$element$Component) {
    _inherits(DrupalBlock, _wp$element$Component);

    function DrupalBlock() {
      _classCallCheck(this, DrupalBlock);

      return _possibleConstructorReturn(this, (DrupalBlock.__proto__ || Object.getPrototypeOf(DrupalBlock)).apply(this, arguments));
    }

    _createClass(DrupalBlock, [{
      key: 'render',
      value: function render() {
        if (this.props.blockContent) {
          return React.createElement(
            'div',
            null,
            React.createElement('div', { className: this.props.className, dangerouslySetInnerHTML: { __html: this.props.blockContent.html } })
          );
        }

        return React.createElement(
          'div',
          { className: 'loading-drupal-block' },
          Drupal.t('Loading'),
          '...'
        );
      }
    }]);

    return DrupalBlock;
  }(wp.element.Component);

  var createClass = withSelect(function (select, props) {
    var _select = select('drupal'),
        getBlock = _select.getBlock;

    var id = props.id;

    var block = getBlock(id);
    var node = document.createElement('div');

    if (block && block.html) {
      node.innerHTML = block.html;
      var formElements = node.querySelectorAll('input, select, button, textarea');
      formElements.forEach(function (element) {
        element.setAttribute('readonly', true);
        element.setAttribute('required', false);
      });
    }

    return {
      blockContent: { html: node.innerHTML } };
  })(DrupalBlock);

  window.DrupalGutenberg = window.DrupalGutenberg || {};
  window.DrupalGutenberg.Components = window.DrupalGutenberg.Components || {};
  window.DrupalGutenberg.Components.DrupalBlock = createClass;
})(wp, Drupal);